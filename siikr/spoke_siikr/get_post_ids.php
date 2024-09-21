<?php
/**
 * Requests that this siikr spoke return any post_ids it has indexed meeting the
 * the specified constraints for the requested blog.
 * The response will be JSON array of JSON objects {post_id: //stirng, post_date: //epoch}. 
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  'version` parameter (required, single character 0-9,a-Z), 
 *  a  `limit` (optional, an integer indicating the maximum number of posts to retrieve from each spoke (default 200, best not to set this too high as nodes may timeout while trying to retrieve the posts))
 *  either a  `before` parameter (optional, timestamp), or an `after` parameter (timestamp, optional) 
 */

require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$args = [
    "blog_uuid" => $blog_uuid, 
    "index_version" => $_GET["version"], 
    "result_limit" => isset($_GET["limit"]) ? $_GET["limit"] : 200];
$time_stmt = "post_date < now()";
if(isset($_GET["after"])) { 
    $time_stmt = " post_date > to_timestamp(:timestamp_anchor) ";
    $args["timestamp_anchor"] = $_GET["after"];
} else if(isset($_GET["before"])) { 
    $time_stmt = " post_date < to_timestamp(:timestamp_anchor) ";
    $args["timestamp_anchor"] = $_GET["before"];
}

$get_ranges = $db->prepare(
    "SELECT post_id, extract(epoch from post_date)::INT as timestamp FROM posts 
    WHERE blog_uuid = :blog_uuid 
    AND index_version = :index_version 
    AND $time_stmt
    ORDER by post_date desc
    LIMIT :result_limit");

$post_ids = $get_ranges->exec($args)->fetchAll(PDO::FETCH_OBJ);

if($post_ids == false) {
    $post_ids = [];
}


header('Content-Type: application/json');
$encoded = json_encode($post_ids);
echo $encoded;
ob_flush();      
flush();