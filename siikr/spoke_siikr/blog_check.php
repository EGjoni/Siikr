<?php
/**accepts a blog_uuid, responds with 
 * 
 * {
 * have_blog: boolean, //whether or not this spoke indexes this blog, 
 * blogstat_info: { //null or undefined if we don't have the blog, otherwise, instantiated with the following
 *  time_last_index: unix epoch, //the last time this spoke indexed the blog, or null
 *  is_indexing: boolean, //true if the blog is currently being indexed, false if indexing has terminated
 *  success: boolean, //true if the indexing completed succesfully last time we ran
 *  indexed_post_count: int, // number of posts this node has indexed  
 *  serverside_posts_reported: int, //number of posts this spoke thinks tumblr reports the blog to have
 *  index_request_count: int // how many times this spoke has been requested to index this blog.
 * }
 * time_right_now: unix epoch, // the current time, in this server's opinion
 * estimated_remaining_post_capacity: int, //estimate of the number of posts this spoke has diskspace for
 * free_space_mb: float, //raw allocated capacity in mb
 * estimated_calls_remaining: int //estimated number of api calls this spoke has left before getting rate limited again
 * estimated_reavailability: int //null or -1 if the spoke is currently capable of making calls, otherwise, the estimated number of seconds until this spoke is available to make calls again
 * need_help: boolean, // true if some error occurred. false/unset/null otherwise.
 * malformed: boolean // true if some error occurred and it doesn't seem to be on our end.
 * spoke_language: string //a two character iso-639 language code indicating the language this node is dedicated to. The hub will avoid routing blogs to nodes of the wrong language
 * }
*/

require_once './../internal/globals.php';
require_once 'node_state.php';
$blog_uuid = $_GET["blog_uuid"];

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$result = build_nodestate_obj($db);
$blogstat_obj = build_blogstat_obj($db, $blog_uuid);

$result["blogstat_info"] = $blogstat_obj["blogstat_info"];
$result["have_blog"] = isset($blogstat_obj["have_blog"]) && $blogstat_obj["have_blog"];
$result["need_help"] = $blogstat_obj["need_help"];


header('Content-Type: application/json');
echo json_encode($result);
ob_flush();      
flush();