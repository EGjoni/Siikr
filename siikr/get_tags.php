<?php
require_once 'internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];
try {
    $db = new PDO("pgsql:dbname=$db_name", "www-data", null);
    $blog_info = (object)[
        "valid" => false,
        "blog_uuid" => $blog_uuid,
        "tag_list" => []];
    $blog_info->valid = true;
    $tags_stmt = $db->prepare(getTagInfoString());
    $tags_stmt->execute(["blog_uuid" => $blog_info->blog_uuid]);
    $blog_info->tag_list = $tags_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $blog_info->valid = false;
    $blog_info->display_error = $e->getMessage();
}
$db = null;
ob_start('ob_gzhandler');
header('Content-Type: application/json');
echo json_encode($blog_info);
