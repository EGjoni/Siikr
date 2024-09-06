<?php
/**accepts a blog_uuid, responds with 
 * 
 * {
 * have_blog: boolean, //whether or not this spoke indexes this blog, 
 * blogstat_info: { //null or undefined if we don't have the blog, otherwise, instantiated with the following
 *  time_last_index: unix epoch, //the last time this spoke indexed the blog, or null
 *  time_right_now: unix epoch // the current time, in this server's opinion
 *  is_indexing: boolean, //true if the blog is currently being indexed, false if indexing has terminated
 *  success: boolean, //true if the indexing completed succesfully last time we ran
 *  indexed_post_count: int, // number of posts this node has indexed  
 *  serverside_posts_reported: int, //number of posts this spoke thinks tumblr reports the blog to have
 *  index_request_count: int // how many times this spoke has been requested to index this blog.
 * }
 * 
 * estimated_remaining_post_capacity: int, //estimate of the number of posts this spoke has room for
 * free_space_mb: float //raw allocated capacity in mb
 * need_help: boolean // true if some error occurred. false/unset/null otherwise.
 * malformed: boolean // true if some error occurred and it doesn't seem to be on our end.
 * }
*/

require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];

$db = new SPDO("pgsql:dbname=$db_name", $db_user, null);
$free_space_mb = capped_freespace($db);
$estimated_remaining_post_capacity = estimatePostIngestLimit($db, $freespace_mb);
$result = [
    "have_blog" => false,
    "estimated_remaining_post_capacity" => $estimated_remaining_post_capacity,
    "free_space_mb" => $free_space_mb
];
if($blog_uuid != null) {
    try {
        $get_blogstats = $db->prepare(
            "SELECT EXTRACT(EPOCH FROM time_last_indexed) AS time_last_indexed, 
            EXTRACT(EPOCH FROM now()) AS time_right_now,
            is_indexing, success, indexed_post_count, 
            serverside_posts_reported, index_request_count 
            FROM blogstats WHERE blog_uuid = :blog_uuid");
        $blogstats = $get_blogstats->exec(["blog_uuid" => $blog_uuid])->fetch(PDO::FETCH_OBJ);
        if($blogstats != false) {
            $response_info = [ 
                "time_last_indexed" => $blogstats->time_last_indexed,
                "is_indexing" => $blogstats->is_indexing === false ? 'FALSE' : 'TRUE',
                "success" => $blogstats->success  === false ? 'FALSE' : 'TRUE',
                "indexed_post_count" => $blogstats->indexed_post_count,
                "serverside_posts_reported" => $blogstats->serverside_posts_reported,
                "index_request_count" => $blogstats->index_request_count
            ];
            $result["blogstat_info"] = $response_info;
        
            $result["have_blog"] = true;
        } else {
            $result["time_right_now"] = (int)time();
        }
    } catch(Exception $e) {
        $result["need_help"] = true; 
    }
}

header('Content-Type: application/json');
echo json_encode($result);
ob_flush();      
flush();