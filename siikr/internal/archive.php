<?php 
require_once 'globals.php';
require_once 'disk_stats.php';

$archiver_uuid = uuid_create(UUID_TYPE_RANDOM);
$userInfo = posix_getpwuid(posix_geteuid());
$db = new SPDO("pgsql:dbname=$db_name", $userInfo["name"], null);

require_once 'lease.php';

//next two lines are so that we can show the user any search results while we happen to come across them in the indexing process
$search_query = $_GET['search_query']; 
$search_options = $_GET['search_options'];
$server_blog_info = [];
$blog_uuid = null;

if($argc > 1) $search_id = $argv[1];

$current_blog = $db->prepare("SELECT blog_uuid FROM active_queries WHERE search_id = ? LIMIT 1");
$blog_uuid = $current_blog->exec([$search_id])->fetchColumn();
$establishLease->exec([$blog_uuid, $archiver_uuid]); //defined in lease.php
$get_active_queries = $db->prepare("SELECT * FROM active_queries WHERE blog_uuid = :blog_uuid"); 
$searches_array = $get_active_queries->exec(["blog_uuid" => $blog_uuid])->fetchAll(PDO::FETCH_OBJ);

$stmt_select_blog = $db->prepare("SELECT * FROM blogstats WHERE blog_uuid = ?");
$db_blog_info = $stmt_select_blog->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
$db_blog_info->index_request_count += 1;
$db->prepare("UPDATE blogstats SET index_request_count = ? WHERE blog_uuid = ?")->exec([$db_blog_info->index_request_count, $blog_uuid]);
$resolve_queries = $db->prepare("DELETE FROM active_queries WHERE blog_uuid = :blog_uuid"); 


$zmqsock_identifier = $archiver_uuid; #each archiver_instance gets their own zmq socket for update broadcasts
require_once $predir.'/internal/messageQ.php';

$blog_name = $db_blog_info->blog_name;
if($blog_name) {
    $server_blog_info = &call_tumblr($db_blog_info->blog_name, "info")->blog;
} else {
    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    die("Error: no name given"."\n");
}

$archiving_status = (object)[
    "blog_uuid" => $blog_uuid, 
    "blog_name" => $blog_name, 
    "as_search_result" => [],
    "serverside_posts_reported" => &$server_blog_info->total_posts, //this ampersand means the value will remain updated when I reference $archiving_status again, right?
    "indexed_post_count" => &$db_blog_info->indexed_post_count,
    "indexed_this_time" => 0];

if(!ensureSpace($db, $db_blog_info, $server_blog_info)) {
    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    $archiving_status->notice = "Siikr is nearly out of disk space and this blog would make it die. For the good of many, it must sacrifice the few. (i.e you).";
    sendEventToAll($searches_array, 'NOTICE!', $archiving_status);
    die("Error: insufficient disk space");
}



sendEvent("INDEXING!$search_id", $archiving_status); //broadcast a zmq message for anyone interested to know about the blog indexing status.


function extract_blocks_from_content($content, &$soFar, $mode=0b01) {
    $text_content = [];
    $blockct = 0;
    $possible_encodings =['UTF-8', 'ASCII', 'JIS', 'EUC-JP', 'SJIS', 'ISO-8859-1'];
    foreach ($content as $block) {
        try {
            if (property_exists($block, 'text')) {
                if(property_exists($block, "subtype")) {
                    if ($block->subtype == 'chat')
                        $soFar["has_chat"] |= $mode;
                }
                $text_content[] = mb_convert_encoding($block->text, 'UTF-8', $possible_encodings);
                $soFar["has_text"] |= $mode;
            }
            if(property_exists($block, "type")) {
                if ($block->type == 'image') {
                    $soFar["has_images"] |= $mode;
                    $img_info = ["url" => $block->media[0]->url];
                    if(property_exists($block, "caption")) {
                        $img_info["caption"] = mb_convert_encoding($block->caption, 'UTF-8', $possible_encodings);
                        $text_content[] = mb_convert_encoding($block->caption, 'UTF-8', $possible_encodings);
                    }
                    if(property_exists($block, "alt_text")) {
                        $img_info["alt_text"] = mb_convert_encoding($block->alt_text, 'UTF-8', $possible_encodings);
                        $text_content[] =  mb_convert_encoding($block->alt_text, 'UTF-8', $possible_encodings);
                    }
                    $soFar["images"][] = $img_info;
                }
                if ($block->type =='audio')
                    $soFar["has_audio"] |= $mode;
                if ($block->type == 'video')
                    $soFar["has_video"] |= $mode;
                if ($block->type =='link')
                    $soFar["has_link"] |= $mode;
            }
        } catch (Exception $e) {
            echo "error decoding text $e";
        }
        $blockct++;
    }
    return implode("\n", $text_content);
}


