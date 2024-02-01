<?php 
require_once 'globals.php';

$archiver_uuid = uuid_create(UUID_TYPE_RANDOM);
$userInfo = posix_getpwuid(posix_geteuid());
$db = new PDO("pgsql:dbname=$db_name", $userInfo["name"], null);

require_once 'lease.php';

//next two lines are so that we can show the user any search results while we happen to come across them in the indexing process
$search_query = $_GET['search_query']; 
$search_options = $_GET['search_options'];
$server_blog_info = [];
$blog_uuid = null;

if($argc > 1) $search_id = $argv[1];

$current_blog = $db->prepare("SELECT blog_uuid FROM active_queries WHERE search_id = ? LIMIT 1");
$current_blog->execute([$search_id]);
$blog_uuid = $current_blog->fetchColumn();
if(!renewOrStealLease($db, $blog_uuid, $archiver_uuid)) {
    exit;
}

$stmt_select_blog = $db->prepare("SELECT * FROM blogstats WHERE blog_uuid = ?");
$stmt_select_blog->execute([$blog_uuid]);
$db_blog_info = $stmt_select_blog->fetch(PDO::FETCH_OBJ);

$get_active_queries = $db->prepare("SELECT * FROM active_queries WHERE blog_uuid = :blog_uuid");
$get_active_queries->execute(["blog_uuid" => $blog_uuid]); 
$searches_array = $get_active_queries->fetchAll(PDO::FETCH_OBJ);

$zmqsock_identifier = $archiver_uuid; #each archiver_instance gets their own zmq socket for update broadcasts
require_once $predir.'/internal/messageQ.php';

$blog_name = $db_blog_info->blog_name;
if($blog_name) {
    $server_blog_info = &call_tumblr($db_blog_info->blog_name, "info")->blog;
    $blog_uuid =  $server_blog_info->uuid;
} else {
    die("Error: no name given"."\n");
}

$archiving_status = [
    "blog_uuid" => $blog_uuid, 
    "blog_name" => $blog_name, 
    "as_search_result" => [],
    "serverside_posts_reported" => &$server_blog_info->total_posts, //this ampersand means the value will remain updated when I reference $archiving_status again, right?
    "indexed_post_count" => &$db_blog_info->indexed_post_count,
    "indexed_this_time" => 0];

sendEvent("INDEXING!$search_id", (object)$archiving_status); //broadcast a zmq message for anyone interested to know about the blog indexing status.


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

