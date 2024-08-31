<?php

/**Like PDOStatement but with an exec convenience function that returns the statement again for easy chaining with fetch calls
 * An error is thrown on failure instead of returning false
*/
class SPDOStatement extends PDOStatement {
    private $dbh;
    public $execution_time = null;
    protected function __construct($dbh) {$this->dbh = $dbh;}
    /**
     * @param delta if true, sets a value on this prepared statement storing how long its last execution took to complete
     */
    public function exec($params, $deltas=false) {
        $qtime = null;
        if($deltas) $qtime = microtime(true);
        else $this->execution_time = null;
        $result = $this->execute($params) ? $this : throw new PDOException("Execution failed"); 
        if($deltas) $this->execution_time = microtime(true)- $qtime;
        return $result;
    }
}

/**Like PDO but prepared statements contain an exec convenience function that can be chained with subsequent fetch calls and throw an Error where execute returns false*/
class SPDO extends PDO {
    public function __construct($dsn, $username = null, $passwd = null, $options = null) {
        parent::__construct($dsn, $username, $passwd, $options);
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SPDOStatement::class, [$this]]);
    }
}

$predir = __DIR__.'/../';
require_once $predir.'auth/credentials.php';
require_once 'disk_stats.php';


$clean_sp = [
    "sp_self_text", "sp_self_media", "sp_tag_text", "sp_trail_text", "sp_trail_media", "sp_trail_usernames", //v4
    "sp_image_text"//v3
];
$clean_fp= ["fp_images", "fp_video", "fp_audio", "fp_ask", "fp_chat", "fp_link"];
//TODO: The enum approach takes 16 bytes per row and is actually less flexible than using bytes. (whereby inequality operators can apply via index)
//For 1600 blogs, this currently amounts to 800MB of space.
//switching to bytes would bring it down to 200MB
//if I can figure a performant way to switch to a single byte that would go down to 50MB.
//That's a 1,600% improvement in storage requirements for this column, but less than 0.7% improvement in total storage requirement...
// yeah it can wait.
$DENUM = [ 
    0 => 'FALSE', 
    1 => 'SELF',
    2 => 'TRAIL',
    3 => 'BOTH', //indicates that both must have images, not that either has them 
];


function get_base_url($blog_name_or_uuid, $request_type) {
    if(substr($blog_name_or_uuid, 0, 2) == "t:")
        return "https://api.tumblr.com/v2/blog/{$blog_name_or_uuid}/$request_type?";
    else   
        return "https://api.tumblr.com/v2/blog/{$blog_name_or_uuid}.tumblr.com/$request_type?";
}

function call_tumblr($blog_name_or_uuid, $request_type, $params=[], $with_meta = false) {
    global $api_key;
    $options = ['http' => ['ignore_errors' => true]];
    $params["api_key"] = $api_key;
    $encodedBlogName = urlencode($blog_name_or_uuid);
    $url = get_base_url($blog_name_or_uuid, $request_type).http_build_query($params);
    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);
    $response = json_decode($response);
    /*if($response->meta->status != 200) {
        throw new Error(implode(', ', get_object_vars($response->errors)));
    }*/    
    if($with_meta) 
        return $response;
    return $response->response;
}

function execAllSearches($db, $search_array, $match_condition, $exec_params) {
    $multiResults = []; 
    foreach($search_array as $search_item) { 
        $search_query = $search_item->query_text;
        $search_params = $search_item->query_params;
        $blog_uuid = $search_item->blog_uuid;
        $results = execSearch($db, $search_query, $search_params, $match_condition, $exec_params); 
        $multiResults[] = (object)[
            "search_query" => $search_query, 
            "search_params" => $search_params,
            "blog_uuid" => $blog_uuid,
            "results" => $results,
            "search_id" => $search_item->search_id
        ];
    }
    return $multiResults;
}

