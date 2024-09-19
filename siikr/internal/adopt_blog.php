<?php
require_once 'globals.php';
require_once $predir.'./meta_siikr/meta_internal/node_management.php';
/**functions for adopting blog contents from other siikr nodes so as to avoid spamming the tumblr api*/


function gather_foreign_posts($blog_uuid, $archiver_version, $this_server_url, $before_timestamp=null, $after_timestamp=null) {
    global $hub_url;
    $options = ['http' => ['ignore_errors' => true]];
    $context = stream_context_create($options);
    $params = ["blog_uuid" =>$blog_uuid, 
        "requested_by" => $this_server_url,
        'version' => $archiver_version];
    if($before_timestamp != null) $params["before"] = $before_timestamp;
    if($after_timestamp != null) $params["after"] = $after_timestamp;
    
    $fullUrl = $hub_url."gather_posts.php?".http_build_query($params);
    $response = file_get_contents($fullUrl, false, $context);
    $result = json_decode($response);
    $posts = $result;
}

