<?php
require_once './../internal/globals.php';
$username = $_GET["username"];
header('Content-Type: application/json');
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
require_once './meta_internal/node_management.php';
$checkinQueue = [];
$forward_params = $_GET;


function initSearch($username, $forward_params) {

    try {        
        $response = call_tumblr($username, "info", [], true);
        try {
            $error_string = handleError($response);
            $blog_info = $response->response->blog;
            $blog_info->blog_uuid = $blog_info->uuid;
            $blog_info->blog_name = $blog_info->name;
        } catch (Exception $e) {
            throw $e;
        } 
        $blog_info->valid = true;
        $usenode = findBestKnownNode($blog_info->blog_uuid, $blog_info);
        $ping_suggested = true;
        if($usenode == null) {
            $usenode = askAllNodes($blog_info->blog_uuid, $blog_info);
            $ping_suggested = false;
        }
        if($usenode != false) {
            if($usenode != false && $ping_suggested == false) {
                registerToBlogNodeMap($blog_info->blog_uuid, $usenode);
            }
            cacheBestNode($blog_info->blog_uuid, $usenode);
            forwardRequest($forward_params, 'streamed_search.php', $usenode, $blog_info);
            if($ping_suggested) {
                askAllNodes($blog_info->blog_uuid, $blog_info);
            }
            //askAllNodes($blog_info->blog_uuid, $blog_info); //implicitly updates nodestats, doing it since we're here anyway
            cleanStaleCacheEntries();
        }
    } catch ( Exception $e) {
        $blog_info->valid = false;
        $blog_info->display_error = $e->getMessage();
        echo json_encode($blog_info);
        flush();
        throw $e;
    }
}




function handleError($response) {
    if($response->meta->status != 200) { 
        $error_string = "";
        foreach($response->errors as $err) {
            if($err->code == 0 && $response->meta->status == 404) {
                throw new Exception("lol, that's not even a real name");
            }
            $error_string .= "$err->detail \n";
        }           
        throw new Exception("Tumblr says: \"$error_string\"");
    }
}


initSearch($username, $forward_params);