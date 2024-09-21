<?php 
require_once 'globals.php';
require_once 'disk_stats.php';
require_once 'post_processor.php';
//echo phpversion();
$archiver_uuid = uuid_create_v4();
$userInfo = posix_getpwuid(posix_geteuid());
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$media_table = 'media';

require_once 'lease.php';

//next two lines are so that we can show the user any search results while we happen to come across them in the indexing process
$search_query = @$_GET['search_query']; 
$search_options = @$_GET['search_options'];
$server_blog_info = [];
$blog_uuid = null;
function rollback() {
    global $db;
    echo "nixing";
    $db->rollback();
}

$vsplit = explode(" ", $argv[1]);
if(count($vsplit) > 0) $search_id = $vsplit[0];
if($argc > 2) $this_server_url = $argv[2];
if(count($vsplit) > 2) $this_server_url = $vsplit[1];
if(count($vsplit) > 3 && $vsplit[3] == "dev") register_shutdown_function('rollback');

$current_blog = $db->prepare("SELECT blog_uuid FROM active_queries WHERE search_id = ? LIMIT 1");
$blog_uuid = $current_blog->exec([$search_id])->fetchColumn();
$establishLease->exec([$blog_uuid, $archiver_uuid]); //defined in lease.php
$get_active_queries = $db->prepare("SELECT * FROM active_queries WHERE blog_uuid = :blog_uuid"); 
$searches_array = $get_active_queries->exec(["blog_uuid" => $blog_uuid])->fetchAll(PDO::FETCH_OBJ);

$stmt_select_blog = $db->prepare("SELECT * FROM blogstats WHERE blog_uuid = ?");
$db_blog_info = $stmt_select_blog->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
$resolve_queries = $db->prepare("DELETE FROM active_queries WHERE blog_uuid = :blog_uuid"); 
if($db_blog_info == false) {
    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    die("Error: blog_uuid not in blogstats");
}
$db_blog_info->index_request_count += 1;
$db->prepare("UPDATE blogstats SET index_request_count = ? WHERE blog_uuid = ?")->exec([$db_blog_info->index_request_count, $blog_uuid]);



$zmqsock_identifier = $archiver_uuid; #each archiver_instance gets their own zmq socket for update broadcasts
require_once $predir.'/internal/messageQ.php';

$blog_name = $db_blog_info->blog_name;


$archiving_status = (object)[
    "blog_uuid" => $blog_uuid, 
    "blog_name" => $blog_name, 
    "as_search_result" => [],
    "serverside_posts_reported" => &$db_blog_info->serverside_posts_reported,
    "indexed_post_count" => &$db_blog_info->indexed_post_count,
    "indexed_this_time" => 0];

if(!ensureSpace($db, $db_blog_info)) {
    $resolve_queries->execute(["blog_uuid" => $blog_uuid]);
    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
    $archiving_status->notice = "Siikr is nearly out of disk space and this blog would make it die. For the good of the many, it must sacrifice the few. (i.e you).";
    sendEventToAll($searches_array, 'NOTICE!', $archiving_status);
    die("Error: insufficient disk space");
}


sendEvent("INDEXING!$search_id", $archiving_status); //broadcast a zmq message for anyone interested to know about the blog indexing status.