function execSearch($db, $search_query, $search_params, $match_condition, $exec_params) {
    $post_check_stmt = $db->prepare(getTextSearchString($search_query, $search_params, $match_condition));
    $post_check_stmt->execute($exec_params);
    $result = $post_check_stmt->fetch(PDO::FETCH_OBJ);
    return $result;
}

function getTagInfoString() {
    return "WITH TagsCount AS (
        SELECT pt.tag_id, COUNT(*) as user_usecount
        FROM posts_tags pt
        WHERE pt.blog_uuid = :blog_uuid
        GROUP BY pt.tag_id
    )
    SELECT t.tag_name as tagtext, tc.tag_id, tc.user_usecount
    FROM TagsCount tc
    JOIN tags t ON t.tag_id = tc.tag_id;";
}

function getTextSearchString($query, $search_params, $match_condition="p.blog_uuid = :q_uuid ") {
    $query_simple = $query; 
    $query_english = str_replace("_tsquery('simple',", "_tsquery('en_us_hun_simple',", $query);
    list($weight_string, $filter_string, $image_only) = parseParams($search_params);
    //if(!$image_only)
    return getPostSearchString($query_simple, $query_english, $match_condition, $weight_string, $filter_string);
    //else 
    //    return getImageSearchString($query, $match_condition);
}


function parseParams($paramstring) {
    global $clean_sp;
    global $clean_fp;
    global $DENUM;
    $param_arr = explode(",", $paramstring);
    $over_assoc = []; 
    $filter_assoc = [];
    foreach($param_arr as $kv) {
        $kvexp = explode(":", $kv);
        $k = $kvexp[0]; 
        $v = $kvexp[1];
        if(in_array($k, $clean_sp))
            $over_assoc[substr($k, 3)] = $v; 
        else if(in_array($k, $clean_fp))
            $filter_assoc[ "has_".substr($k, 3)] = $v;
    }
    //if(!$over_assoc["sp_self_text"] && !$over_assoc) 
    //$over_fields = [];
    if(isset($over_assoc['image_text'])) {//v3 -> v4
        $over_assoc['self_media'] = $over_assoc['image_text']; 
        $over_assoc['trail_media'] = $over_assoc['image_text']; 
        $over_assoc['trail_usernames'] = $over_assoc['trail_text'];
    }
    
    $over_weight[] = $over_assoc["self_text"] ? 1.0 : 0.0; 
    $over_weight[] = $over_assoc["self_media"] ? 1.0 : 0.0;
    $over_weight[] = $over_assoc["tag_text"] ? 1.0 : 0.0;

    $over_weight[] = $over_assoc["trail_text"] ? 1.0 : 0.0;
    $over_weight[] = $over_assoc["trail_usernames"] ? 1.0 : 0.0;
    $over_weight[] = $over_assoc["trail_media"] ? 1.0 : 0.0;
    $weight_string = "{".implode(", ", $over_weight)."}";
    $weightsum = 0.0;
    foreach($over_weight as $k => $v) {
        $weightsum += $v;
    }
    $media_only = $weightsum > 0 ? false : true;
    $filter_string = "";
    foreach($filter_assoc as $k => $v) {
        if($v == 3) {
            $filter_string .= "AND ($k = '$DENUM[1]' OR $k = '$DENUM[2]' OR $k = 'BOTH')";
        } else {
            $both_cond = $v >= 0 && $v < 3 ? " OR $k = 'BOTH'" : "";
            $filter_string .= "AND ($k = '$DENUM[$v]'$both_cond)";
        }
    }
    return array($weight_string, $filter_string, $media_only);
}