$resolve_queries = $db->prepare("DELETE FROM active_queries WHERE blog_uuid = :blog_uuid"); 


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
        "UPDATE blogstats SET most_recent_post_id = :runstart_post_id, 
                        post_id_last_indexed = :post_id, 
                        post_id_last_attempted = :post_id, 
                        indexed_post_count = :indexed_count,
                        serverside_posts_reported = :serverside_posts_reported,
                        time_last_indexed = now(),
                        success = :success, is_indexing = :is_indexing WHERE blog_uuid = :blog_uuid");
    $stmt_update_blogstat_count = $db->prepare(
        "UPDATE blogstats SET most_recent_post_id = :runstart_post_id,
                        post_id_last_attempted = :post_id, 
                        indexed_post_count = :indexed_count,
                        serverside_posts_reported = :serverside_posts_reported,
                        success = :success, 
                        is_indexing = :is_indexing WHERE blog_uuid = :blog_uuid");
    $stmt_insert_post = $db->prepare(
        "INSERT INTO posts (post_id, blog_uuid, post_date, post_url, slug, blocks, 
                            simple_ts_vector, english_ts_vector,
                            tag_text, has_text, has_link, has_chat, has_ask, has_images, has_video, has_audio) 
                    VALUES (:post_id, :blog_uuid, :post_date, :post_url, :slug, :blocks, 
                    setWeight(to_tsvector('simple', :vec_tags), 'A') || setWeight(to_tsvector('simple', :self_text), 'B') || setWeight(to_tsvector('simple', :trail_text), 'C') || setWeight(to_tsvector('simple', :image_text), 'D'),
                    setWeight(to_tsvector('en_us_hunspell', :vec_tags), 'A') || setWeight(to_tsvector('en_us_hunspell', :self_text), 'B') || setWeight(to_tsvector('en_us_hunspell', :trail_text), 'C') || setWeight(to_tsvector('en_us_hunspell', :image_text), 'D'),
                            :raw_tags, :has_text, :has_link, :has_chat, :has_ask, :has_images, :has_video, :has_audio)");
    $stmt_insert_tag = $db->prepare("INSERT INTO tags (tag_name, tag_simple_ts_vector) VALUES (:tag_text, to_tsvector('simple', :tag_text)) ON CONFLICT (tag_name) DO UPDATE SET tag_name = Excluded.tag_name RETURNING tag_id");
    $stmt_insert_posts_tags = $db->prepare("INSERT INTO posts_tags (blog_uuid, post_id, tag_id) VALUES(?, ?, ?) ON CONFLICT DO NOTHING");
    $stmt_insert_image = $db->prepare("INSERT INTO images (img_url, caption_vec, alt_text_vec) VALUES (:img_url, setWeight(to_tsvector('simple', :caption), 'A') || setWeight(to_tsvector('en_us_hunspell', :caption), 'B'), setWeight(to_tsvector('simple', :alt_text), 'A') || setWeight(to_tsvector('en_us_hunspell', :alt_text), 'B')) ON CONFLICT (img_url) DO UPDATE SET img_url = Excluded.img_url RETURNING image_id");
    $stmt_insert_posts_images = $db->prepare("INSERT INTO images_posts (post_id, image_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
    $stmt_give_up = $db->prepare("SELECT post_id FROM posts where blog_uuid = :blog_uuid ORDER BY post_date ASC LIMIT 1");
    $post_check_stmt = $db->prepare(getTextSearchString($search_query, "p.post_id = :post_id"));
  

    // Get the most_recent_post_id and post_id_last_indexed from the blogstats table
    $stmt_select_blog->execute([$blog_uuid]);
    $db_blog_info = $stmt_select_blog->fetch(PDO::FETCH_OBJ);
    $most_recent_post_id = $db_blog_info->most_recent_post_id;
    $post_id_last_indexed = $db_blog_info->post_id_last_indexed;
    $post_id_last_attempted = $db_blog_info->post_id_last_attempted;
    $db_blog_info->index_request_count = $db_blog_info->index_request_count + 1;
    $jump_triggered = false;
    $error_count = 0;

    $run_start_post_id = null;
    $stmt = $db->prepare("INSERT INTO blogstats (blog_uuid, index_request_count) VALUES (?, ?) 
                        ON CONFLICT (blog_uuid) 
                        DO UPDATE SET index_request_count = EXCLUDED.index_request_count")->execute([$blog_uuid, $db_blog_info->index_request_count]);
    do {
        $disk_use = get_disk_stats();
        $params = ['limit' => 50, 'npf' => 'true', 'before_id' => $before_id];
        $server_blog_info = call_tumblr($blog_name, 'posts', $params);
        if($run_start_post_id == null) $run_start_post_id = $server_blog_info->posts[0]->id_string;

        foreach ($server_blog_info->posts as $post) {
            $post->id = $post->id_string;
            if(!renewOrStealLease($db, $blog_uuid, $archiver_uuid)) {
                exit;
            }
            $get_active_queries->execute(["blog_uuid" => $blog_uuid]);
            $searches_array = $get_active_queries->fetchAll(PDO::FETCH_OBJ);
            $stmt_select_blog->execute([$blog_uuid]);
            $requestcheck = $stmt_select_blog->fetch(PDO::FETCH_OBJ)->index_request_count;
            if($db_blog_info->index_request_count > $requestcheck){
                die("Being replaced by new archive request");
            }
            $before_id = $post->id;
            // If the post is older than the post we began the last run with, skip over to posts older than the one we ended the last run with
            if ($most_recent_post_id != null && $post_id_last_attempted != null && $before_id <= $most_recent_post_id && $before_id >= $post_id_last_attempted) {
                if($post_id_last_indexed < $post_id_last_attempted) 
                    $post_id_last_attempted = $post_id_last_indexed;
                $before_id = $post_id_last_attempted;
                break;
            }

            $db_post_obj = extract_text_from_post($post);
            $tags = $post->tags;
            
            if($jump_triggered || $post_id_last_attempted == null) {
                $post_id_last_attempted = $before_id;
            }
            try {
                $stmt_update_blogstat_count->execute([
                    "runstart_post_id" => $run_start_post_id, 
                    "post_id" => $post_id_last_attempted, 
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
                    $stmt_insert_image->execute($will_insert);
                    $image_id = $stmt_insert_image->fetchColumn();
                    $img["db_id"] = $image_id;
                }
            
                list($transformed, $for_db) = transformNPF($post, $db_post_obj->images);

                $stmt_insert_post->execute(
                    ["post_id" =>$post->id, 
                    "post_url" => $post->post_url,
                    "slug" => $post->slug,
                    "blog_uuid"=>$blog_uuid, 
                    "post_date"=>$post->date,
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
                    $stmt_insert_posts_images->execute([$post->id, $image_id]);
                }

                // Insert tags into tags table and create relations in posts_tags table
                foreach ($tags as $tag) {
                    if(check_delete($tag, $archiving_status["indexed_this_time"], $blog_uuid, $db)) {
                        $archiving_status['disk_used'] = $disk_use;
                        $archiving_status['content'] = "Deleting blog by request of post <a href='$post->post_url'>$post->id</a>, please wait...";
                        sendEvent("FINISHEDINDEXING!$post_result_cont->search_id", (object)$archiving_status);                  
                        delete_blog($tag, $archiving_status["indexed_this_time"], $blog_uuid, $db);
                        $archiving_status['content'] = "Blog deleted. Goodbye.";
                        $archiving_status['deleted'] = true;                       
                        sendEvent("FINISHEDINDEXING!$post_result_cont->search_id", (object)$archiving_status);
                        $db->commit();
                        exit;
                    }
                    $stmt_insert_tag->execute(["tag_text" => $tag]);
                    $tagid = $stmt_insert_tag->fetchColumn();
                    $stmt_insert_posts_tags->execute([$blog_uuid, $post->id, $tagid]);
                    $arc_stat = (object)$archiving_status;
                    $arc_stat->newTag = (object)[]; 
                    $arc_stat->newTag->tag_id = $tagid;
                    $arc_stat->newTag->tagtext = $tag;
                    $arc_stat->newTag->user_usecount = 1;
                    $arc_stat->disk_used = $disk_use;
                    foreach($searches_array as $search_item) {
                        queueEvent("INDEXEDTAG!$search_item->search_id", $arc_stat);
                    }
                }
                
                $db_blog_info->indexed_post_count += 1;
                $stmt_update_blogstat_count->execute(
                    [$run_start_post_id, 
                    $before_id, 
                    $db_blog_info->indexed_post_count, 
                    $server_blog_info->total_posts, 
                    'TRUE', 'TRUE', $blog_uuid]);
                $db->commit();
            } catch (Exception $e) {
                $db->rollBack(); 
                $error_count++;
                if($e->getCode() == "23505") {
                    $jump_triggered = true;
                    if($error_count > 50) {
                        $stmt_give_up->execute(["blog_uuid" => $blog_uuid]); 
                        $before_id = $stmt_give_up->fetchColumn();
                        break;
                    } else if ($error_count > 150) {
                        break 2;
                    }
                    continue;
                }
                throw $e;  
            }
            $stmt_update_blog->execute([
                "runstart_post_id" => $run_start_post_id, 
                "post_id" => $post_id_last_attempted, 
                "indexed_count" => $db_blog_info->indexed_post_count,
                "serverside_posts_reported" => $server_blog_info->total_posts,
                "is_indexing" => 'TRUE', 
                "success" => 'TRUE', 
                "blog_uuid" => $blog_uuid]);
            $post_id_last_indexed = $post_id_last_attempted;
            $archiving_status["indexed_post_count"] = $db_blog_info->indexed_post_count;
            $archiving_status["indexed_this_time"] += 1;



            $post_searches_results = execAllSearches($db, $searches_array, "p.post_id = :post_id", ["post_id" => $post->id]);
            $listener_result_map = [];

            foreach($post_searches_results as $post_result_cont) {
                $post_result = $post_result_cont->results;
                $arc_stat = (object)$archiving_status;
                if($post_result != false) {
                    $post_result->tags = json_decode($post_result->tags);
                    $arc_stat->as_search_result = [$post_result];
                    $arc_stat->disk_used = $disk_use;
                }
                queueEvent("INDEXEDPOST!$post_result_cont->search_id", $arc_stat);
            }
            
            fireEventQueue();
        }
    } while (!empty($server_blog_info->posts));

    // Update blogstats table
    //$stmt = $db->prepare("INSERT INTO blogstats (blog_uuid, most_recent_post_id, time_last_indexed, total_posts) VALUES (?, ?, NOW(), ?) ON CONFLICT (blog_uuid) DO UPDATE SET most_recent_post_id = EXCLUDED.most_recent_post_id, last_indexed = EXCLUDED.last_indexed, total_posts = EXCLUDED.total_posts");
    //$stmt->execute([$blog_uuid, $before_id, $server_blog_info->total_posts]);
    $db->prepare("UPDATE blogstats SET success = TRUE, is_indexing = FALSE WHERE blog_uuid = ?")->execute([$blog_uuid]);
    $archiving_status['content'] = "All done! ".$archiving_status["indexed_post_count"]." posts found, and ".$archiving_status['indexed_this_time']." new posts indexed!";
    foreach($searches_array as $search_inst) {
        sendEvent("FINISHEDINDEXING!$search_inst->search_id", (object)$archiving_status); #notify the client
    }

} catch (PDOException $e) {
    $error_string = "Error: " . $e->getMessage();
    $archiving_status["error"] = $error_string;
    $db->prepare("UPDATE blogstats SET success = FALSE, is_indexing = FALSE WHERE blog_uuid = ?")->execute([$blog_uuid]);
    foreach($searches_array as $search_inst) {
        sendEvent("ERROR!$search_inst->search_id", (object)$archiving_status);
        sendEvent("ERROR!$search_inst->search_id", (object)$archiving_status);
    }
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    die($error_string . "\n");
}
$resolve_queries->execute(["blog_uuid" => $blog_uuid]);
$abandonLease->execute(["leader_uuid" => $archiver_uuid]);

/*returns an array containing 2 elements, first is raw text content ignoring anything that might be an image caption
second is image captions and their alt_text*/
function extractTextAndImageAltFromHTML($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html); 

    $xpath = new DOMXPath($dom);
    $imgNodes = $xpath->query('//img');

    // Extract alt text from each <img> node
    $altTexts = [];
    foreach ($imgNodes as $imgNode) {
        if ($imgNode->hasAttribute('alt')) {
            $altTexts[] = $imgNode->getAttribute('alt');
        }
        $imgNode->parentNode->removeChild($imgNode);
    }

    return [$dom->textContent, implode(' ', $altTexts)];
}

#If you're going through hell, keep going.
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
    $result = (object)["self" => "", "trail" => "", "images" => ""];
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

#oh god. oh god. oh god.
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
    //applyLayout($post->layout, $respost, $typeCount);

    return [$respost, $db_content];
}

function applyLayout($layout, &$post, &$typeCount) {
    $keyed_ltypes = [];
    
    foreach ($layout as $layoutItem) {
        $typenum = $typeCount[$layoutItem->type] ??  -1;
        $typeCount[$layoutItem->type] = $typenum;
        applyBlocksLayout($layoutItem, $post, $keyed_ltypes, $typeCount);
    }
    $post->content = array_filter($post->content, function($value) {
        return $value !== null;
    });
    foreach($keyed_ltypes as $k => $v) {
        foreach($v as $i) {
            $post->content[$i]->ltypes[] = $k;
        }
    }
    asMerged($post->content, $keyed_ltypes["ask"]);
}

function applyBlocksLayout($layoutItem, &$post, &$keyed_ltypes, &$typeCount, $iltype=null) {
    if($layoutItem->display) {
        $iltype = $layoutItem->type;
        foreach($layoutItem->display as $rows) {
            applyBlocksLayout($rows, $post, $keyed_ltypes, $typeCount, $iltype);
        }
    } else {
        $ltype = $iltype ?? $layoutItem->type;
        $typeCount[$ltype] = $typeCount[$ltype]+1;
        $typenum = $typeCount[$ltype];
        foreach($layoutItem->blocks as $block_index) {
            $keyed_ltypes["$ltype-$typenum"][] = $block_index;
            if($ltype == "ask") {
                $post->content[$layoutItem->blocks[$block_index]]->by = $layout->attribution->blog->name ?? "anonymous";
            }
        }
    }
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
