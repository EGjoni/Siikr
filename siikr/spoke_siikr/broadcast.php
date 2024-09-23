<?php
/**various function to broadcast the state of this node to its hub*/
$self_file = explode("/", $_SERVER["PHP_SELF"]); array_pop($self_file);
$self_dir = implode("/", $self_file);
require_once "$self_dir/../../internal/globals.php";


/**requests that the hub call this node back to check in about the provided blog_uuid*/
function call_me_back($blog_uuid, $my_url = null) {
    global $hub_url;
    $me = $my_url == null ? $_SERVER["HTTP_HOST"] : $my_url;
    $params = http_build_query(["caller" => $me, "blog_uuid" => $blog_uuid]);
    $options = ['http' => ['ignore_errors' => true]];
    $context = stream_context_create($options);
    $result = file_get_contents($hub_url."voicemail.php?".$params, false, $context);
}