function extract_text_from_post($post) {
    $soFar = 
    ["self_text" => "", 
    "trail_text" => "",
    "title" => "",
    "has_text" => 0b00, 
    "has_link" => 0b00, 
    "has_chat" => 0b00,
    "has_ask" => 0b00, 
    "has_images" => 0b00,
    "has_audio" => 0b00,
    "has_video" => 0b00,
    "images" => []]; 
    $self_text = extract_blocks_from_content($post->content, $soFar, 0b01);
    foreach($post->layout as $layout) {
        if(property_exists($layout->type, "ask"))
            $soFar["has_ask"] |= 0b01;
    }
    $trail_text = '';
    if (property_exists($post, 'trail')) {
        foreach ($post->trail as $trail_item) {
            $trail_text .= "\n" . extract_blocks_from_content($trail_item->content, $soFar, 0b10)."[skrtgrblgnd]";
            foreach($trail_item->layout as $layout) {
                $soFar["has_ask"] |= 0b10;
            }
        }
    }
    $soFar["trail_text"] = $trail_text;
    $soFar["self_text"] = $self_text; 
    return (object)$soFar;
}

try {
    $before_id = null;
    $stmt_update_blog = $db->prepare(
        "UPDATE blogstats SET most_recent_post_id = :most_recent_post_id, 
                        post_id_last_indexed = :post_id_successfully_indexed, 
                        post_id_last_attempted = :post_id_successfully_indexed, 
                        indexed_post_count = :indexed_count,
                        serverside_posts_reported = :serverside_posts_reported,
                        time_last_indexed = now(),
                        success = :success, 
                        is_indexing = :is_indexing WHERE blog_uuid = :blog_uuid");
    $stmt_update_blogstat_count = $db->prepare(
        "UPDATE blogstats SET most_recent_post_id = :most_recent_post_id,
                        post_id_last_attempted = :post_id_last_attempted, 
                        indexed_post_count = :indexed_count,
                        serverside_posts_reported = :serverside_posts_reported,
                        success = :success, 
                        is_indexing = :is_indexing WHERE blog_uuid = :blog_uuid");

    //SELECT merge_tsvector_json(:texts::jsonb) AS tsvector_result
    $stmt_insert_post = $db->prepare(
        "INSERT INTO posts (post_id, blog_uuid, post_date, post_url, 
                            slug, blocks, simple_ts_vector, english_ts_vector,
                            tag_text, has_text, has_link, has_chat, has_ask, has_images, has_video, has_audio) 
                    VALUES (:post_id, :blog_uuid, to_timestamp(:post_date), :post_url,
                        :slug, :blocks, setWeight(to_tsvector('simple', :vec_tags), 'A') || setWeight(to_tsvector('simple', :self_text), 'B') || setWeight(to_tsvector('simple', :trail_text), 'C') || setWeight(to_tsvector('simple', :image_text), 'D'),
                        setWeight(to_tsvector('en_us_hunspell', :vec_tags), 'A') || setWeight(to_tsvector('en_us_hunspell', :self_text), 'B') || setWeight(to_tsvector('en_us_hunspell', :trail_text), 'C') || setWeight(to_tsvector('en_us_hunspell', :image_text), 'D'), :raw_tags, :has_text, :has_link, :has_chat, :has_ask, :has_images, :has_video, :has_audio)
                    --VALUES (:post_id, :blog_uuid, to_timestamp(:post_date), :post_url,          
                    -- following two lines are for after reworking the indexing schema and will replace the above two lines
                    --  :slug, :blocks, setWeight(to_tsvector('simple', :vec_tags), 'A') || setWeight(to_tsvector('simple', :self_text), 'B') || setWeight(to_tsvector('simple', :trail_text), 'C') || setWeight(to_tsvector('simple', :username_text), 'D'),
                    --   setWeight(to_tsvector('en_us_hunspell', :vec_tags), 'A') || setWeight(to_tsvector('en_us_hunspell', :self_text), 'B') || setWeight(to_tsvector('en_us_hunspell', :trail_text), 'C') || setWeight(to_tsvector('en_us_hunspell', :username_text), 'D'),
                            ");
    $stmt_insert_tag = $db->prepare("INSERT INTO tags (tag_name, tag_simple_ts_vector) VALUES (:tag_text, to_tsvector('simple', :tag_text)) ON CONFLICT (tag_name) DO UPDATE SET tag_name = Excluded.tag_name RETURNING tag_id");
    $stmt_insert_posts_tags = $db->prepare("INSERT INTO posts_tags (blog_uuid, post_id, tag_id) VALUES(?, ?, ?) ON CONFLICT DO NOTHING");
    $stmt_insert_image = $db->prepare("INSERT INTO images (img_url, caption_vec, alt_text_vec) VALUES (:img_url, setWeight(to_tsvector('simple', :caption), 'A') || setWeight(to_tsvector('en_us_hunspell', :caption), 'B'), setWeight(to_tsvector('simple', :alt_text), 'A') || setWeight(to_tsvector('en_us_hunspell', :alt_text), 'B')) ON CONFLICT (img_url) DO UPDATE SET img_url = Excluded.img_url RETURNING image_id");
    $stmt_insert_posts_images = $db->prepare("INSERT INTO images_posts (post_id, image_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
    $get_oldest_indexed_post = $db->prepare("SELECT post_id FROM posts where blog_uuid = :blog_uuid ORDER BY post_date ASC LIMIT 1");
    
    $stmt_get_post_info = $db->prepare("SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts where post_id = :post_id");
    //$post_check_stmt = $db->prepare(getTextSearchString($search_query, "p.post_id = :post_id"));
    $set_blog_success_status = $db->prepare("UPDATE blogstats SET success = ?, is_indexing = FALSE WHERE blog_uuid = ?");
    
    // Get the most_recent_post_id and post_id_last_indexed from the blogstats table
    $most_recent_post_id = $db_blog_info->most_recent_post_id;
    if($db_blog_info->time_last_indexed != null) {
        $post_id_last_indexed = $db_blog_info->post_id_last_indexed;
        $post_id_last_attempted = $db_blog_info->post_id_last_attempted;
        //next line is for debug. delete when done.
        //$smallest_post_id_indexed_info = $db->prepare("SELECT post_id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts where blog_uuid = :blog_uuid ORDER BY post_id ASC LIMIT 1")->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
        $oldest_post_id_indexed =  $get_oldest_indexed_post->exec(["blog_uuid"=>$blog_uuid])->fetchColumn();

        $most_recent_post_info = $stmt_get_post_info->exec([$most_recent_post_id])->fetch(PDO::FETCH_OBJ);
        if($post_id_last_indexed == null) $post_id_last_indexed = $oldest_post_id_indexed->post_id;
        $post_last_indexed_info = $stmt_get_post_info->exec([$post_id_last_indexed])->fetch(PDO::FETCH_OBJ);
        $post_last_attempted_info = $stmt_get_post_info->exec([$post_id_last_attempted])->fetch(PDO::FETCH_OBJ);
        $oldest_post_indexed_info = $stmt_get_post_info->exec([$oldest_post_id_indexed])->fetch(PDO::FETCH_OBJ);
    }
    
    $oldest_server_post = call_tumblr($blog_name, 'posts', ['limit' => 1, 'npf' => 'true', 'sort' => 'asc'])->posts[0];
    
    $loop_count = 0;


    /**LOGIC: 
     * 
     * Create a queue of potential gaps (posts before which we might be missing some post), with null indicating we wish to begin from the very most recent post.
     * 
     * If tumblr ever responds that there are no earlier posts, or we encounter a post we already indexed, pop the queue to begin indexing from the next gap 
    */
    $gap_queue = [null];
    if($oldest_post_id_indexed != null) 
        array_push($gap_queue, $oldest_post_indexed_info);
    if($db_blog_info->success == false && $post_id_last_indexed != $oldest_post_id_indexed) 
        array_push($gap_queue, $post_last_indexed_info);
    $max_loop_count = count($gap_queue) * 2;
    $response_post_num = 0;
    do {
        $before_info = array_pop($gap_queue);
        $before_time = $before_info == null? null:$before_info->timestamp;
        $before_id = $before_info->id;
        do {
            $disk_use = get_disk_stats();        
            $params = ['limit' => 50, 'npf' => 'true', 'before' => $before_time, 'sort' => 'desc'];
            $server_blog_info = call_tumblr($blog_name, 'posts', $params);
            
            foreach ($server_blog_info->posts as $post) {
                $post->id = $post->id_string;
                if($most_recent_post_id == null || $post->timestamp > $most_recent_post_info->timestamp) {
                    $most_recent_post_id = (int)$post->id;
                    $most_recent_post_info = $post;
                }
                if($oldest_post_id_indexed == null || $post->timestamp < $oldest_post_indexed_info->timestamp) {
                    $oldest_post_id_indexed = (int)$post->id;
                    $oldest_post_indexed_info = $post;
                }
                
                if(!amLeader($db, $blog_uuid, $archiver_uuid)) {
                    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
                    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
                    exit;
                }
                $get_active_queries->execute(["blog_uuid" => $blog_uuid]);
                $searches_array = $get_active_queries->fetchAll(PDO::FETCH_OBJ);
                
                $post_id = $post->id;
                $before_info = $post;
                $before_time = $before_info->timestamp;
                
                $db_post_obj = extract_text_from_post($post);
                $tags = $post->tags;
                
                if($post_id_last_attempted == null) {
                    $post_id_last_attempted = $post_id;
                    $post_last_attempted_info = $post;
                }
                try {
                    $response_post_num++;
                    $stmt_update_blogstat_count->exec([
                        "most_recent_post_id" => $most_recent_post_id,
                        "post_id_last_attempted" => $post_id, 
                        "indexed_count" => $db_blog_info->indexed_post_count,
                        "serverside_posts_reported" => $server_blog_info->total_posts,
                        "is_indexing" => 'TRUE', 
                        "success" => 'FALSE', 
                        "blog_uuid" => $blog_uuid]);
                    $db->beginTransaction();
                    // Insert post into posts table
                    $tag_rawtext = implode('\n#', $tags);
                    if(strlen($tag_rawtext) > 0) $tag_rawtext = "#$tag_rawtext";
                    $tag_tstext = implode('\n', $tags);

                    foreach ($db_post_obj->images as &$img) {
                        $image = (object)$img;
                        $alt_text = $image->alt_text ?? null;
                        $caption = $image->caption ?? null;
                        $will_insert = ["img_url" => $image->url, "caption"=> $caption,  "alt_text" => $alt_text];
                        $image_id = $stmt_insert_image->exec($will_insert)->fetchColumn();
                        $img["db_id"] = $image_id;
                    }
                
                    list($transformed, $for_db) = transformNPF($post, $db_post_obj->images);

                    $stmt_insert_post->exec(
                        ["post_id" =>$post->id, 
                        "post_url" => $post->post_url,
                        "slug" => $post->slug,
                        "blog_uuid"=>$blog_uuid, 
                        "post_date"=>$post->timestamp,
                        "blocks" => json_encode($transformed),
                        "self_text" => $for_db->self, 
                        "trail_text" => $for_db->trail,
                        "image_text" => $for_db->images,
                        "has_text" => $DENUM[$db_post_obj->has_text],
                        "has_link" => $DENUM[$db_post_obj->has_link],
                        "has_chat" => $DENUM[$db_post_obj->has_chat],
                        "has_ask" => $DENUM[$db_post_obj->has_ask],
                        "has_images" => $DENUM[$db_post_obj->has_images],
                        "has_video" => $DENUM[$db_post_obj->has_video],
                        "has_audio" => $DENUM[$db_post_obj->has_audio],
                        "raw_tags" => $tag_rawtext, 
                        "vec_tags" => $tag_tstext]);

                    foreach ($db_post_obj->images as &$img) {
                        $stmt_insert_posts_images->exec([$post->id, $img["db_id"]]);
                    }

                    $__arc_stat = serialize($archiving_status);
                    // Insert tags into tags table and create relations in posts_tags table
                    foreach ($tags as $tag) {
                        if(check_delete($tag, $archiving_status->indexed_this_time, $blog_uuid, $db)) {
                            $archiving_status->disk_used = $disk_use;
                            $archiving_status->content = "Deleting blog by request of post <a href='$post->post_url'>$post->id</a>, please wait...";
                            queueEventToAll($searches_array, "FINISHEDINDEXING!", $archiving_status);                  
                            delete_blog($tag, $archiving_status->indexed_this_time, $blog_uuid, $db);
                            $archiving_status->content = "Blog deleted. Goodbye.";
                            $archiving_status->deleted = true;                       
                            queueEventToAll($searches_array, "FINISHEDINDEXING!", $archiving_status);
                            $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
                            $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
                            $db->commit();
                            exit;
                        }
                        $tagid = $stmt_insert_tag->exec(["tag_text" => $tag])->fetchColumn();
                        $stmt_insert_posts_tags->exec([$blog_uuid, $post->id, $tagid]);
                        $arc_stat = unserialize($__arc_stat);
                        $arc_stat->newTag = (object)[]; 
                        $arc_stat->newTag->tag_id = $tagid;
                        $arc_stat->newTag->tagtext = $tag;
                        $arc_stat->newTag->user_usecount = 1;
                        $arc_stat->disk_used = $disk_use;
                        queueEventToAll($searches_array, "INDEXEDTAG!", $arc_stat);
                    }
                    
                    $db_blog_info->indexed_post_count += 1;
                    $stmt_update_blog->exec([
                        "most_recent_post_id" => $most_recent_post_id, 
                        "post_id_successfully_indexed" => $post_id, //sets both post_id_last_attempted and post_id_last_indexed
                        "indexed_count" => $db_blog_info->indexed_post_count,
                        "serverside_posts_reported" => $server_blog_info->total_posts,
                        "is_indexing" => 'TRUE', 
                        "success" => 'FALSE', 
                        "blog_uuid" => $blog_uuid]);
                    $db->commit();
                    $post_id_last_indexed = $post_id_last_attempted;
                    $post_last_indexed_info = $post_last_attempted_info;
                    $archiving_status->indexed_post_count = $db_blog_info->indexed_post_count;
                    $archiving_status->indexed_this_time += 1;

                    $post_searches_results = execAllSearches($db, $searches_array, "p.post_id = :post_id", ["post_id" => $post->id]);
                    $listener_result_map = [];

                    $archiving_status->disk_used = $disk_use;
                    $__arc_stat = serialize($archiving_status);
                    $arc_stat = unserialize($__arc_stat);
                    foreach($post_searches_results as $post_result_cont) {
                        $post_result = $post_result_cont->results;
                        if($post_result != false) {
                            $post_result->tags = json_decode($post_result->tags);
                            $arc_stat->as_search_result = [$post_result];
                        }
                        queueEvent("INDEXEDPOST!$post_result_cont->search_id", $arc_stat);
                    }
                    
                    fireEventQueue();
                } catch (Exception $e) {
                    $db->rollBack(); 
                    if($e->getCode() == "23505") { //post has already been indexed
                        $jump_triggered = true;  
                        //to avoid binary search if possible, let's just hope the problem magically fixes itself by the time we finish this batch
                        if($response_post_num > 51) {
                            $response_post_num = 0;
                            break 2;
                        } 
                        continue;
                    } 
                    throw $e;  
                }
            }
        } while (!empty($server_blog_info->posts));
        
        if(empty($gap_queue)) {       
            $binary_threshold = max($server_blog_info->total_posts * 0.99, max($server_blog_info->total_posts-100, $server_blog_info->total_posts*0.99));
            if($db_blog_info->indexed_post_count < $binary_threshold) {
                $delta = -($db_blog_info->indexed_post_count - $server_blog_info->total_posts);
                $arc_stat = unserialize(serialize($archiving_status));
                $arc_stat->notice = "$delta sneaky posts failed to index. Attempting to find...";
                sendEventToAll($searches_array, "NOTICE!", $arc_stat);
                //we appear to be missing some posts. See if we can find them.                
                //Siikr uses binary search! It is not very effective...
                //Let's manually count the indexed posts like savages.
                $actual_count = $db->prepare("SELECT COUNT(*) from posts WHERE blog_uuid = ?")->exec([$db_blog_info->blog_uuid])->fetchColumn();
                //regardless of whether it solves the problem let's update the value in the blogstats column since we bothered.
                $db->prepare("UPDATE blogstats SET indexed_post_count = ? WHERE blog_uuid = ?")->exec([$actual_count, $db_blog_info->blog_uuid]);
            
                if($db_blog_info->$indexed_post_count < $binary_threshold) {
                    //Siikr uses binary search!
                    if(!amLeader($db, $blog_uuid, $archiver_uuid)) {
                        $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
                        $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
                        exit;
                    } 
                    $establishLease->exec([$blog_uuid, $archiver_uuid]);
                    $binary_result = binarySearchMissing($db, $db_blog_info);
                    $establishLease->exec([$blog_uuid, $archiver_uuid]);
                    if($binary_result != null) { 
                        //It's very effective!
                        array_push($gap_queue, $binary_result);
                        $response_post_num = 0;
                        $arc_stat->notice = "Found some sneaky posts!";
                        $arc_stat->resolved = true; 
                        sendEventToAll($searches_array, "NOTICE!", $arc_stat);
                    } else {
                        //It's not very effective...
                        $arc_stat->error = "Could not find sneaky posts...this is usually due to interactions with deactivated blogs.";
                        $arc_stat->notice = $arc_stat->error;
                        $arc_stat->resolved = false;
                        $success_status = 'FALSE';
                        sendEventToAll($searches_array, "ERROR!", $arc_stat);
                    }
                }
            }
            $success_status = 'TRUE'; 
        }
        $loop_count++;
    } while (
        $loop_count <= $max_loop_count && !empty($gap_queue)
    );

    // Update blogstats table
    //$stmt = $db->prepare("INSERT INTO blogstats (blog_uuid, most_recent_post_id, time_last_indexed, total_posts) VALUES (?, ?, NOW(), ?) ON CONFLICT (blog_uuid) DO UPDATE SET most_recent_post_id = EXCLUDED.most_recent_post_id, last_indexed = EXCLUDED.last_indexed, total_posts = EXCLUDED.total_posts");
    //$stmt->execute([$blog_uuid, $before_id, $server_blog_info->total_posts]);
    $set_blog_success_status->exec([$success_status, $blog_uuid]);
    $archiving_status->content= "All done! ".$archiving_status->indexed_post_count." posts archived, and ".$archiving_status->indexed_this_time." new posts indexed! (Try hitting the refresh button if you think I missed one of your results)";
    $archiving_status->disk_used= $disk_use;
    sendEventToAll($searches_array, "FINISHEDINDEXING!", (object)$archiving_status); #notify the client
    

} catch (Exception $e) {
    $error_string = "Error: " . $e->getMessage();
    $archiving_status->error = $error_string;
    $archiving_status->notice = "A critical error occurred while indexing your blog. Posts may be missing.";
    $set_blog_success_status->exec(['FALSE', $blog_uuid]);
    sendEventToAll($searches_array, "ERROR!", (object)$archiving_status);
    sendEventToAll($searches_array, "ERROR!", (object)$archiving_status);
    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    die($error_string . "\n");
}
//$db->prepare("ANALYZE")->execute([]);
$resolve_queries->execute(["blog_uuid" => $blog_uuid]);
$abandonLease->execute(["leader_uuid" => $archiver_uuid]);


function sendEventToAll($searches_array, $event_str, $message) {
    foreach($searches_array as $search_inst) {
        queueEvent($event_str.$search_inst->search_id, $message);
    }
    fireEventQueue();
}

function queueEventToAll($searches_array, $event_str, $message) {
    foreach($searches_array as $search_inst) {
        queueEvent($event_str.$search_inst->search_id, $message);
    }
}

//If you're going through hell, keep going.
function transformNPF($post, $db_images) {
    $compact = new stdClass();
    $db_content = new stdClass();
    $compact->self = new stdClass();
    list($compact->self, $db_content->self) = transformItem($post, $db_images);
    $compact->trail = [];
    $db_content->trail = [];
    
    foreach($post->trail as $item) {
        list($compact->trail[], $db_content->trail[]) = transformItem($item, $db_images);
    }
    $db_result = collapseDBContent($db_content);
    return [$compact, $db_result];
}

function collapseDBContent($db_content) {
    $result = (object)["self" => "", "trail" => "", "images" => "", "blogs_involved" => ""];
    $blogs_involved = []; //extracts out blog names on a best effort basis so as to store them in their own dedicated columns 
    foreach($db_content->self->blocks as $block) {
        toCollapsed($block, $result->self, $result->images);
    }
    foreach($db_content->trail as $item) {
        $result->trail .= $item->by != null ? "$item->by\n" : "\n";
        foreach($item->blocks as $block) {
            toCollapsed($block, $result->trail, $result->images);
        }
    }
    return $result;
}

function toCollapsed($db_block, &$into, &$images) {
    if($db_block["type"] == "text")
        $into .= $db_block["val"];
    else if($db_block["type"] == "image")
        $images .= $db_block["val"];
}

//oh god. oh god. oh god.
function transformItem($post, $db_images) {
    global $blog_uuid;
    $subtype_map = [
        "heading1" => "h1",
        "heading2" => "h2", #Intended for section subheadings.
        "quirky" => "quirky", #Tumblr Official clients display this with a large cursive font.
        "quote" => "q", #Intended for short quotations, official Tumblr clients display this with a large serif font.
        "indented" => "ind", #Intended for longer quotations or photo captions, official Tumblr clients indent this text block.
        "chat" => "chat", #Intended to mimic the behavior of the Chat Post type, official Tumblr clients display this with a monospace font.
        "ordered-list-item" => "ol", #Intended to be an ordered list item prefixed by a number, see next section.
        "unordered-list-item" => "ul" #Intended to be an unordered list item prefixed with a bullet, see next section.
    ];
    $respost = new stdClass();
    $respost->content = [];
    $respost->by = $post->blog->name ?? "tumblr user";
    
    $db_content = new stdClass();
    if($post->blog->uuid != $blog_uuid)
        $db_content->by = $post->blog->name ?? "tumblr user";
    $db_content->blocks = [];
    $typeCount = [];
    // Handle the main content
    foreach ($post->content as $block) {
        $item = new stdClass();
        switch ($block->type) {
            case 'text':
                $item->t = 'txt';
                $item->c = $block->text;
                if (isset($block->subtype)) {
                    $item->s = $subtype_map[$block->subtype];
                }
                if (isset($block->formatting)) {
                    //augment_text($item->c);
                }
                break;
            case 'image':
                $image_id = null;
                foreach($db_images as $db_img) {
                    if($db_img["url"] == $block->media[0]->url)
                        $item->db_id = $db_img["db_id"];
                }
                $item->t = 'img';
                //$item->u = $block->media[0]->url;
                $item->w = $block->media[0]->width;
                $item->h = $block->media[0]->height;
                $item->alt = $block->alt_text;
                $item->caption = $block->caption;
                break;
            case 'link':
                $item->t = 'lnk';
                $item->u = $block->url;
                if (isset($block->description)) {
                    $item->d = $block->description;
                }
                if (isset($block->title)) {
                    $item->ttl = $block->title;
                }
                break;
            case 'audio':
                $item->t = 'aud';
                $item->u = $block->media->url;
                if (isset($block->title)) {
                    $item->ttl = $block->description;
                }
                if (isset($block->artist)) {
                    $item->artist = $block->artist;
                }
                if (isset($block->album)) {
                    $item->album = $block->album;
                }
                break;
            case 'video':
                $item->t = 'vid';
                $item->u = $block->media->url;
                $item->w = $block->media->width;
                $item->h = $block->media->height;
                break;
        }
        $respost->content[] = $item;
        $db_content->blocks = array_merge($db_content->blocks, get_db_type_for_block($block));
    }
    $respost->layout = $post->layout;

    return [$respost, $db_content];
}

/**
 * updates the text contents of &$block in place to replace all detected username mentions with an xmltag representation
 * of the username. This will atempt find usernames in the old style where people would copy and paste cnote text,
 * as well as the new style where mentions are directly formatted via mentions this is so that the mention can 
 */
function augment_text(&$block) {


}

/**
 * looks at the formatting entry of any text if it exists, then splits the textblock into
 * an array of seperate strings, such that the 0-inddexed odd numbered elements contain text sequences corresponding to a user mention, and the even ones contain text
 * not corresponding to a user mention. An emty string is inserted at the beginning or end of the sequence to ensure this rule holds in the event that the first or last segment corresponds to a user mention
 * 
 * @param npfBlock should be a raw tumblr npfblock of type text with a formatting entry.
*/
function extractMentionSubtext($npfBlock) {
    $result_array = [];
    $mentionedUsers = [];
    $text = $input['text'];
    
    // Iterate over the formatting to find mentions and replace them with whitespace
    foreach ($input['formatting'] as $format) {
        if ($format['type'] === 'mention') {
            $username = $format['blog']['name'];
            $mentionedUsers[] = $username;

            $start = $format['start'];
            $end = $format['end'];
            if($start == 0) $resultArray[] = '';
            if($end == strlen($input->text))

            // Replace the mentioned username with whitespace
            $length = $end - $start;
            $whitespace = str_repeat(' ', $length);
            $text = substr_replace($text, $whitespace, $start, $length);
        }
    }

    return [
        'mentionedUsers' => $mentionedUsers,
        'processedText' => $text
    ];
}


/**merges the text content of blocks of the given indices into the text content of a single block. */
function asMerged(&$blocks, $indices_arr = null) {
    if($indices_arr == null) return;
    $newBlock = $blocks[$indices_arr[0]];
    $isFirst = true;
    foreach($indices_arr as $block_index) {
        if(!$isFirst)
            $newBlock->text .= $blocks[$block_index]->text;
        $isFirst = false;
        $blocks[$block_index] = null;
    }
    return $newBlock;
}

function get_db_type_for_block($block) {
    if($block->type == "text") {
        $result = ["type"=> "text", "val" => $block->text."\n"]; 
        return [$result];
    }
    $propety_map = [
        "title" => "text",
        "description" => "text",
        "alt_text" => "image",
        "caption" => "image"
    ];
    $results = [];
    foreach($propety_map as $k => $v) {
        if($block->{$k} != null) {
            if($k == "alt_text" && $block->{$k} == "image") 
                continue; //wow that's annoying. sometime images are just alt_texted with "image"
            $results[] = ["type" => $v, "val" => $block->{$k}."\n"];
        }
    }

    return $results;
}


?>
