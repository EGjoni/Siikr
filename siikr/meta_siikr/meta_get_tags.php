<?php
require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
require_once './meta_internal/node_management.php';
ob_start('ob_gzhandler');
header('Content-Type: application/json');


try {
    if($blog_uuid == false) {
        throw new Exception("blog_uuid is required");
    } 
    $usenode = findBestKnownNode($blog_uuid);
    if($usenode == null) {
        throw new Exception("No cached node is present. This could hypothetically be worked around but is too spooky not to mention.");
    }
    if($usenode != null) {
        cacheBestNode($blog_uuid, $usenode);
        forwardRequest($_GET, 'get_tags.php', $usenode, false);
    }        
} catch (Exception $e) {
    $blog_info = (object)[];
    $blog_info->valid = false;
    $blog_info->display_error = $e->getMessage();
    echo json_encode($blog_info);
}

cleanStaleCacheEntries();