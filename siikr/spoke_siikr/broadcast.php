<?php
/**various function to broadcast the state of this node to its hub*/
require_once __DIR__."/../internal/globals.php";


/**requests that the hub call this node back to check in about the provided blog_uuid*/
function call_me_back($blog_uuid, $my_url = null) {
    global $hub_url;
    $me = $my_url == null ? $_SERVER["SERVER_NAME"] : $my_url;
    $params = http_build_query(["caller" => $me, "blog_uuid" => $blog_uuid]);
    $options = ['http' => ['ignore_errors' => true]];
    $context = stream_context_create($options);
    $result = file_get_contents($hub_url."voicemail.php?".$params, false, $context);
}