function getImageSearchString($query_simple, $query_english, $post_match_condition="p.blog_uuid = :q_uuid ") {
    return 
    "WITH filtered_ips AS (
        SELECT ip.post_id, ip.image_id
        FROM images_posts p
        WHERE $post_match_condition
    )
    SELECT realposts.*, (ts_rank(i.caption_vec, $query_simple) + ts_rank(i.caption_vec, $query_english) + ts_rank(i.alt_text_vec, $query_simple) + ts_rank(i.alt_text_vec, $query_english)) as score
    FROM posts realposts
    JOIN filtered_ips fip ON realposts.post_id = fip.post_id
    JOIN images i ON i.image_id = fip.image_id 
    AND (i.caption_vec @@ $query_simple
        OR
         i.caption_vec @@ $query_english
        OR 
         i.alt_text_vec @@ $query_simple
        OR
         i.alt_text_vec @@ $query_english
        )";
}

function getPostSearchString($query_simple, $query_english, $match_condition="p.blog_uuid = :q_uuid ", $weight_string, $filter_string) {
    return "SELECT 
                c.post_id::text, 
                --c.post_url, 
                c.post_date,
                c.blocks,
                c.tag_text, 
                (ts_rank('$weight_string', c.en_hun_simple, $query_simple) + ts_rank('$weight_string', c.ts_meta, $query_english)) as score,
                COALESCE(agg.tags, array_to_json(ARRAY[]::integer[])) as tags,
                COALESCE(mediaagg.media_info, '[]'::json) media
            FROM 
                (".getInnerSearchString($query_simple, $query_english, $match_condition, $filter_string).") as c 
            LEFT JOIN LATERAL 
                (
                    SELECT 
                        array_to_json(array_agg(t.tag_id)) as tags
                    FROM 
                        posts_tags pt
                    LEFT JOIN 
                        tags t ON pt.tag_id = t.tag_id
                    WHERE 
                        pt.post_id = c.post_id
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
                        mp.post_id = c.post_id
                    GROUP BY
                        mp.post_id
                ) as mediaagg ON true
        ";
}

/**query match for just posts. Useful if you want to posthoc and*/
function getInnerSearchString($query_simple, $query_english, $match_condition="p.blog_uuid = :q_uuid ", $filter_string="") {
    $result = "SELECT 
            p.post_id, 
            --p.post_url,
            p.post_date, 
            p.blocksb as blocks,
            p.tag_text,
            p.ts_meta,
            p.en_hun_simple
        FROM 
            posts p
        WHERE 
            $match_condition
            AND
            (p.ts_meta @@ $query_english
            OR
            p.en_hun_simple @@ $query_english)
            $filter_string";
    return $result;
}


class Parser {
    private $tokens;
    private $index;
    private $language;

    public function __construct($language = 'simple') {
        $this->language = $language;
    }

