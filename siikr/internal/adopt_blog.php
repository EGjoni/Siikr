<?php
require_once 'globals.php';
require_once $predir.'./meta_siikr/meta_internal/node_management.php';
/**functions for adopting blog contents from other siikr nodes so as to avoid spamming the tumblr api*/


function localize_media_ids($blog_uuid, $foreign_siikrposts) {

}

function localize_tag_ids($blog_uuid, $foreign_siikrposts) {

}

function ingest_posts($blog_uuid, $post_list) {

}

function gather_foreign_posts($blog_uuid, $archiver_version, $this_server_url, $before_timestamp=null, $after_timestamp=null) {
    global $hub_url;
    try{
    $options = ['http' => ['ignore_errors' => true]];
    $context = stream_context_create($options);
    $params = ["blog_uuid" =>$blog_uuid, 
        "requested_by" => $this_server_url,
        'version' => $archiver_version];
    $results = [];
    do {
        if($after_timestamp != null) {
            $params["after"] = $after_timestamp;
            $fullUrl = $hub_url."gather_posts.php?".http_build_query($params);
            $response = file_get_contents($fullUrl, false, $context);
            $results = json_decode($response);            
            ingest_posts($blog_uuid, $results);
            $after_timestamp = $results[0]->timestamp;
            $oldest_returned = $results[count($results)-1];
            if($before_timestamp == null || isset($oldest_returned)) {
                $before_timestamp = min($before_timestamp, $oldest_returned->timestamp);
            }
        }
    } while(count($results) >= 200);

    unset($params["after"]);
    
    do {
        if($before_timestamp != null) $params["before"] = (int)$before_timestamp;
        $fullUrl = $hub_url."gather_posts.php?".http_build_query($params);
        $response = file_get_contents($fullUrl, false, $context);
        $results = json_decode($response);
        ingest_posts($blog_uuid, $results);
        $oldest_returned = $results[count($results)-1];
        $before_timestamp = $oldest_returned->timestamp;
    } while(count($results) >=200);
    

    $posts = $result;
} catch(Exception $e){}
}

