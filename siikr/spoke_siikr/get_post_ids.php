<?php
/**
 * Requests that this siikr spoke return any post_ids it has indexed meeting the
 * the specified constraints for the requested blog.
 * The response will be JSON array of post_id stirngs. 
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),  
 *  a  'version` parameter (required, single character 0-9,a-Z), 
 *  a  `before` parameter (timestamp, optional), and
 *  an `after` parameter (timestamp, optional)
 * 
 * NOTE that before and after parameters behave somewhat unituitively. Specifically, if both are specified, and after is less than before, then they indicate an interval between which the any posts of that timestamp will be returned. If both are specified and after greater than befoe, then they specify to distinct intervalsone from the most recent post_date on the spoke down to the after timestamp, and another from the oldest post_date on the spoke, up to the before timestamp
 */

require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$args = ["blog_uuid" => $blog_uuid, "index_version" => $_GET["version"]];
$before_cond = "";
$after_cond = "";
if(isset($_GET["before"])) { 
    $before_cond = "AND post_date <= :before_timestamp ";
    $args["before_timestamp"] = $_GET("before");
}
if(isset($_GET["after"])) { 
    $after_cond = "AND post_date >= :after_timestamp ";
    $args["after_timestamp"] = $_GET("after");
}

$post_ids = $db->prepare("SELECT post_id FROM post WHERE blog_uuid = :blog_uuid AND index_version = :index_version $before_cond $after_cond")->exec($args)->fetchAll(PDO::FETCH_COLUMN);

if($post_ids == false) {
    $post_ids = [];
}


header('Content-Type: application/json');
echo json_encode($post_ids);
ob_flush();      
flush();