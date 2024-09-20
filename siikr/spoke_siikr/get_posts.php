<?php

/**
 * Requests that this siikr spoke return any posts it has indexed meeting the
 * the specified constraints for the requested blog.
 * The posts will be returned as a JSON array. 
 * Each element in the json array will correspond to a post, and each post will 
 * contain a `media` attribute and a `tags` attribute. 
 * The tags attribute will contain a (potentially empty) array of strings, each representing a tag on the post.
 * The media attribute will contain a json object, where each keye corresponds to a node internal media id for that media item 
 * and each value is the actual media item object. It is the responsibililty of the receiving nde to make sure new media_ids 
 * are mapped to these entries wherever they may be referenced in a block, such that the media_ids ocrrespond to the node's internal foreign key relations.
 * 
 * Takes as arguments  
 *  a  `blog_uuid` (required),
 *  a  `requested_by` (required, node url),  
 *  a  'version` parameter (required, single character 0-9,a-Z),
 *  a  `limit` (optional, an integer indicating the maximum number of posts to retrieve from each spoke (default 200, best not to set this too high as nodes may timeout while trying to retrieve the posts))
 *  0 or 1 of either a `before` parameter (optional, timestamp), or an `after` parameter (timestamp, optional). If neither is provided, defaults to "before now()"
 *  you may also POST a json array list of up to 200 post_ids. If provided, this will ignore any before or after parameters and simply return the post_ids in the list should the server have them and should they belong to the requested blog and be of the requested version
 */



require_once './../internal/globals.php';
$blog_uuid = $_GET["blog_uuid"];
$id_list = file_get_contents('php://input');

$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$args = [
    "blog_uuid" => $blog_uuid, 
    "index_version" => $_GET["version"], 
    "result_limit" => isset($_GET["limit"]) ? $_GET["limit"] : 200];
$selection_stmt = "post_date < now()";
if($id_list = null) { $id_list = [];}
else if($id_list != null) {
    $id_list = json_decode($id_list);
    $placeholders = implode(',', array_fill(0, count($post_ids), '?'));
    $selection_stmt = " post_id in ($placeholders)";
} else if(isset($_GET["after"])) {
    $selection_stmt = " post_date > to_timestamp(:timestamp_anchor) ";
    $args["timestamp_anchor"] = $_GET["after"];
} else if(isset($_GET["before"])) { 
    $selection_stmt = " post_date < to_timestamp(:timestamp_anchor) ";
    $args["timestamp_anchor"] = $_GET["before"];
}


$stmt = $db->prepare(
    "SELECT c.post_id_i::TEXT as post_id, c.*, 
    COALESCE(agg.tags, array_to_json(ARRAY[]::text[])) as tags,
    COALESCE(mediaagg.media_info, '[]'::json) media
        FROM (
            SELECT 
                post_id as post_id_i, post_date, 
                blocksb as blocks, tag_text, 
                is_reblog, 
                hit_rate, 
                extract(epoch from post_date)::INT as timestamp,
                '$content_text_config' as text_config,
                ts_meta,
                ts_content as ts_content,
            FROM posts
            WHERE blog_uuid = :blog_uuid
            AND index_version = :index_version
            AND $selection_stmt
            LIMIT :result_limit) as c 
            LEFT JOIN LATERAL
            (SELECT 
                array_to_json(array_agg(t.tag_name)) as tags
             FROM 
                posts_tags pt
             LEFT JOIN 
                tags t ON pt.tag_id = t.tag_id
             WHERE
                pt.blog_uuid = :blog_uuid 
                AND 
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

$stmt->bindValues($args);
$posts = $stmt->exec($id_list)->fetchAll(PDO::FETCH_OBJ);

if($posts == false) {
    $posts = [];
}


header('Content-Type: application/json');
echo json_encode($posts);
ob_flush();      
flush();