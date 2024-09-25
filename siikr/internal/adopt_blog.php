<?php
require_once 'globals.php';
require_once $predir.'./meta_siikr/meta_internal/node_management.php';
require_once 'post_reprocessor.php';
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
        $media_meta->type = $media_item->mtype;
        $db_media_id = get_media_id($media_meta);//kinda hacky but expected to be in the context because this script only gets imported from archive.php from now. 
        $id_map[$media_item->media_id] = $media_item;
        $id_map[$media_item->media_id]->media_id = $db_media_id;
    }
    return $id_map;
}

function localize_tags($blog_uuid, $foreign_siikrpost) {

}

function tsvec_to_array($vec_string) {
    $lexeme_wpos_arr = explode(' ', $vec_string);
    $lexemes = [];
    foreach($lexeme_wpos_arr as &$lexeme_wpos) {
        $lexeme_wpos_l = explode(':', $lexeme_wpos);
        $lexeme_wpos_l[0] = substr($lexeme_wpos_l[0], 1, strlen($lexeme_wpos_l[0])-2);
        $pos_ws_arr = explode(',', $lexeme_wpos_l[1]);
        $pos_ws = [];
        foreach($pos_ws_arr as $pos_wo) {
            $pos_ws = ["pos" => (int)$pos_wo, "w" => substr($pos_wo, -1, 1)];
        }
        $lexemes[$lexeme_wpos_l[0]][] = $pos_ws;
    }
    return $lexemes;
}

$qaq = $db->prepare("SELECT ts_content, ts_meta from posts where post_id = :post_id");
$reserved = ["api" => 1, "settings" => 1, "new" => 1];
$failed_posts = []; 


function quality_assurance($post) {
    global $db, $qaq, $reserved, $failed_posts;
    $resolved = $qaq->exec(["post_id"=>$post->post_id])->fetch(PDO::FETCH_OBJ);
    $asr_c = tsvec_to_array($resolved->ts_content);
    $asr_m = tsvec_to_array($resolved->ts_meta);
    $aso_c = tsvec_to_array($post->ts_content);
    $aso_m = tsvec_to_array($post->ts_meta);
    $err = false;
    $fail_string = "";
    if($resolved->ts_content != $post->ts_content) {        
        if (strlen($resolved->ts_content) < strlen($post->ts_content)) {            
            if(count($asr_c) != count($aso_c)) {
                //echo "\n------ts_content mismatch-----\n";
                //echo "new:  $resolved->ts_content\n------";
                //echo "\nold:  $post->ts_content\n";
                foreach($aso_c as $o_c_k => $p) {
                    if(!isset($asr_c[$o_c_k])) {
                        $fail_string .= "missing $o_c_k\n";
                        if(!isset($reserved[$o_c_k])) {
                            $err = true;
                        }
                    }
                }
                if($err) {
                    $failed_posts[$post->post_id]["content"] = [
                        "original_ts" => $aso_c,
                        "new_ts" => $asr_c,
                        "fail_string"=> $fail_string];
                    error_log("$post->post_id mismatched");
                    throw new Exception("ts_mismatch");
                }
            }
        }
    }
    $fail_string;
    if($resolved->ts_meta != $post->ts_meta) {
        
        if (strlen($resolved->ts_meta) < strlen($post->ts_meta)) {            
            if(count($asr_m) != count($aso_m)) {
                //echo "\n------ts_meta mismatch-----\n";
                //echo "new:  $resolved->ts_meta\n--------";
                //echo "\nold:  $post->ts_meta\n";
                foreach($aso_m as $o_m_k => $p) {
                    
                    if($asr_m[$o_m_k] == null) {
                        $fail_string .= "missing $o_m_k\n";
                        if(!isset($reserved[$o_c_k])) {
                            $err = true;
                        }
                    }
                }
                if($err) {
                    $failed_posts[$post->post_id]["meta"] = [
                        "original_ts" => $aso_m,
                        "new_ts" => $asr_m,
                        "fail_string"=> $fail_string 
                    ];
                    error_log("$post->post_id mismatched");
                    throw new Exception("ts_mismatch");
                }
            }
        }
    }
}