    public function parse($input) {
        //I have heard the voice of God, and it spoke thus.
        $this->tokens = preg_split('/\s*(?<!\\\)"(.*?)(?<!\\\)\"\s*|\s+/', $input, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $this->index = 0;    
        $ast = $this->expression();
        return $this->generateQuery($ast);
    }
    
    private function expression() {
        $factors = [$this->factor()];

        while ($this->index < count($this->tokens)) {
            $factors[] = $this->factor();
        }

        return ['type' => 'OR', 'factors' => $factors];
    }

    private function factor() {
        if ($this->match('-')) {
            return ['type' => 'NOT', 'factor' => $this->factor()];
        } else if ($this->tokens[$this->index][0] == '"') {
            $phrase = trim($this->tokens[$this->index++], '"');
            return ['type' => 'PHRASE', 'value' => $phrase];
        } else {
            return ['type' => 'WORD', 'value' => $this->tokens[$this->index++]];
        }
    }

    private function match($expected) {
        if ($this->index < count($this->tokens) && $this->tokens[$this->index] == $expected) {
            $this->index++;
            return true;
        } else {
            return false;
        }
    }

    private function generateQuery($ast) {
        switch ($ast['type']) {
            case 'OR':
                $queries = array_map([$this, 'generateQuery'], $ast['factors']);
                return '(' . implode(' || ', $queries) . ')';
            case 'AND':
                $queries = array_map([$this, 'generateQuery'], $ast['factors']);
                return '(' . implode(' && ', $queries) . ')';
            case 'NOT':
                return '!' . $this->generateQuery($ast['factor']);
            case 'WORD':
                return "to_tsquery('".$this->language."', '" . $ast['value'] . "')";
            case 'PHRASE':
                // Use phraseto_tsquery for the phrase
                return "phraseto_tsquery('".$this->language."', '" . $ast['value'] . "')";
        }
    }
}

/**
 * we want to check if the user is searching a deleted blog, and distinguish 
 * it as special from searching for an existing blog by its old name
 * 
 * we need to call tumblr with the blogname they are searching. 
 * - if tumblr has it, we just return tumblrs response as if this were a call to call_tumblr,
 * - if they don't we check out database to see if we have a blog_uuid.
 *      - if we don't we return tumblrs response again for error handling
 *      - if we do, we call tumblr again with that blog_uuid to find the new name
 *          - if tumblr has it, we just return the full response of what tumblr has
 *          - if they don't we've hit our HIT OUR SPECIAL CASE!!
 *              - return the blog_uuid, and name to trigger a search, but DO NOT archive
 *  
 * but given the complexity of the checks involved, we probably might as well handle db consistency resolution here.
 * which would make the logic:
 * 
 * - if tumblr has it check if the uuid matches what's in our database. 
 *      NOTE WE ALWAYS RETURN TUMBLRS FIRST RESPONSE IN THIS CASE 
 *      - << if it does match db_uuid, we just return tumblrs response as if this were a call to call_tumblr(),
 *      - << if it doesn't match db_uuid, we ultimately still return tumblrs response but before that
 *          - call tumblr again with our db_uuid to update the name in our database
 *              - set the name in our database to whatever tumblr reports, unless tumblr reports nothing
 *                  - in which case don't touch it. it's a deleted blog. 
 *          
 * - if they don't we check out database to see if we have a blog_uuid.
 *      - if we don't we return tumblrs response again for error handling
 *      - if we do, we call tumblr again with that blog_uuid to find the new name
 *          - if tumblr has it, we just return the full response of what tumblr has
 *          - if they don't we've hit our HIT OUR SPECIAL CASE!!
 *              - << return the blog_uuid, and name to trigger a search, but DO NOT archive
 * */
function nameCorrespondenceObj($db, $userProvidedName) {
    $existence_obj = (object)[];
    $tumblr_blogname_info = call_tumblr($existingBlogUuidForName, "info", [], true);
    $existence_obj->full_tumblr_response = $tumblr_blogname_info;
    if($new_blogname_info->meta->status == 200) {
        $existence_obj->tumblr_blogInfo_byUserProvidedName = $new_blogname_info->response->blog;
    }
    $db_blogInfo = $db->prepare("SELECT * FROM blogstats WHERE blog_name = :blog_name")->exec(["blog_name" => $userProvidedName])->fetch(PDO::FETCH_OBJ);
    if($db_blogInfo->blog_name) {
        $existence_obj->db_blogInfo_byUserProvidedName = $db_blogInfo;
    }
    return $existence_obj;

}


/**
 * @return obj a blogInfo object containing the resolved blog_uuid and name. This should be checked against the one tumblr purported before calling this function
 * so that the appropriate action or notification be issued clientside
* Handles the following cases: 
* 1. the blog_name does not exist in my table, nor does the blog_uuid.
* 2. the blog_name does not exist in my table, but the uuid does. (can happen if a user changes their blog name and searches siikr by their new name)
* 3. the blog_name exists in my table, and the blog_uuid is the same one tumblr reports. (returning user who hasn't changed their blog name)
* 4. the blog_name exists in my table so I have a blog_uuid, but tumblr reports the blog doesn't exist. (existing user changed their blog_name but is searching by their old name)
*     - I should update the name in my table to the one tumblr reports for the uuid in my table, if tumblr lets me get blog info by uuid 
* 5. the blog_name exists in my table, but the blog_uuid is different from the one tumblr reports
*   4a. the blog_uuid tumblr reports is not in my table (a new user has adopted the now abandoned name of an existing user). 
*   4b. the blog_uuid tumblr reports is in my table (one of my existing users has abandoned their name, and another of my existing users has adopted it)
* 6. I have been in hell this entire time.
 */
function resolve_uuid($db, $blogNameFromUser, $blogUuidFromTumblr, $blogNameFromTumblr) {
    $return_result = (object)[];
    try {
        $stmt = $db->prepare("SELECT blog_uuid FROM blogstats WHERE blog_name = :blog_name");
        $stmt->execute(['blog_name' => $blogNameFromUser]);
        $existingBlogUuidForName = $stmt->fetchColumn();
        $stmt2 = $db->prepare("SELECT blog_name FROM blogstats WHERE blog_uuid = :blog_uuid");
        $stmt2->execute(['blog_uuid' => $blogUuidFromTumblr]);
        $existingBlogNameForUuid = $stmt2->fetchColumn();

        $db->beginTransaction();
        if (!$existingBlogNameForUuid && !$existingBlogUuidForName) {
            // Situation 1
            $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid) 
                            VALUES (:blog_name, :blog_uuid)");
            $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
        } elseif ($existingBlogNameForUuid && !$existingBlogUuidForName) {
            // Situation 2
            $stmt = $db->prepare("UPDATE blogstats 
                        SET blog_name = :blog_name 
                        WHERE blog_uuid = :blog_uuid");
            $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
        } elseif ($existingBlogUuidForName == $blogUuidFromTumblr) {
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
        } elseif ($existingBlogUuidForName != NULL && $blogUuidFromTumblr == NULL) {
            // Situation 4
            $new_blogname_info = call_tumblr($existingBlogUuidForName, "info", [], true);
            if($new_blogname_info->meta->status != 200) {
                $error_string = implode("\n", $new_blogname_info->response->errors);
                throw new Exception("Failed to determine new blog_name for a blog name tumblr reports no longer exists. Tumblr says: \"$error_string\"");
            }
            $new_blogInfo = $new_blogname_info->$response->$blog;
            $new_name = $new_blogInfo->name;
            $change_name = $db->prepare("UPDATE blogstats SET blog_name = :new_name WHERE blog_uuid = :blog_uuid")->exec(["new_name"=>$new_name, "blog_uuid"=>$blog_uuid]);
            $return_result->uuid = $existingBlogUuidForName;
            $return_result->name = $new_name; 
        } else {
            // Situation 5
            if (!$existingBlogNameForUuid) {
                // Situation 5a 
                $uuid_end = substr($existingBlogUuidForName, -5);         
                $stmt = $db->prepare("UPDATE blogstats SET blog_name = CONCAT(blog_name, '_archfrom_', :uuid_end::text) WHERE blog_uuid = :blog_uuid");
                $stmt->execute(['blog_uuid' => $existingBlogUuidForName, 'uuid_end' => $uuid_end]);

                // Insert a new entry for the new blog_uuid and blog_name.
                $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid) VALUES (:blog_name, :blog_uuid)");
                $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
                $return_result->name = $blogNameFromTumblr;
                $return_result->uuid = $blogUuidFromTumblr;
            } else {
                // Situation 5b
                $uuid_end = substr($existingBlogUuidForName, -5);
                $stmt = $db->prepare("UPDATE blogstats SET blog_name = CONCAT(blog_name, '_archfrom_', :uuid_end::text) WHERE blog_uuid = :old_blog_uuid");
                $stmt->execute(['old_blog_uuid' => $existingBlogUuidForName, 'uuid_end' => $uuid_end]);

                $stmt = $db->prepare("UPDATE blogstats SET blog_name = :blog_name WHERE blog_uuid = :blog_uuid");
                $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
                $return_result->name = $blogNameFromTumblr;
                $return_result->uuid = $blogUuidFromTumblr;
            }
            
        }
        $db->commit();
    } catch (PDOException $e) {
        $db->rollback();
        throw $e;
    }
    return $return_result;
}


