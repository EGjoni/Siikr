<?php
require_once 'internal/globals.php';

$username = $_GET["username"];
$raw_query = pg_escape_string($_GET["query"]."");
$search_params_assoc = sanitizeParams($_GET);
$search_params_arr = [];
foreach($search_params_assoc as $k => $v) {
    $search_params_arr[] = "$k:$v";
}
$search_params = implode(",", $search_params_arr);
//$parsed = parseParams($search_params);

$parser = new Parser('simple'); //fancy new abstract syntax tree parse
$query = "websearch_to_tsquery('simple', '$raw_query')";//$parser->parse($raw_query);
$blog_info = (object)[];
try {
    $db = get_db();
    $response = call_tumblr($username, "info", [], true);
    if($response->meta->status != 200) { 
        $error_string = "";
        foreach($response->errors as $err) {
            if($err->code == 0 && $response->meta->status == 404) {
                throw new Exception("lol, that's not even a real name");
            }
            $error_string .= "$err->detail \n";
        }           
        throw new Exception("Tumblr says: \"$error_string\"");
    }
    $blog_info = $response->response->blog;
    $blog_info->valid = true;
    $blog_info->blog_uuid = $blog_info->uuid;

    resolve_uuid($db, $username, $blog_info->blog_uuid);
    $activate_search = $db->prepare("INSERT INTO active_queries (query_text, query_params, blog_uuid) VALUES (:query_text, :query_params, :blog_uuid) ON CONFLICT  (query_text, query_params, blog_uuid) DO UPDATE SET blog_uuid = Excluded.blog_uuid returning search_id");
    $activate_search->execute(["query_text" => $query, "query_params"=>$search_params, "blog_uuid" => $blog_info->blog_uuid]);
    $blog_info->search_id = $activate_search->fetchColumn();

    $stmt = $db->prepare(getTextSearchString($query, $search_params));
    //$debugStat = explode(" FROM ", $stmt); 
    //foreach($debugStat as &$stat) $stat = "FROM $stat";
    $result = $stmt->execute(["q_uuid" => $blog_info->blog_uuid]);
    
    $blog_info->results = $stmt->fetchAll(PDO::FETCH_OBJ);

    /*$tags_stmt = $db->prepare(getTagInfoString());
    $tags_stmt->execute(["blog_uuid" => $blog_info->blog_uuid]);*/
    $blog_info->tag_list = [];//$tags_stmt->fetchAll(PDO::FETCH_ASSOC);
    $db = null;
    exec("php internal/archive.php ". $blog_info->search_id."  > /dev/null &");
} catch (Exception $e) {
    $blog_info->valid = false;
    $blog_info->display_error = $e->getMessage();
    $db = null;
}
ob_start('ob_gzhandler');
header('Content-Type: application/json');
echo json_encode($blog_info);


