<?php
require_once 'internal/globals.php';

/**
 * returns posts by post id, associating them with whatever score is provided.
 * useful for not melting the server with searches when user is just navigating back and forward.
 * Expects an object of the form {
 *  blog_uuid: //self explanatory
 *  posts: {
 *      post_id1: score, 
 *      post_id2: score, etc
 *   }
 * } blog_uuid, limit, and an associative array of post_id => score. Streams out the contents of the post_ids up to the limit, then streams out the rest beyond the limit
 */
$jsonData = file_get_contents('php://input');
$data = json_decode($jsonData);
$post_ids = array_keys((array)$data->posts);
$blog_info = $data->blogInfo;
$blog_uuid = $data->blog_info->blog_uuid;



function beginSearch($db) {
    global $blog_info;
    global $data;
    global $blog_uuid;
    global $post_ids; 
    
    try {
        $blog_info->valid = true;
        augmentValid($db, $blog_info);
        $blog_info->tag_list = [];
        $qmarks = str_repeat('?,', count($post_ids) - 1) . '?';
        $stmt = $db->prepare(
            "SELECT c.post_id_i::TEXT as post_id, c.*, 
            COALESCE(agg.tags, array_to_json(ARRAY[]::integer[])) as tags,
            COALESCE(mediaagg.media_info, '[]'::json) media
                FROM (
                    SELECT 
                        post_id as post_id_i, post_date, blocksb as blocks, tag_text, is_reblog, hit_rate
                    FROM posts WHERE blog_uuid = ? AND post_id IN ($qmarks)) as c 
                    LEFT JOIN LATERAL
                    (SELECT 
                        array_to_json(array_agg(t.tag_id)) as tags
                     FROM 
                        posts_tags pt
                     LEFT JOIN 
                        tags t ON pt.tag_id = t.tag_id
                     WHERE 
                        pt.post_id = c.post_id_i
                     GROUP BY 
                        pt.post_id
                    ) as agg ON true
                    LEFT JOIN LATERAL 
                    (
                        SELECT 
                            json_agg(row_to_json(m.*)) as media_info
                        FROM 
                            media_posts mp
                        LEFT JOIN
                            media m ON mp.media_id = m.media_id
                        WHERE 
                            mp.post_id = c.post_id_i
                        GROUP BY
                            mp.post_id
                    ) as mediaagg ON true
             ");
        $blog_info->results = $stmt->exec([$blog_info->blog_uuid, ...$post_ids], true)->fetchAll(PDO::FETCH_OBJ);
        $posts = ((array)$data->posts);
        foreach($blog_info->results as &$r) {
            $r->score = $posts[$r->post_id];
        }

        $db = null;
    } catch (Exception $e) {
        $blog_info->valid = false;
        $blog_info->display_error = $e->getMessage();
        $db = null;
    }
    encodeAndFlush($blog_info);
}

function augmentValid($db, $blog_info) {
    $stmt_select_blog = $db->prepare("SELECT * FROM blogstats WHERE blog_uuid = ?");
    if($blog_info->valid) {
        try {
            $db_blog_info = $stmt_select_blog->exec([$blog_info->blog_uuid])->fetch(PDO::FETCH_OBJ);
            if($db_blog_info) {
                $blog_info->indexed_post_count = $db_blog_info->indexed_post_count;
            }
        } catch (Exception $e) {}
    }
}

function encodeAndFlush($obj) {
    echo json_encode($obj);
    ob_flush();      
    flush();
    ob_clean();
}


header('Content-Type: application/json');
ob_start('gz_handler');
$db = new SPDO("pgsql:dbname=$db_name", "www-data", null);
beginSearch($db);
