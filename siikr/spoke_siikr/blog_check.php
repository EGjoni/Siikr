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
 * }
*/

require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$free_space_mb = capped_freespace($db);
$estimated_remaining_post_capacity = estimatePostIngestLimit($db, $freespace_mb);
$result = [
    "have_blog" => false,
    "estimated_remaining_post_capacity" => $estimated_remaining_post_capacity,
    "free_space_mb" => $free_space_mb
];


$last_stat_obj = $db->prepare("SELECT EXTRACT(epoch FROM req_time)::INT as req_time, response_code::INT, est_reavailable FROM self_api_hist ORDER by req_time desc LIMIT 1")->exec([])->fetch(PDO::FETCH_OBJ);
$deletion_rate = 0.994; //approximate, we estimate users are sufficiently ashamed of roughly 0.5% of the things they say to warrant deletion. It would be expensive to determine the exact number on a case by case basis, so this estimate is derived from the average deletion rate on a sample of 200 users. The variance is actually quite high per user but ultimately this number gets divided by 50, so most of the variance gets swept under the 1.5 orders of magnitude.

$pending_posts = $db->prepare(
            "SELECT SUM(GREATEST(1, bl.serverside_posts_reported - (bl.indexed_post_count*$deletion_rate))) as approximate_pending_post_count
            FROM blogstats bl, archiver_leases al WHERE al.blog_uuid = bl.blog_uuid AND time_last_indexed < now() - INTERVAL '24 hours'")->exec([])->fetchColumn();

$summary_stat_obj = $db->prepare("SELECT * FROM self_api_summary")->exec([])->fetch(PDO::FETCH_OBJ);
$hr_limit = $am_unlimited ? 50000 : 1000;
$day_limit = $am_unlimited ? 250000 : 5000;
$est_revailability = null;
$est_calls_remaining = 1000;
$hr_calls_remaining = $hr_limit - $summary_stat_obj->requests_this_hour;
$day_calls_remaining = $day_limit - $summary_stat_obj->requests_today;
$est_calls_remaining = min($hr_calls_remaining, $day_calls_remaining);
$result["time_right_now"] = $last_stat_obj->req_time;

if($last_stat_obj->response_code == 429) {    
    if($last_stat_obj?->est_reavailable != null) {
        $est_revailability = $last_stat_obj->est_reavailable;
    } else if($summary_stat_obj?->est_reavailable != null) {
        $est_revailability = (int)($summary_stat_obj->est_reavailable);
    } else {
        $est_revailability = 24*60*60;
    }
    $est_revailability = $est_revailability - ((int)time()-$last_stat_obj->req_time);
    if($est_revailability > 0) {
        $result["estimated_reavailability"] = $est_revailability;
        $est_calls_remaining = 0;
    }
}


$result["estimated_calls_remaining"] = $est_calls_remaining - ($pending_posts/50);
sleep(10);


if($blog_uuid != null) {
    try {
        $get_blogstats = $db->prepare(
            "SELECT EXTRACT(EPOCH FROM time_last_indexed) AS time_last_indexed,
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
        }
    } catch(Exception $e) {
        $result["need_help"] = true; 
    }
}

header('Content-Type: application/json');
echo json_encode($result);
ob_flush();      
flush();