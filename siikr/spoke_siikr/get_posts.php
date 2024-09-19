<?php

/**
 * Requests that this siikr spoke return any posts it has indexed meeting the
 * the specified constraints for the requested blog.
 * The posts will be returned as a JSON array. 
 * Each element in the json array will correspond to a post, and each post will 
 * contain a `media` attribute and a `tags` attribute. 
 * The tags attribute will contain a (potentially empty) array of strings, each representing a tag on the post.
 * The media attribute will contain a json object, where each keye corresponds to a node internal media id for that media item 
 * and each value is the actual media item object. It is the responsibililty of the receiving nde to make sure new media_ids 
 * are mapped to these entries wherever they may be referenced in a block, such that the media_ids ocrrespond to the node's internal foreign key relations.
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  `requested_by` (required, node url),  
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
    $before_cond .= "AND "; 
    if (isset($_GET["after"])) $before_cond .= "(";
    $before_cond .= " post_date <= to_timestamp(:before_timestamp) ";
    $args["before_timestamp"] = $_GET("before");
}
if(isset($_GET["after"])) { 
    $after_cond = isset($_GET["before"]) ? " OR " : " AND ";
    $after_cond .= "post_date >= to_timestamp(:after_timestamp) ";
    $args["after_timestamp"] = $_GET("after");
    $after_cond .= isset($_GET["before"]) ? ")" : "";
}

$post_ids = $db->prepare("SELECT post_id FROM post WHERE blog_uuid = :blog_uuid AND index_version = :index_version $before_cond $after_cond")->exec($args)->fetchAll(PDO::FETCH_COLUMN);

if($post_ids == false) {
    $post_ids = [];
}


header('Content-Type: application/json');
echo json_encode($post_ids);
ob_flush();      
flush();