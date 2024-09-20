<?php
require_once './../internal/globals.php';

/**
 * Use to avoid hitting the tumblr api when indexing a new blog,
 * Requests that this siikr hub query all of the nodes it is aware of to return or acquire n posts meeting the
 * the specified constraints for the requested blog. This endpoint will consolidate the results before returning them 
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  `requested_by` (required, url of node making the request),  
 *  a  `limit` (optional, an integer indicating the maximum number of posts to retrieve from each spoke (default 200, best not to set this too high as nodes may timeout while trying to retrieve the posts))
 *  a  `version` parameter (optional, single character 0-9,a-Z), and zero or one of
 *  either a  `before` parameter (optional, timestamp), or an `after` parameter (timestamp, optional) 
 * 
 * 
 * The basic idea is to query t
 */

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$args = ["blog_uuid" => $blog_uuid, "index_version" => $_GET["version"]];
$before_requested = $_GET["before"];
$after_requested = $_GET["after"];
$index_version_requested = isset($_GET["version"]) ? $_GET["version"] : $archiver_version;
$blog_uuid = $_GET["blog_uuid"];
$requested_by = 'https://'.normalizeURL($_GET["requested_by"]);

$limit = isset($_GET["limit"]) ? $_GET["limit"] : 200;

if($requested_by == null) throw new Error("Error: requested_by is a required parameter");
if($blog_uuid == null) throw new Error("Error: blog_uuid is a required parameter");

$spokes_list = $db->prepare(
    "SELECT sn.* FROM blog_node_map bnm, siikr_nodes sn 
    WHERE bnm.blog_uuid = :blog_uuid
    AND sn.node_id = bnm.node_id
    AND sn.node_url != :requested_by"
)->exec([
    "blog_uuid" => $blog_uuid,
    "requested_by" => $requested_by
])->fetchAll(PDO::FETCH_OBJ);

$init_params = ["blog_uuid"=>$blog_uuid, "version" => $index_version_requested];
if($before_requested != null) $init_params["before"] = $before_requested;
if($after_requested != null) $init_params["after"] = $after_requested;
$http_query = http_build_query($init_params);
$request_urls = [];
$spokes_by_url = [];
$spokes_by_id = [];
foreach($spokes_list as $spoke) {
    $req_url = $spoke->node_url."/spoke_siikr/get_post_ids.php?".$http_query;
    $request_urls[] = $req_url;
    $spokes_by_url[$req_url] = $spoke;
    $spokes_by_id[$spoke->node_id] = $spoke;
}

$all_results = [];
$spoke_posts = []; //keyed by node_id, value is a list of posts they contain
$post_spokes = []; //keyed by post_ids, value is a list of node_ids of the spokes on which the posts with those ids reside
$timestamp_posts = []; //timestamp sorted list of [post_ids => timestamp]

$update_post_spokes = function($new_results) use (&$spokes_by_url, &$spoke_posts, &$post_spokes, &$timestamp_posts) {
    foreach($new_results as $result) {
        $spoke = $spokes_by_url[$result["url"]];
        $post_array = json_decode($result["response"], true);        
        foreach($post_array as $post) {
            $p_id = $post["post_id"];
            $p_epoch = (int)$post["timestamp"];
            $spoke_posts["$spoke->node_id"]["$p_id"] = $p_epoch;
            $post_spokes["$p_id"][] = $spoke->node_id;
            $timestamp_posts["$p_id"] = $p_epoch;
        }
    }
};
/**
* basic_gist is we just query each spoke in $spoke_posts. On the first pass we do so using the largest before and after timestamps provided by the client (which ideally were specified such that before is less than after)
* We eliminate the posts that spoke returned from the $post_spokes list and $timestamp_posts lists. 
* We then set our $before value to that of the largest remaining timestamp in $timestamp_posts, and our $after value to that of the smallest timestamp remaining in the list, and query the next spoke. Repeating this process until there are no more posts in $post_spokes
**/

require_once 'meta_internal/node_management.php';
multiCall($request_urls, $update_post_spokes, $all_results);
arsort($timestamp_posts);
$truncated_posts = array_slice($timestamp_posts, $limit, count($timestamp_posts), true);

foreach($truncated_posts as $post_id => $epoch) {    
    foreach($post_spokes as $post_id => $on_spokes) {
        $on_spokes = $post_spokes["$post_id"];
        foreach($on_spokes as $hosting_id) {
            unset($spoke_posts["$hosting_id"]["$post_id"]);
        }
    }
    unset($post_spokes["$post_id"]);
}

$timestamp_posts = array_slice($timestamp_posts, 0, $limit, true);

$consolidated_posts = [];

foreach($spoke_posts as $spoke_id => $post_arr) {
    $post_ids = array_keys($post_arr);
    if(count($post_ids) > 0) {
        $http_params = [
            'ignore_errors' => true,
            'method' => 'POST',
            'header' => 'Content-Type:application/json',
            'content' => json_encode($payload)];
        $options = ['http' => $http_params];
        $spoke = $spokes_by_id[$spoke_id];
        $http_query = http_build_query(["blog_uuid"=>$blog_uuid, "version" => $index_version_requested]);
        $req_url = $spoke->node_url."/spoke_siikr/get_posts.php?".$http_query;
        $context = stream_context_create($options);
        $req_results = file_get_contents($req_url, false, $context);
        $post_results = json_decode($req_results);
        foreach($post_results as $post) {
            $on_spokes = $post_spokes["$post->post_id"];
            foreach($on_spokes as $hosting_id) {
                unset($spoke_posts["$hosting_id"]["$post->post_id"]);
            }
            $consolidated_posts[] = $post;
        }
    }
}



reset($timestamp_posts);
$newest_post_epoch = current($timestamp_posts);
end($timestamp_posts);
$oldest_post_epoch = current($timestamp_posts);





header('Content-Type: application/json');
echo json_encode($consolidated_posts);
ob_flush();      
flush();