/**checks if indexing the provided blog is likely to result in a full disk. 
* @return true if there is sufficient room for the blog, false if indexing the blog might result in running out disk space
*/
function ensureSpace($db, $db_blogInfo, $server_blog_info) {
	global $db_disk;
	global $db_min_disk_headroom;
    $blog_uuid = $db_blogInfo->blog_uuid;
	$indexed_post_count = min($server_blog_info->total_posts, $db_blogInfo->indexed_post_count);
	$posts_anticipated = $server_blog_info->total_posts - $indexed_post_count;
	$averagePostSize = 3300;
	$anticipatedSpaceRequired = $averagePostSize * $posts_anticipated;
    $other_anticipated_posts = $db->prepare("SELECT COALESCE(SUM(b.serverside_posts_reported - LEAST(b.indexed_post_count, b.serverside_posts_reported)), 0) 
                            FROM blogstats b, archiver_leases l WHERE l.blog_uuid = b.blog_uuid AND l.blog_uuid != :blog_uuid")->exec(['blog_uuid' => $blog_uuid])->fetchColumn();
    $anticipatedSpaceRequired += $averagePostSize * $other_anticipated_posts;
    $free_space = disk_free_space($db_disk);
	$current_wal = $db->prepare("SELECT wal_bytes FROM pg_stat_wal")->exec([])->fetchColumn();
	$max_wal = sizeToBytes($db->prepare("SHOW max_wal_size")->exec([])->fetchColumn());
    
	$required_wal_headroom = $max_wal - $current_wal;
	$free_space -= $required_wal_headroom + $db_min_disk_headroom;
	$anticipated_free_space = $free_space - $anticipatedSpaceRequired;
	return $anticipated_free_space >= 0;
}


function sanitizeParams($paramArr) {
    global $clean_sp;
    global $clean_fp;
    $sanitary = [];
    
    foreach($paramArr as $k => $v) {
        if(in_array($k, $clean_sp) && (bool)$v) {
            $sanitary[$k] = $v;
        }
        else if(in_array($k, $clean_fp) && (int)$v) {
            $sanitary[$k] = $v;
        }
    }
    return $sanitary;
}



/** deletes a blog. internally calls check_delete first out of sheer paranoia.
 * returns true if deletion was successful
 * */
function delete_blog($tag_text, $prior_count, $blog_uuid, $db) {
    if(check_delete($tag_text, $prior_count, $blog_uuid, $db)) {
        $db->prepare("SELECT delete_blog(:blog_uuid)")->execute([$blog_uuid]);
        return true;
    }
    return false;
}

/**
 *Determines if a blog is requesting deletion from the index by the spcial magic tag text.
 * Returns true if deletion was requested and succeed, false if not.
 ***/
function check_delete($tag_text, $prior_count, $blog_uuid, $db) {
    $magic_words="YES HELLO SIIKR HI PLEASE DELETE MY BLOG THANK YOU I'M SORRY I'LL LEAVE NOW THANK YOU PLEASE DON'T BE MAD.";
    if($tag_text == $magic_words && $prior_count < 20) {
        return true;
    }
    return false;
}


/**convenience function to automatically */
function fetch($statement, $params, $fetchStyle = PDO::FETCH_OBJ) {
    if ($statement->execute($params)) {
        return $statement->fetch($fetchStyle);
    }
    return false;  // or handle the error as needed
}


function determineNextIdx($idx, $low_mark, &$high_mark, $auto_cap) {
    $idx = $idx === 0 ? 1 : 2*$idx;
    if($idx > $auto_cap && $high_mark == null) {
        $high_mark = $auto_cap;    
    }
    if($high_mark != null) 
        return $idx = round((($high_mark + $low_mark)/2)+0.01);
    else
        return $idx;
}

/**queries back and forth between tumblr and the siikr database to determine where a gap exists in the posts that have been ingested.
 * 
 * Procedure. 
 * Search our database for oldest post at offset = idx when posts are arranged in temporally asc order. Do the same for tumblr.
 * If we match, double our idx, set low_mark to idx and repeat.
 * If they mismatch, check if we have the post tumblr returns.
 *  If we do: get the number of posts in our db older than it.
 *      If that number is greater than our offset for that post, then a post was deleted and we can safely-ish assume there's probably no gap there.
 *      If that number is less than our offset for that post, then there's a gap and this post should be our new high mark and we should set the idx to the midpoint and start again.
 *  If we don't, then we've found our gap.
 *      Search our db for the oldest post which is newer than the one tumblr returned, and begin archiving from there.
*/
function binarySearchMissing($db, $blog_info) {
    $blog_uuid = $blog_info->blog_uuid; 
    $blog_name = $blog_info->blog_name;
    $get_db_post = $db->prepare(
                        "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                        WHERE post_id = :post_id");
    $get_post_at_idx = $db->prepare(
                            "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                            WHERE blog_uuid = :blog_uuid ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
    $get_post_at_filt_offset = $db->prepare(
                            "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                            WHERE blog_uuid = :blog_uuid and post_date >= to_timestamp(:min_date) ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
    $get_fake_post_offset = $db->prepare(
                            "SELECT COUNT(*) FROM posts 
                            WHERE blog_uuid = :blog_uuid and post_date < to_timestamp(:max_date) and post_date >= to_timestamp(:min_date)");
    $get_slow_post_offset = $db->prepare(
                            "SELECT COUNT(*) FROM posts 
                            WHERE blog_uuid = :blog_uuid and post_date < to_timestamp(:max_date)");
    $get_oldest_post_after = $db->prepare(
                            "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                             WHERE blog_uuid = :blog_uuid AND post_date > to_timestamp(:min_date) ORDER BY post_date ASC LIMIT 1");
    $verify_offset = $db->prepare(
                        "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                         WHERE blog_uuid = :blog_uuid ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
    $high_mark = null;
    $prev_low_mark = 0;
    $low_mark = 0;
    $idx=0;
    $prev_delete_count = 0;
    $deleted_count = 0;
    $most_recently_posted_match_date = 0;
    $most_recently_posted_match_offset = 0;
    $prev_most_recently_posted_match_date = 0;
    $prev_most_recently_posted_match_offset = 0;
    $forceCount = 1;
    do {
        $db_post_info = $get_post_at_filt_offset->exec(["blog_uuid" => $blog_uuid, "offset" => $idx-$most_recently_posted_match_offset, "min_date" => $most_recently_posted_match_date])->fetch(PDO::FETCH_OBJ);
        /*$db_post_info = $get_post_at_idx->exec(["blog_uuid" => $blog_uuid, "offset" => $idx])->fetch(PDO::FETCH_OBJ);
        if($db_post_info_fast->id != $db_post_info->id) {
            throw new Error("I am not very good at math");
        }*/
        $tumblr_info = call_tumblr($blog_name, 'posts', ['offset'=>$idx - $deleted_count, 'sort' => 'asc', 'limit' => 5]);
        $tumblr_all_posts_info = $tumblr_info->posts;
        $tumblr_post_info = empty($tumblr_all_posts_info) ? null : $tumblr_all_posts_info[0];
        if($tumblr_post_info == null && $high_mark != null) {
            //tumblr reports nothing at this offset, so we've hit the end.
            return null;
        } else if ($tumblr_post_info == null && $high_mark == null) {
            //force high mark to make sure we didn't miss anything
            $high_mark = $tumblr_info->total_posts;
            $low_mark = $prev_low_mark; $deleted_count = $prev_delete_count; 
            $most_recently_posted_match_date = $prev_most_recently_posted_match_date; 
            $most_recently_posted_match_offset = $prev_most_recently_posted_match_offset;
            $idx = determineNextIdx($idx, $low_mark, $high_mark, $tumblr_info->total_posts);
            continue;
        } else if($tumblr_post_info->id != $db_post_info->id) {
            $db_matched_post_offset_info = $get_db_post->exec(["post_id" => $tumblr_post_info->id])->fetch(PDO::FETCH_OBJ);
            if($db_matched_post_offset_info) {
                //post exists both with tumblr and with us, but our offsets don't match.
                $db_offset_count = $get_fake_post_offset->exec(
                                        ["blog_uuid" => $blog_uuid, 
                                        "max_date" => $db_matched_post_offset_info->timestamp, 
                                        "min_date" => $most_recently_posted_match_date])->fetchColumn();
                $db_offset_count += $most_recently_posted_match_offset;
                if($idx < $db_offset_count) {
                    //tumblr reports fewer posts older than ours, indicating some posts were deleted. We account for the deleted post when querying tumblr and continue
                    $prev_delete_count = $deleted_count;
                    $deleted_count = $db_offset_count - $idx;
                    $prev_most_recently_posted_match_date = $most_recently_posted_match_date;
                    $most_recently_posted_match_date = $db_post_info->timestamp; 
                    $prev_most_recently_posted_match_offset = $most_recently_posted_match_offset;
                    $most_recently_posted_match_offset = $idx;
                    $idx = determineNextIdx($idx, $low_mark, $high_mark, $tumblr_info->total_posts);
                    if($idx==$high_mark || $idx == $low_mark) {$forceCount--;}
                    $prev_low_mark = $low_mark; $low_mark = $idx; 
                } else if( $idx > $db_offset_count) {
                    //tumblr implicitly reports that the post we expected at the requested offset should have more predcessors than we think. User has been time traveling or using date edit feature that tumblr allows for some reason.
                    //in either case, we have likely found a gap. But we have yet to figure out exactly where it is.
                    $high_mark = $idx;
                    $low_mark = $prev_low_mark;
                    $deleted_count = $prev_delete_count;
                    $most_recently_posted_match_date = $prev_most_recently_posted_match_date; 
                    $most_recently_posted_match_offset = $prev_most_recently_posted_match_offset;                    
                    $idx = determineNextIdx($idx, $low_mark, $high_mark, $tumblr_info->total_posts);
                    if($idx==$high_mark || $idx == $low_mark) { $forceCount--;}
                } else {
                    throw new Exception("Huh? You should never hit this");
                }
            } else {
                //tumblr reports a post we don't have. We can now shortcut to the start of the gap.
                $oldest_after = $get_oldest_post_after->exec([":blog_uuid" => $blog_uuid, "min_date" => $tumblr_post_info->timestamp])->fetch(PDO::FETCH_OBJ);
                return $oldest_after;
            }            
        } else {
            //tumblr's offset and ours are in agreement. Double or midpoint the index and low water mark
            $prev_most_recently_posted_match_date = $most_recently_posted_match_date;
            $most_recently_posted_match_date = $db_post_info->timestamp; 
            $prev_most_recently_posted_match_offset = $most_recently_posted_match_offset;
            $most_recently_posted_match_offset = $idx;
            $old_ix = $idx; 
            $idx = determineNextIdx($idx, $low_mark, $high_mark, $tumblr_info->total_posts);
            if($idx==$high_mark || $idx == $low_mark) { $forceCount--;}
            $prev_low_mark = $low_mark; $low_mark = $idx;
        }
        if($forceCount < 0 && $high_mark != null && $high_mark - $low_mark <=1) {
            return $db_post_info;
        }
    } while (
        (
        ($high_mark == null || $high_mark - $low_mark >= 1) 
         && $idx <= $blog_info->indexed_post_count
        ) || $forceCount >= 0
    );
    return null;
}
