<?php 
    $uuid = $_GET["blog_uuid"];
    $post_url = $_GET["post_url"];
    $post_id = $_GET["post_id"];
    $url = "https://embed.tumblr.com/embed/post/$uuid/$post_id";
    $result = file_get_contents($url);
    $response = (object)[];
    $response->post_id = $post_id; 
    $response->post_url = $post_url;
    $response->blog_uuid = $blog_uuid;
    $response->html = $result;
    header('Content-Type: application/json');
    echo json_encode($response);