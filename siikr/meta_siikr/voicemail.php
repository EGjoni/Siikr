<?php
/**check-in request endpoint.
 * nodes request that the hub ask them for information, and the hub does so at its discretion
 */
require_once './../internal/globals.php';
$db = getDb();//new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
require_once 'meta_internal/node_management.php';


$blog_uuid = $_GET["blog_uuid"];
$normalized_node_url = normalizeURL($_GET["caller"]);
$normalized_node_url = explode(":", $normalized_node_url)[0];
$node_url = 'https://'.$normalized_node_url;

$node_obj = $db->prepare("SELECT * FROM siikr_nodes WHERE node_url = :node_url")
                ->exec(["node_url"=>$node_url])->fetch(PDO::FETCH_OBJ);

function checkInWith($endpoint, $params, $nodeObj) {
    $node = unserialize(serialize($nodeObj));
    //echo $params["blog_uuid"]."\n";
    $http_params = http_build_query($params);
    $options = ['http' => ['ignore_errors' => true]];
    $context = stream_context_create($options);
    $result = file_get_contents($endpoint."?$http_params", false, $context);
    $failed = false; 
    if($result == false) {
        $failed = true;
    }
    $json_result = json_decode($result, true);
    if($failed || $json_result == false) {
        $node->reliability_boost -=0.0125;
        return $node;
    }

    foreach($json_result as $k => $v) if($k != "blogstat_info") $node->$k = $v;
    if($json_result["have_blog"] && isset($json_result["blogstat_info"])) {
        foreach($json_result["blogstat_info"] as $k => $v) {
            $node->$k = $v;
        }
    }
    return $node;
}

if($node_obj == false) {
    throw new Error("Node unrecognized");
} else {
    if($blog_uuid != null) {
        $updated_nodeObj = checkInWith("$node_obj->node_url/spoke_siikr/blog_check.php", ["blog_uuid"=>$blog_uuid], $node_obj);
        updateNodeStats($updated_nodeObj);
        if($updated_nodeObj?->have_blog) {
            registerToBlogNodeMap($blog_uuid, $updated_nodeObj);
        } else {
            $db->prepare("DELETE FROM cached_blog_node_map WHERE node_id = :node_id AND blog_uuid = :blog_uuid")->exec(["blog_uuid"=>$blog_uuid, "node_id" => $node_obj->node_id])->fetch();
            $db->prepare("DELETE FROM blog_node_map WHERE node_id = :node_id AND blog_uuid = :blog_uuid")->exec(["blog_uuid"=>$blog_uuid, "node_id" => $node_obj->node_id])->fetch();
        }
    }
}

?>