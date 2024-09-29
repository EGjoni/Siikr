<?php
require_once './../internal/globals.php';
$username = $_GET["username"];
header('Content-Type: application/json');
header('X-Accel-Buffering: no');
ob_implicit_flush(0);

try { 
    require_once './../internal/SPDO.php';
    $db = getDb();
} catch (Exception $e) {
    $a =1;
}
//$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
require_once './meta_internal/node_management.php';
$checkinQueue = [];
$forward_params = $_GET;

function initSearch($username, $forward_params) {
    global $blog_info, $known_prep;
    $forward_search_params = $forward_params;
    $forward___archive___params = (array)((object)$forward_search_params);
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
        $known_hosts = $known_prep->exec(["blog_uuid"=>$blog_info->blog_uuid])->fetchAll(PDO::FETCH_OBJ);
        $blog_info->hosted_by = $known_hosts;
        $searchNode = findBestSearchNode($blog_info->blog_uuid, $blog_info, $known_hosts);
        $archivingNode =  findBestArchivingNode($blog_info->blog_uuid, $blog_info, $known_hosts);
        $ping_suggested = true;
        $new_archive = false;
        $hosts_queried = [];
        $non_hosts_queried = [];
        $available_nodes = [];
        if($archivingNode == null) {
            $archivingNode = askAllNodes($blog_info->blog_uuid, $blog_info, 
                                        $hosts_queried, $non_hosts_queried, $available_nodes);
            if($searchNode == null)
                $searchNode = $archivingNode;
            $ping_suggested = false;
            $new_archive = true;
        }
        if($searchNode != false) {           
            if($new_archive == true) {
                registerToBlogNodeMap($blog_info->blog_uuid, $archivingNode);
            }
            $blog_info->node_id = $archivingNode->node_id ?? $searchNode->node_id;
            $blog_info->indexed_post_count = $archivingNode->indexed_post_count;
            cacheBestNode($blog_info->blog_uuid, $archivingNode);
            if($searchNode->node_id != $archivingNode->node_id) {
                $forward_search_params["search_only"] = true;
                $forward_search_params["listen_to"] = $archivingNode->node_url;
                $forward___archive___params["archive_only"] = true;
                forwardRequest($forward___archive___params, 'streamed_search.php', $archivingNode, $blog_info);
            }
            forwardRequest($forward_search_params, 'streamed_search.php', $searchNode, $blog_info);            
            if($ping_suggested) {//update blognode map
                $hosts_queried=[]; $non_hosts_queried=[]; $available_nodes=[];
                askAllNodes($blog_info->blog_uuid, $blog_info,
                            $hosts_queried, $non_hosts_queried, $available_nodes);
            }
            //askAllNodes($blog_info->blog_uuid, $blog_info); //implicitly updates nodestats, doing it since we're here anyway
            cleanStaleCacheEntries();
        }
    } catch ( Exception $e) {
        if(!isset($blog_info)) {
            $blog_info = (object)[];
        }
        $blog_info->valid = false;
        $blog_info->display_error = $e->getMessage();
        echo json_encode($blog_info);
        echo "\n#end_of_object#\n";
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