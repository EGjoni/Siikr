<?php
require_once 'globals.php';
require_once $predir.'./meta_siikr/meta_internal/node_management.php';
/**functions for adopting blog contents from other siikr nodes so as to avoid spamming the tumblr api*/

function assign_localized_ids(&$foreign_subpost, $id_map) {

}

function localize_media_ids($blog_uuid, &$foreign_siikrpost) {
    global $stmt_upsert_media;
    $media_list = $foreign_siikrpost->media;
    $id_map = [];
    foreach ($media_list as &$med) {
        $media_item = (object)$med;
        $media_meta = (object)$media_item->media_meta;
        $media_meta->type = $media_item->type;
        $media_id = get_media_id($media_meta);//kinda hacky but expected to be in the context because this script only gets imported from archive.php from now. 
        $id_map[$media_item->media_id] = $media_id; 
        $db_post_obj->media_by_id[$media_id] = $media_item->media_url;
        $db_post_obj->media_by_url[$media_meta->media_url][] = $media_id;
    }

    if(property_exists($foreign_siikrpost, "blocks")) {
        $self = $foreign_siikrpost->blocks?->self ?? null;
        $trail = $foreign_siikrpost->blocks?->trail ?? null;
        assign_localized_ids($trail, $id_map);
        if($trails != null) {
            foreach($trail as &$trailpost) {
                assign_localized_ids($trailpost, $id_map);
            }
        }
    }
}

function localize_tags($blog_uuid, $foreign_siikrpost) {

}

function ingest_posts($blog_uuid, $post_list) {
    foreach($post_list as &$post) {
        try {
            $db->beginTransaction();
                $post->blocks = json_decode($post->blocks);
                $post->media = json_decode($post->media, true);
                $post->tags = json_decode($post->tags, true);
                if(count($post->media) > 0) {
                    localize_media_ids($blog_uuid, $post);
                }
            $db->commit();
        } catch(Exception $e) {
            
        }
    }

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

