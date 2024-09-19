<?php
require_once './../internal/globals.php';

/**
 * Use to avoid hitting the tumblr api when indexing a new blog,
 * Requests that this siikr hub query all of the nodes it is aware of to return acquire all psots meeting the
 * the specified constraints for the requested blog. This endpoint will consolidate the results before returning them 
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  `requested_by` (required, node url),  
 *  a  'version` parameter (optional, single character 0-9,a-Z), 
 *  a  `before` parameter (timestamp, optional), and
 *  an `after` parameter (timestamp, optional)
 */

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$args = ["blog_uuid" => $blog_uuid, "index_version" => $_GET["version"]];
$before_requested = $_GET["before"];
$after_requested = $_GET["after"];
$index_version_requested = isset($_GET["version"]) ? $_GET["version"] : $archiver_version;
$blog_uuid = $_GET["blog_uuid"];
$requested_by = $_GET["requested_by"];

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
foreach($spokes_list as $spoke) {
    $req_url = $spoke->node_url."/spoke_siikr/get_post_ids.php?".$http_query;
    $request_urls[] = $req_url;
    $spokes_by_url[$req_url] = $spoke;
}

$all_results = [];
$spoke_posts = []; //keyed by node_id, value is a list of posts they contain
$post_spokes = []; //keyed by post_ids, value is a list of node_ids of the spokes on which the posts with those ids reside
$timestamp_posts = []; //timestamp sorted list of [post_ids => timestamp]

$update_post_spokes = function($new_results) use (&$spoke_posts, &$post_spokes, &$timestamp_posts) {
    foreach($new_results as $result) {
        $spoke = $spokes_by_url[$result["url"]];
        $post_array = json_decode($request["response"]);        
        foreach($post_array as $post) {
            $p_id = $post["post_id"];
            $p_epoch = $post["post_date"];
            $spoke_posts[$spoke->node_id] = $p_id;
            $post_spokes[$p_id][] = $spoke->node_id;
            $timestamp_posts[$p_id] = $p_epoch;
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

reset($timestamp_posts);
$newest_post_epoch = current($timestamp_posts);
end($timestamp_posts);
$oldest_post_epoch = current($timestamp_posts);

