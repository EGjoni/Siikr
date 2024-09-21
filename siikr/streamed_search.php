<?php
require_once './internal/globals.php';
$index_disabled = false;

$offset = $_GET["offset"];
$limit = $_GET["limit"];

$init_stride = 15;
$MAX_LIMIT = 99999;
$stride = 100;
$supplied_info = file_get_contents('php://input');
if($supplied_info != null) {
    $supplied_info = json_decode($supplied_info);
}
$blog_info = null;
$username = explode(".", $_GET["username"])[0];
$raw_query = pg_escape_string($_GET["query"]."");
$search_params_assoc = sanitizeParams($_GET);
$sort_mode_arg = $_GET["sortMode"];
$sort_mode = ["score" => "ORDER BY score desc", 
        "new" => "ORDER BY post_date desc", 
        "old" => "ORDER BY post_date asc", 
        "hits" => "ORDER BY hit_rate desc",
        "" => "ORDER BY score desc"][$sort_mode_arg];

if($limit == null) $limit = $MAX_LIMIT;

$meta_search_params = [
    "search_only" => isset($_GET["search_only"]) && $_GET["search_only"] == true,
    "listen_to" => isset($_GET["listen_to"]) ? $_GET["listen_to"] : $_SERVER["HTTP_HOST"],
    "archive_only" => isset($_GET["archive_only"]) && $_GET["archive_only"]
];
$search_params_arr = [];
foreach($search_params_assoc as $k => $v) {
    $search_params_arr[] = "$k:$v";
}
$search_params = implode(",", $search_params_arr);

function beginSearch($db) {
    global $index_disabled;
    global $meta_search_params;
    global $blog_info;
    global $username; 
    global $raw_query;
    global $search_params;
    
    global $sort_mode;
    global $supplied_info;
    
    $is_insurance_check = $_GET["isInsuranceCheck"];
    
    //$parsed = parseParams($search_params)
    $parser = new Parser('simple'); //fancy new abstract syntax tree parse
    $query = "$raw_query";//$parser->parse($raw_query)
    $blog_info = (object)[];
    try {
        $response = call_tumblr($username, "info", [], true);
        try {
            if($supplied_info == null) {
                $error_string = handleError($response);
                $blog_info = $response->response->blog;
            } else {
                $blog_info = $supplied_info;
            }
            $blog_info->blog_uuid = $blog_info->uuid;
            $blog_info->blog_name = $blog_info->name;
            $resolved_blog_info = resolve_uuid($db, $username, $blog_info, $blog_info->blog_name);
        } catch (Exception $e) {
            throw $e;
        } 
        $blog_info->valid = true;
        augmentValid($db, $blog_info);
        $search_query = getTextSearchString($query, $search_params)." $sort_mode";
        $archive_only = $meta_search_params["archive_only"];
        $search_only = $meta_search_params["search_only"];
        
        if(!$is_insurance_check) {
            $activate_search = $db->prepare("INSERT INTO active_queries (query_text, query_params, blog_uuid) VALUES (:query_text, :query_params, :blog_uuid) ON CONFLICT (query_text, query_params, blog_uuid) DO UPDATE SET blog_uuid = EXCLUDED.blog_uuid returning search_id");
            $activate_search->execute(["query_text" => $query, "query_params"=>$search_params, "blog_uuid" => $blog_info->blog_uuid]);
            $blog_info->search_id = $activate_search->fetchColumn();
            $search_id_info = (object)[]; 
            $search_id_info->server = $meta_search_params["listen_to"];
            if($is_insurance_check != true) {
                /* clientside script checks for missed results on completion, 
                but this makes me paranoid about infinite loops if I put the wrong FINISHEDINDEXING event, and anyway it's more efficient just to skip so...*/
                $predir = __DIR__;
                $exec_string = "php ".__DIR__."/internal/archive.php ". $blog_info->search_id. " ". $search_id_info->server;
                $no_index = $search_only || $index_disabled;
                if(!$no_index) exec("$exec_string  > /dev/null &");
                else if($index_disabled) {
                    throw new Exception("Sorry, indexing of new posts is temporarily disabled for maintenance");
                }
            }
            
            $search_id_info->search_id = $blog_info->search_id;
            $search_id_info->valid = true;
            $search_id_info->is_init = true;
            $search_id_info->blog_uuid = $blog_info->blog_uuid;
            if(!$search_only)
                encodeAndFlush($search_id_info);
        }
        $blog_info->tag_list = [];
        
        if(!$archive_only)
            sendByStreamedSet($db, $blog_info, $search_query);
        if($search_only) {
            $resolve_queries = $db->prepare("DELETE FROM active_queries WHERE blog_uuid = :blog_uuid"); 
            $resolve_queries->exec(["blog_uuid" => $blog_info->blog_uuid]);
        }
        //getByOffset($db, $blog_info, $search_query);        
        /*$tags_stmt = $db->prepare(getTagInfoString());
        $tags_stmt->execute(["blog_uuid" => $blog_info->blog_uuid]);*/
        //$tags_stmt->fetchAll(PDO::FETCH_ASSOC);

        $db = null;
    } catch (Exception $e) {
        $blog_info->valid = false;
        $blog_info->display_error = $e->getMessage();
        encodeAndFlush($blog_info);
        $db = null;
    }
}

function sendByStreamedSet($db, $blog_info, $search_query) {
    global $init_stride;
    global $stride;
    global $offset; 
    global $limit;
    $stmt = $db->prepare($search_query, array(\PDO::ATTR_CURSOR => \PDO::CURSOR_SCROLL));
    $blog_info->debug_query = $stmt->debug(["q_uuid" => $blog_info->blog_uuid], true);
    //$debugStat = explode(" FROM ", $stmt); 
    //foreach($debugStat as &$stat) $stat = "FROM $stat";
    $result = $stmt->exec(["q_uuid" => $blog_info->blog_uuid], true);

    $blog_info->results = [];
    if($r = $result->fetch(PDO::FETCH_OBJ)) $blog_info->results[] = $r; 
    for($i = 0; $i<$init_stride-1; $i++) {
        if($r = $result->fetch(PDO::FETCH_OBJ)) $blog_info->results[] = $r;
        else break;
    }
    $r_total = count($blog_info->results);
    if($r = $result->fetch(PDO::FETCH_OBJ)) {
        $blog_info->has_more = true;
    } else {
        $blog_info->has_more = false;
        $blog_info->total_results = $r_total; 
    }
    $blog_info->execution_time = $stmt->execution_time;
    encodeAndFlush($blog_info);    
    $blog_info->results = null;
    $blog_info->more_results=[];
    while($r) { 
        $blog_info->more_results[] = $r;
        $blog_info->has_more = !!($r = $result->fetch(PDO::FETCH_OBJ));

        if(count($blog_info->more_results) > $stride) {
            $r_total += count($blog_info->more_results);
            encodeAndFlush($blog_info);
            $blog_info->more_results = [];
        }
    }
    $blog_info->total_results = $r_total + count($blog_info->more_results);
    if(count($blog_info->more_results) > 0) encodeAndFlush($blog_info);
}

function encodeAndFlush($obj) {
    echo json_encode($obj);
    echo "\n#end_of_object#\n";
    ob_flush();      
    flush();
    ob_clean();
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

function handleError($response) {
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
}
header('Content-Type: application/json');
header('X-Accel-Buffering: no');
ob_implicit_flush(0);
ob_start('ob_gzhandler');
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
beginSearch($db);