function ingest_posts($blog_uuid, $post_list) {
    global $db, $db_blog_info, $archiving_status, $server_blog_info;
    global $archiver_version, $archiver_uuid, $abandonLease;
    global $most_recent_post_id;
    global $stmt_nomention_insert_post;
    global $stmt_mention_insert_post, $stmt_update_blog;
    global $stmt_update_blogstat_count;
    global $update_mention_versioned_post;
    global $update_nomention_versioned_post;
    global $delete_existing_mp_links;
    global $rows_updated;
    global $stmt_insert_posts_media;
    global $post_id_last_attempted, $post_last_attempted_info, $search_notify_rate, $point_last_searched; 


    $repair_mode = true;
    foreach($post_list as &$post) {
        try {
            if(!amLeader($db, $blog_uuid, $archiver_uuid)) {
                $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
                exit;
            }
            $stmt_update_blogstat_count->exec([
                "post_id_last_attempted" => $post->post_id, 
                "indexed_count" => $db_blog_info->indexed_post_count,
                "serverside_posts_reported" => $db_blog_info->serverside_posts_reported,
                "is_indexing" => 'TRUE', 
                "success" => 'FALSE',
                "blog_uuid" => $blog_uuid
            ]);
            $db->beginTransaction();
                $post->blocks = json_decode($post->blocks);
                $post->tags = json_decode($post->tags, true);
                $post->media = json_decode($post->media, true);
                $media_map = [];
                if(count($post->media) > 0) {
                    $media_map = localize_media_ids($blog_uuid, $post);
                }
                $for_db = extract_db_content_from_siikr_post($post, $media_map);
                $tags = $post->tags;
                $tag_rawtext = implode('\n#', $tags);
                if(mb_strlen($tag_rawtext) > 0) $tag_rawtext = "#$tag_rawtext";
                //$tag_tstext = implode('\n', $tags);
                $common_inserts = [
                    "post_id" =>$post->post_id, 
                    "blog_uuid"=>$blog_uuid, 
                    "post_date"=>$post->timestamp,
                    "blocksb" => json_encode($post->blocks),
                    "self_media_text" => $for_db->self_media_text,
                    "trail_media_text" => $for_db->trail_media_text,
                    "trail_usernames" => $for_db->trail_usernames,
                    "has_text" => $post->has_text,
                    "has_link" => $post->has_link,
                    "has_chat" => $post->has_chat,
                    "has_ask" => $post->has_ask,
                    "has_images" => $post->has_images,
                    "has_video" => $post->has_video,
                    "has_audio" => $post->has_audio,
                    "tag_text" => $tag_rawtext,
                    "index_version" => $archiver_version,
                    "is_reblog" => $post->is_reblog == true ? 'TRUE' : 'FALSE',
                    "hit_rate" => $post->hit_rate,
                    "deleted" => $post->deleted == true ? 'TRUE' : 'FALSE'];
                try {
                        $db->query("SAVEPOINT insert_post");
                    if(count($for_db->self_mentions_list) + count($for_db->trail_mentions_list) > 0) {
                        $common_inserts["self_no_mentions"] = $for_db->self_text_no_mentions;
                        $common_inserts["self_with_mentions"] = $for_db->self_text_augmented_mentions;
                        $common_inserts["trail_no_mentions"] = $for_db->trail_text_no_mentions;
                        $common_inserts["trail_with_mentions"] = $for_db->trail_text_augmented_mentions;
                        $stmt_mention_insert_post->exec($common_inserts);
                    } else {
                        $common_inserts["self_text_regular"] = $for_db->self_text_regular;
                        $common_inserts["trail_text_regular"] = $for_db->trail_text_regular;
                        $stmt_nomention_insert_post->exec($common_inserts);
                    }
                    //quality_assurance($post); 
                } catch(Exception $e) {
                    if($e->getCode() == "23505") {
                        $db->query("ROLLBACK TO SAVEPOINT insert_post");
                        $e2 = null;
                        $useStatement = $update_nomention_versioned_post;
                        if(count($for_db->self_mentions_list) + count($for_db->trail_mentions_list) > 0)
                            $useStatement = $update_mention_versioned_post;
                        try {
                            $useStatement->exec($common_inserts);
                        } catch(Exception $ei) {
                            $e2 = $ei;}
                        $rows_updated = $useStatement->rowCount();
                        if($rows_updated > 0) {
                            $archiving_status->indexed_this_time -=1;
                            $db_blog_info->indexed_post_count -= 1;
                            $archiving_status->upgraded += 1;
                        $delete_existing_mp_links->exec(["post_id"=>$post->post_id]); //whipe old media_links for upgrade
                            $isUpgrade = true;
                            $rows_updated++;
                            addToReanalysisQueue($blog_uuid);
                        }
                        
                        if($isUpgrade == false && $repair_mode == false) 
                            throw $e;

                    } else {
                        throw $e;
                    }
                }

            foreach ($media_map as &$med) {
                $stmt_insert_posts_media->exec([$post->post_id, $med->media_id]);
            }

            notify_tags($tags, $post->post_id);
            $db_blog_info->indexed_post_count += 1;
            if($most_recent_post_id == false) $most_recent_post_id = $post->post_id;
            $stmt_update_blog->exec([
                "most_recent_post_id" => $most_recent_post_id, 
                "post_id_successfully_indexed" => $post->post_id, //sets both post_id_last_attempted and post_id_last_indexed
                "indexed_count" => $db_blog_info->indexed_post_count,
                "serverside_posts_reported" => $db_blog_info->serverside_posts_reported,
                "is_indexing" => 'TRUE', 
                "success" => 'FALSE', 
                "blog_uuid" => $blog_uuid]);
            $db->commit();
            $post_id_last_indexed = $post_id_last_attempted;
            $post_last_indexed_info = $post_last_attempted_info;
            $archiving_status->indexed_post_count = $db_blog_info->indexed_post_count;
            $archiving_status->indexed_this_time += 1;
            notify_search_results("p.blog_uuid = :blog_uuid and p.post_date < :last_range_date", 
                    ["blog_uuid" => $blog_uuid, "last_range_date" => $point_last_searched],
                    $archiving_status->indexed_this_time > 0 && $archiving_status->indexed_this_time % $search_notify_rate != 0);
               
                $point_last_searched = $post->post_date;
            
            if($archiving_status->indexed_this_time % $search_notify_rate == 0) {
                $point_last_searched = $post->post_date;
            }
            if($archiving_status->indexed_this_time % 400 == 0) notifyHub();
        }  catch(Exception $e) { 
            throw $e;
        }
    }
    return $post;
}

function gather_foreign_posts($blog_uuid, $archiver_version, $this_server_url, $before_timestamp=null, $after_timestamp=null) {
    global $hub_url, $server_blog_info;
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
    

    return array_pop($results);
} catch(Exception $e){}
}

