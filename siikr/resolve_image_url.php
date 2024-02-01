<?php
require_once 'internal/globals.php';
$image_id = $_GET["image_id"];
try {
    $db = new PDO("pgsql:dbname=$db_name", "www-data", null);
    $get_image_stmt = $db->prepare("SELECT img_url FROM images where image_id = :image_id");
   
    $get_image_stmt->execute(["image_id" => $image_id]);
    $url = $get_image_stmt->fetchColumn();
} catch (Exception $e) {
    $blog_info->valid = false;
    $blog_info->display_error = $e->getMessage();
}
$db = null;
echo $url;