try {
    $before_id = null;
    $set_post_deleted = $db->prepare("UPDATE posts SET deleted = TRUE WHERE post_id = :post_id");
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
        "UPDATE blogstats SET 
                        post_id_last_attempted = :post_id_last_attempted, 
                        indexed_post_count = :indexed_count,
                        serverside_posts_reported = :serverside_posts_reported,
                        success = :success, 
                        is_indexing = :is_indexing WHERE blog_uuid = :blog_uuid");

    /**
     * with two tsvector columns we can have 
     * ts_content:
     *  self_text = 'A'
     *  self_text_mentions = 'B'
     *  trail_text = 'C' 
     *  trail_text_mentions = 'D' 
     * 
     * ts_meta: 
     *  tag_text = 'A'
     *  self_media = 'B'
     *  trail_media = 'C'
     *  trail_usernames = 'D'
     * 
     * english_stem_simple 
     *  self_text = 'A'
     *  self_media = 'B' 
     *  self_media = 'C'
     *  trail_media = 'D'
     */
    
    /**columns that don't need us to do extra junk*/
    $unprocessed_columns = ["blog_uuid", "tag_text", "index_version"];
    $unprocessed_str = ""; foreach($unprocessed_columns as $col) $unprocessed_str .= ", :$col as $col";
    $insert_post_column_list = ["post_date", "blocksb", "is_reblog", "hit_rate", "ts_content", "ts_meta", ...$unprocessed_columns];
    $withrec = "WITH rec AS (SELECT :post_id::BIGINT AS post_id, 
                to_timestamp(:post_date::INT) AS post_date,
                :blocksb::JSON AS blocksb,
                :is_reblog::BOOLEAN AS is_reblog,
                :hit_rate::FLOAT AS hit_rate,
                :has_text::has_content as has_text, :has_link::has_content as has_link, :has_chat::has_content as has_chat, 
                :has_ask::has_content as has_ask, :has_images::has_content as has_images, :has_video::has_content as has_video, 
                :has_audio::has_content as has_audio $unprocessed_str, ";
    $post_postinsert = "
    setWeight(to_tsvector('$content_text_config', :tag_text), 'A')
    || setWeight(to_tsvector('$content_text_config', :self_media_text), 'B') 
    || setWeight(to_tsvector('$content_text_config', :trail_media_text), 'C') 
    || setWeight(to_tsvector('simple', :trail_usernames), 'D') as ts_meta
    )";
   
    //slowish variant when usermentions are included
    $nomenA = "setWeight(to_tsvector('$content_text_config', :self_no_mentions), 'A')::TEXT"; 
    $wmenB = "(SELECT replace(setWeight(to_tsvector('$content_text_config', :self_with_mentions), 'B')::TEXT, '@siikr.tumblr.com', ''))::TEXT";
    $nomenC = "setWeight(to_tsvector('$content_text_config', :trail_no_mentions), 'C')::TEXT";
    $wmenD = "(SELECT replace(setWeight(to_tsvector('$content_text_config', :trail_with_mentions), 'D')::TEXT, '@siikr.tumblr.com', ''))::TEXT";
    //$stem_tags = "setWeight(to_tsvector('english_stem_simple', :tag_text), 'A') || (SELECT replace(setWeight(to_tsvector('english_stem_simple', :self_with_mentions), 'B')::TEXT, '@siikr.tumblr.com', ''))::TEXT";
    $doesMention = "
    ($nomenA || ' ' || $wmenB)::tsvector || 
    ($nomenC || ' ' || $wmenD)::tsvector as ts_content,
    ";
    //faster variant when no user mentions
    $noMention = "setWeight(to_tsvector('$content_text_config', :self_text_regular), 'A') || setWeight(to_tsvector('$content_text_config', :trail_text_regular), 'C') as ts_content,";
    
    
   

    $insert_only= "INSERT INTO posts (post_id, ".implode(", ",$insert_post_column_list).") 
                    SELECT :post_id, ".implode(", rec.", $insert_post_column_list)." FROM rec";
    $recMap = ""; foreach($insert_post_column_list as $rec) {$recMap.= ", $rec = rec.$rec";}
    $recMap = ltrim($recMap,",");
    $update_only = "UPDATE posts SET $recMap FROM rec WHERE posts.post_id = :post_id AND posts.index_version < :index_version";

    $stmt_mention_insert_post = $db->prepare("$withrec $doesMention $post_postinsert $insert_only");
    $stmt_nomention_insert_post = $db->prepare("$withrec $noMention $post_postinsert $insert_only");
    $update_mention_versioned_post = $db->prepare("$withrec $doesMention $post_postinsert $update_only");
    $update_nomention_versioned_post = $db->prepare("$withrec $noMention $post_postinsert $update_only");


    $delete_existing_mp_links = $db->prepare("DELETE FROM media_posts WHERE post_id = :post_id");

    $stmt_insert_tag = $db->prepare("INSERT INTO tags (tag_name, tag_simple_ts_vector) VALUES (:tag_text, to_tsvector('simple', :tag_text)) ON CONFLICT (tag_name) DO UPDATE SET tag_name = Excluded.tag_name RETURNING tag_id");
    $stmt_insert_posts_tags = $db->prepare("INSERT INTO posts_tags (blog_uuid, post_id, tag_id) VALUES(?, ?, ?) ON CONFLICT DO NOTHING");
    
    $stmt_upsert_media = $db->prepare( //My kingdom! My kingdom for unique multi-column hash constraint support!
        "WITH record AS (
        SELECT ROW(:media_url, :preview_url, LEFT(:title, 250), LEFT(:description, 1000))::media_info AS r), 
        existing AS (SELECT media_id FROM $media_table WHERE media_meta = (SELECT r FROM record) LIMIT 1), 
        inserted AS (
            INSERT INTO $media_table (media_meta, mtype) 
            SELECT r, :media_type FROM record WHERE NOT EXISTS (SELECT 1 FROM existing) RETURNING media_id) 
        SELECT media_id FROM inserted UNION ALL SELECT media_id FROM existing LIMIT 1");

    $stmt_insert_posts_media = $db->prepare("INSERT INTO media_posts (post_id, media_id) VALUES (?, ?) ON CONFLICT DO NOTHING");
    $get_oldest_indexed_post = $db->prepare("SELECT post_id FROM posts where blog_uuid = :blog_uuid AND deleted = FALSE ORDER BY post_date ASC LIMIT 1");
    //this line is for upgrading posts of an old version
    $get_newest_obsolete_post =  $db->prepare("SELECT post_id, EXTRACT(epoch FROM post_date)::INT as timestamp, index_version FROM posts where blog_uuid = :blog_uuid AND index_version < '$archiver_version' 
                        AND deleted = FALSE 
                        AND post_date < to_timestamp(:max_date::INT) ORDER BY post_date DESC LIMIT 1");
    
    
    $stmt_get_post_info = $db->prepare("SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp, index_version FROM posts where post_id = :post_id");
    //$post_check_stmt = $db->prepare(getTextSearchString($search_query, "p.post_id = :post_id"));
    $set_blog_success_status = $db->prepare("UPDATE blogstats SET success = ?, is_indexing = FALSE WHERE blog_uuid = ?");
    
    // Get the most_recent_post_id and post_id_last_indexed from the blogstats table
    $most_recent_post_id = $db_blog_info->most_recent_post_id;
    $most_recent_post_info = $stmt_get_post_info->exec([$most_recent_post_id])->fetch(PDO::FETCH_OBJ);
    if($db_blog_info->time_last_indexed != null) {
        $post_id_last_indexed = $db_blog_info->post_id_last_indexed;
        $post_id_last_attempted = $db_blog_info->post_id_last_attempted;
        //next line is for debug. delete when done.
        //$smallest_post_id_indexed_info = $db->prepare("SELECT post_id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts where blog_uuid = :blog_uuid ORDER BY post_id ASC LIMIT 1")->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
        $oldest_post_id_indexed =  $get_oldest_indexed_post->exec(["blog_uuid"=>$blog_uuid])->fetchColumn();
        if($most_recent_post_info->index_version < $archiver_version) { //start fresh if the posts are indexed with an old version
            unset($oldest_post_id_indexed, $post_id_last_indexed, $post_id_last_attempted, $most_recent_post_info, $most_recent_post_id);
        } else {
            if($post_id_last_indexed == null) $post_id_last_indexed = $oldest_post_id_indexed->post_id;
            $post_last_indexed_info = $stmt_get_post_info->exec([$post_id_last_indexed])->fetch(PDO::FETCH_OBJ);            
            $post_last_attempted_info = $stmt_get_post_info->exec([$post_id_last_attempted])->fetch(PDO::FETCH_OBJ);
            $oldest_post_indexed_info = $stmt_get_post_info->exec([$oldest_post_id_indexed])->fetch(PDO::FETCH_OBJ);
        }
    }
    
    //$oldest_server_post = call_tumblr($blog_name, 'posts', ['limit' => 1, 'npf' => 'true', 'sort' => 'asc'])->posts[0];
    
    function get_media_id(&$media_item) {
        global $stmt_upsert_media;
        $title = property_exists($media_item, "title") && $media_item->title != null ? substr($media_item->title, 0, 250) : NULL;
        $description =  property_exists($media_item, "description") && $media_item->description != null ? substr($media_item->description, 0, 1000) : NULL;
        if($title != null) $title = ensure_valid_string($title);
        if($description != null) $description = ensure_valid_string($description);
        $preview_url = property_exists($media_item, "preview_url") && $media_item->preview_url != null ? $media_item->preview_url : NULL;
        $will_insert = 
        ["media_url" => $media_item->media_url, 
        "preview_url"=> $preview_url, 
        "title" => $title, 
        "description" => $description, 
        "media_type" => $media_item->type];
        $media_id = $stmt_upsert_media->exec($will_insert)->fetchColumn();
        return $media_id;
    }

    function insert_media(&$db_post_obj, &$media_list) {
        global $possible_encodings;
        foreach ($media_list as &$med) {
            $media_item = (object)$med;
            $media_id = get_media_id($media_item);
            $med["db_id"] = $media_id;
            $db_post_obj->media_by_id[$media_id] = $media_item->media_url;
            $db_post_obj->media_by_url[$media_item->media_url][] = $media_id;
        }
    }

    /**
     * todo: for wordclouds, this function will specify that a blog should be completely removed from the global
     * wordcloud statistics and analyzed from scratch (used on index upgrades as they can substantiallly alter the data)
     **/
    function addToReanalysisQueue($blog_uuid) {}

    /**
     * todo: for wordclouds, this function inform the wordcloud system that a blog has new posts to accoutn for
     */
    function addToAugmentQueue($blog_uuid) {}

    $loop_count = 0;
    $upgradeCount = 0; //counts number of posts that have been upgraded


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
    $limit_start = 50;
    require_once 'adopt_blog.php';
    /*gather_foreign_posts($blog_uuid, $archiver_version, $this_server_url, 
                        $oldest_post_indexed_info?->timestamp ?? null, 
                        $most_recent_post_info?->timestamp ?? null);*/
    do {
        $before_info = array_pop($gap_queue);
        $before_time = $before_info == null? null:$before_info->timestamp;
        $before_id = $before_info->id;
        do {
            $isUpgrade = false; //gets set to true for notifications downstream
            $disk_use = get_disk_stats();        
            $params = ['limit' => $limit_start, 'notes_info' => "true", 'npf' => 'true', 'before' => $before_time, 'sort' => 'desc'];
            do { //apparently sometimes the api just flat out breaks if you ask for more posts than it has.
                $server_blog_info = call_tumblr($blog_uuid, 'posts', $params);
                if($server_blog_info == null) {
                    if($limit_start/2 <= 1 && $params["notes_info"] == true) {
                        $params["notes_info"] = false; //a lot of times the API breaking happens as a result of note data i guess, so  try without those before giving up  
                    }
                    $limit_start = $limit_start / 2;
                }
                if($before_info?->inclusive == true) {
                    $contained = false;
                    foreach($server_blog_info->posts as $post) {
                        if($post->id_string == $before_info->post_id) {
                            $contained = true;
                            break;
                        }
                        //echo ($before_info->timestamp - $post->timestamp)."\n";
                    }
                    if(!$contained) {
                        $before_info->timestamp = $before_info->actual_timestamp;
                        $server_blog_info->posts = [$before_info, ...$server_blog_info->posts];
                        //echo "\nhuh???\n";
                    }
                }
                $params["limit"] =  max((int)$limit_start, 1);                
            } while($server_blog_info == null && (int)$limit_start >= 1);

            if($server_blog_info == null)
                $server_blog_info = (object)["posts"=>[]];
            else $limit_start = min(50, (int)$limit_start * 4);
            
            foreach ($server_blog_info->posts as $post) {
                $post->id = $post->id_string;
                //echo "attempting: $post->id\n";
                if(@$most_recent_post_id == null || $post->timestamp > @$most_recent_post_info->timestamp) {
                    $most_recent_post_id = (int)$post->id;
                    $most_recent_post_info = $post;
                }
                if(@$oldest_post_id_indexed == null || $post->timestamp < @$oldest_post_indexed_info->timestamp) {
                    $oldest_post_id_indexed = (int)$post->id;
                    $oldest_post_indexed_info = $post;
                }
                
                if(!amLeader($db, $blog_uuid, $archiver_uuid)) {
                    //$resolve_queries->execute(["blog_uuid" => $blog_uuid]);
                    $abandonLease->execute(["leader_uuid" => $archiver_uuid]);
                    exit;
                }
                $get_active_queries->execute(["blog_uuid" => $blog_uuid]);
                $searches_array = $get_active_queries->fetchAll(PDO::FETCH_OBJ);
                //error_log("ATTEMPTING TO SEND: Processing!".$searches_array[0]->search_id);
                //sendEventToAll($searches_array, "PROCESSING!", json_encode($arc_stat));
                $post_id = $post->id;
                $before_info = $post;
                $before_time = $before_info->timestamp;
                
                $db_post_obj = extract_db_content_from_post($post);
                $tags = $post->tags;
                
                if($post_id_last_attempted == null) {
                    $post_id_last_attempted = $post_id;
                    $post_last_attempted_info = $post;
                }
                try {
                    $response_post_num++;
                    $stmt_update_blogstat_count->exec([
                        "post_id_last_attempted" => $post_id, 
                        "indexed_count" => $db_blog_info->indexed_post_count,
                        "serverside_posts_reported" => $server_blog_info->total_posts,
                        "is_indexing" => 'TRUE', 
                        "success" => 'FALSE', 
                        "blog_uuid" => $blog_uuid]);
                    $db->beginTransaction();

                    // Insert post into posts table
                    $tag_rawtext = implode('\n#', $tags);
                    if(mb_strlen($tag_rawtext) > 0) $tag_rawtext = "#$tag_rawtext";
                    $tag_tstext = implode('\n', $tags);

                    $db_post_obj->media_by_id = [];
                    $db_post_obj->media_by_url = [];
                    
                    insert_media($db_post_obj, $db_post_obj->self_media_list);
                    insert_media($db_post_obj, $db_post_obj->trail_media_list);
                
                    $transformed= transformNPF($post, $db_post_obj);

                    $common_inserts = ["post_id" =>$post->id, 
                        "blog_uuid"=>$blog_uuid, 
                        "post_date"=>$post->timestamp,
                        "blocksb" => json_encode($transformed),
                        "self_media_text" => $db_post_obj->self_media_text,
                        "trail_media_text" => $db_post_obj->trail_media_text,
                        "trail_usernames" => $db_post_obj->trail_usernames,
                        "has_text" => $DENUM[$db_post_obj->has_text],
                        "has_link" => $DENUM[$db_post_obj->has_link],
                        "has_chat" => $DENUM[$db_post_obj->has_chat],
                        "has_ask" => $DENUM[$db_post_obj->has_ask],
                        "has_images" => $DENUM[$db_post_obj->has_images],
                        "has_video" => $DENUM[$db_post_obj->has_video],
                        "has_audio" => $DENUM[$db_post_obj->has_audio],
                        "tag_text" => $tag_rawtext,
                        "index_version" => $archiver_version,
                        "is_reblog" => $db_post_obj->is_reblog,
                        "hit_rate" => $db_post_obj->hit_rate];
                    try { 
                        $db->query("SAVEPOINT insert_post");
                        if(count($db_post_obj->self_mentions_list) + count($db_post_obj->trail_mentions_list) > 0) {
                            $common_inserts["self_no_mentions"] = $db_post_obj->self_text_no_mentions;
                            $common_inserts["self_with_mentions"] = $db_post_obj->self_text_augmented_mentions;
                            $common_inserts["trail_no_mentions"] = $db_post_obj->trail_text_no_mentions;
                            $common_inserts["trail_with_mentions"] = $db_post_obj->trail_text_augmented_mentions;
                            $stmt_mention_insert_post->exec($common_inserts);
                        } else {
                            $common_inserts["self_text_regular"] = $db_post_obj->self_text_regular;
                            $common_inserts["trail_text_regular"] = $db_post_obj->trail_text_regular;
                            $stmt_nomention_insert_post->exec($common_inserts);
                        }
                    } catch(Exception $e) {
                        if($e->getCode() == "23505") {
                            $db->query("ROLLBACK TO SAVEPOINT insert_post");
                            $rows_updated = 0;
                            $e2 = null;
                            $useStatement = $update_nomention_versioned_post;
                            if(count($db_post_obj->self_mentions_list) + count($db_post_obj->trail_mentions_list) > 0)
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
                                $delete_existing_mp_links->exec(["post_id"=>$post->id]); //whipe old media_links for upgrade
                                $isUpgrade = true;
                                $rows_updated++;
                                addToReanalysisQueue($blog_uuid);
                            }
                            
                            if($isUpgrade == false) 
                                throw $e;

                        } else {
                            throw $e;
                        }
                    }
                    
                    foreach ($db_post_obj->self_media_list as &$med) {
                        $stmt_insert_posts_media->exec([$post->id, $med["db_id"]]);
                    }
                    foreach ($db_post_obj->trail_media_list as &$med) {
                        $stmt_insert_posts_media->exec([$post->id, $med["db_id"]]);
                    }

                    $__arc_stat = serialize($archiving_status);
                    // Insert tags into tags table and create relations in posts_tags table
                    foreach ($tags as $tag) {
                        if(check_delete($tag, $archiving_status->indexed_this_time, $blog_uuid, $db)) {
                            $archiving_status->disk_used = $disk_use;
                            $archiving_status->content = "Deleting blog by request of post <a href='https://".$server_blog_info->blog->name.".tumblr.com/$post->id'>$post->id</a>, please wait...";
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
                    //echo ".";
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
                        } else {$arc_stat->as_search_result = null;}
                        $arc_stat->isUpgrade = $isUpgrade;
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
            $max_date = time();           
            if($newest_old_version_post == null) {
                $newest_old_version_post = $get_newest_obsolete_post->exec(["blog_uuid"=>$blog_uuid, 'max_date'=> $max_date])->fetch(PDO::FETCH_OBJ);
            }
            $max_date = $newest_old_version_post->timestamp;
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
                        $arc_stat->post_id = $post_id;
                        sendEventToAll($searches_array, "NOTICE!", $arc_stat);
                    } else {
                        //It's not very effective...
                        $arc_stat->error = "Could not find sneaky posts...this is usually due to interactions with deactivated blogs.";
                        $arc_stat->notice = $arc_stat->error;
                        $arc_stat->post_id = $post_id;
                        $arc_stat->resolved = false;
                        $success_status = 'FALSE';
                        sendEventToAll($searches_array, "ERROR!", $arc_stat);
                    }
                }
            } else if($newest_old_version_post != false) { //upgrade old posts.
                do {
                    /**
                     * in theory we could retrieve the post from the database, but if the user edited the post, our timestamp will be wrong
                     * and we will miss it. So at least this way we can be sure we get everything.
                    */
                    $iparams = ['limit' => 1, 'id' => $newest_old_version_post->post_id];
                    $tumblr_result =  call_tumblr($blog_name, 'posts', $iparams, true);
                    $posts = [];
                    $tryAnother = false;
                    if($tumblr_result->meta->status == 200) {
                        $posts = $tumblr_result->response->posts;
                    }
                    $reanalyze = count($posts) > 0; 
                    if($reanalyze) {
                        $db_p_inf = $stmt_get_post_info->exec(["post_id" => $posts[0]->id_string])->fetch(PDO::FETCH_OBJ);
                        if($db_p_inf->index_version == $archiver_version)
                            $reanalyze = false;
                    }

                    if($reanalyze) {
                        $newest_old_version_post = $posts[0];
                        $newest_old_version_post->inclusive = true;
                        if(property_exists($newest_old_version_post, "id_string")) {
                            $newest_old_version_post->post_id = $newest_old_version_post->id_string;
                            $newest_old_version_post->id = $newest_old_version_post->id_string;
                            $newest_old_version_post->actual_timestamp = $newest_old_version_post->timestamp;
                        }
                        $newest_old_version_post->timestamp +=1;
                        if(count($server_blog_info->posts) > 0) $loop_count--; //in case tumblr is fucking with us. Which it does, sometimes.
                        //echo "queing: $newest_old_version_post->post_id\n";
                        array_push($gap_queue, $newest_old_version_post);
                    } else {
                        if(property_exists($tumblr_result, "errors")) {
                            foreach($tumblr_result->errors as $err) {
                                if($err->title == "Not Found") {
                                    //echo "deleting : $newest_old_version_post->post_id\n";
                                    $set_post_deleted->exec(["post_id"=>$newest_old_version_post->post_id]);                                    
                                    $tryAnother = true;
                                    break;
                                }
                            }
                        }
                        $newest_old_version_post = $get_newest_obsolete_post->exec(["blog_uuid"=>$blog_uuid, 'max_date'=> $max_date])->fetch(PDO::FETCH_OBJ);
                        if($newest_old_version_post != false) {
                            $max_date = $newest_old_version_post->timestamp;
                            //echo "checking: $newest_old_version_post->post_id\n";
                        }
                        else break;
                    }
                } while($tryAnother);
                              
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
    $arc_stat->post_id = $post_id;
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

/**
 * Takes a post in NPF format, extracts and recomposes its content for databases entry.
 * Multiple decompositions and transformations occur, and multiple are returned.
 * @return array of the form [
 *     [0] : A standard object containing post information intended for displaying to the user. This comprises a "self" post object made by the user, and a trail array of the post's reblog history. 
 *      Each post object includes 
 *          - various meta information like:
 *              - the name of the blog which made the post,
 *              - the date of the post, 
 *              - the tumblr link to the post, 
 *              - etc
 *          - an array of the blocks in the post, arranged by appearance 
 *              - with any image urls referenced by image_id
 *              - any text formatting inlined into the text content
 * 
 *      [1] : A standard object containing a prepprocessed textual representation of the post approriate for database input.
 *          This part is a bit tricky so you'll need to pay attention to understand it.
 *              1. We want to specifically mark any username / mention text in a post as being such. I use the term "we" here loosely, to mean "mostly just me for now". Specifically, if we can't distinguish between people referencing a user and people using a word, then we pollute downstream analysis for other fun stuff like wordclouds . But it's also hypothetically useful for future features (like, if you want to search for interactions you've had with a user who changes their blog name a lot).
 *              2. So let's say we have a post mentioning @antinegationism and @siikr.
 *                  "Salutations, @antinegationism. I extend my sincerest gratitutde for your impeccable work on @siikr, the tumblr search engine which really exists."  
 *              We want this to lexemetize to
 *               'salutation:1A,  antinegationism:2B,  extend:4A,  sincere:6A,  gratitutde:7A,  impeccable:10A,  work:11A,  siikr:13B,  tumblr:15A,  search:16A,  engine:17A,  real:19A, exist:20A'
 *              Notice how most words get a weight of A, while mentioned users get a weight of B. This all while each lexeme retains its position information in the original text. This allows us to continue using phrase matching in search if someone wants for example the exact phrase "Salutations antinegationism I extend", while still allowing us to filter out usernames on statistical queries or fancier downstream features.
 *              3. Unfortunately, postgresql doesn't let us set lexeme weights inline. So this means we'd have to split the string up on every detected username mention just to seperately set its weight to something we can selectively filter or replace. This defeats the purpose of using prepared statements, since we'd be rebuilting the query from scratch each time. Aside from being slow, this would be extremely error prone depending on the stopwords our split happens to occur on.
 *              - If you think there's a bunch of trivial ways to overcome this -- you're wrong and postgres doesn't support any of those things you are thinking. 
 *              4. Instead, we take advantage of a few quirks and poorly documented behavior tsvector concatenation.
 *                 - First, stop words reserve their position when lexemetized, despite not appearing.
 *                 - Second, a stop word from a specific dictionary inhibits application in a later dictionary (so, english "the" gets turned into a stopword, and thereby never appears in simple dictionary's "the")
 *                 - Third (this one is actually an obstacle), the only way to combine tsvectors is to concatenate them, which offsets the positions of the second vector to account for the positions of the first vector.
 *                 - Fourth, a tsvector can be cast to a string and vice versa, and the tsvector will retain its information
 *                 - Fifth, in order to ensure a tsvector is valid, some deduplication, merging and prioritizing occurs. 
 *                      - This means tokens which are identical in both strings by both lexeme and position get merged into a single token.
 *                      - Strangely, though tsvector supports two different lexemes of the same weight in the same position, and two different lexemes of different weights in the same position, and the same lexeme in multiple positions with different or the same weights; it does not support the same lexeme in the same position with multiple weights.
 *                      - antinegationism:22A,23B is valid, but antinegationism:22A,22B is not.
 *                      - upon resolution, whichever appearance has the highest weight precedence (A) gets priority.
 *              5. So in theory, to get the result we want, we can just make two versions of our string. A version weighted 'A' with any user mentions replaced by a stop word (to retain position of all other words), and a version weighted 'B', which contians the actual mentioned usernames. 
 *          Then just turn each into a tsvector, 
 *          cast them both to strings, 
 *          concatenate the strings,
 *          and parse the strings as tsvectors, knowing that the A weights will take precedence where words exist, and the B weight words will only appear in the positions where they take priority over stop words (which don't exist in the vector at all).
 * 
 *      HOWEVER IN PRACTICE . . .  tumblr user names can have hyphens, underscores, and numbers. And postgres parses these as distinct lexeme types, be they word hyphenations, equation parts, numwords . . . basically anticipatin all of the things that can happen in advance amounts to rewriting the parser. And we definitely DON'T want to ts_vectorize every single username mention just to determine the number of stop words to replace with it.
 * 
 *     DOUBLY HOWEVER, the parser does NOT do this if something looks like an email. 
 *     Emails are very carefully preserved as such. And as it so happens, the valid characters for an email addres are a superset of the valid characters for a tumblr username. So we can always just use a single stopword per username in the placeholder string, and just add @siikr.tumblr.com to the username containing string. Then strip out what we injected from the string cast from the tsvector created from the email augmented username containing string. Easy!
 * Which means the full procedure looks like:
 *          - Create version S_a of the string where every username or mention is replaced with a stopword.
 *          - Create version S_b of the string, where every username or mention has '@siikr.tumblr.com' appended to it.
 *          - Create a tsvector by 
 *              -- (setWeight(to_tsvector('$content_text_config', S_a), 'A')::TEXT || ' ' || 
 *                  (SELECT replace(setWeight(to_tsvector('$content_text_config', S_b, 'B')::TEXT), '@siikr.tumblr.com', ''))::tsvector 
 *      
 *      All of this is to say, that this entry contains two strings. 
 *      S_a, and S_b. Both are necessary, you should use them, and furthermore if you are modifying siikr to better support a non-english language, you should make sure to replace the stopword with something that is a stopword in whatever dictionary your language uses 
 * ]
 **/
?>
