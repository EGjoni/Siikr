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
 *  largest_indexed_post_id: string //the largest post_id indexed from this blog
 *  smallest_indexed_post_id: string //the smallest post_id indexed from this blog
 * }
 * time_right_now: unix epoch, // the current time, in this server's opinion
 * total_capacity_mb: int, //total space used and unused available on this node,
 * node_name: varchar(64), //a pretty name to givr your node for display and mnemonic purposes,
 * node_flare: text, //anything ridiculous thing you might want to subject a user to while their blog indexes. The hub might choose to show it to them,
 * node_notice: text, //it's like AOL instant messenger, but for database administrators, and only if they happen to check this column for some reason
 * estimated_remaining_post_capacity: int, //estimate of the number of posts this spoke has diskspace for
 * free_space_mb: float, //raw remaining storage capacity in mb
 * estimated_calls_remaining: int //estimated number of api calls this spoke has left before getting rate limited again
 * estimated_reavailability: int //null or -1 if the spoke is currently capable of making calls, otherwise, the estimated number of seconds until this spoke is available to make calls again
 * need_help: boolean, // true if some error occurred. false/unset/null otherwise.
 * down_for_maintenance: boolean, //indicates to the hub that this node should be ignored for searches
 * malformed: boolean // true if some error occurred and it doesn't seem to be on our end.
 * spoke_language: string //a two character iso-639 language code indicating the language this node is dedicated to. The hub will avoid routing blogs to nodes of the wrong language
 * }
*/

require_once './../internal/globals.php';
require_once 'node_state.php';
$blog_uuid = $_GET["blog_uuid"];

$db = getDb();//new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$result = build_nodestate_obj($db);
$blogstat_obj = build_blogstat_obj($db, $blog_uuid);

$result["blogstat_info"] = $blogstat_obj["blogstat_info"];
$result["have_blog"] = isset($blogstat_obj["have_blog"]) && $blogstat_obj["have_blog"];
$result["need_help"] = $blogstat_obj["need_help"];


header('Content-Type: application/json');
echo json_encode($result);
ob_flush();      
flush();