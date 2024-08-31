<?php

$predir = __DIR__.'/../';
require_once $predir.'auth/credentials.php';
require_once 'disk_stats.php';


$clean_sp = ["sp_self_text", "sp_trail_text", "sp_image_text", "sp_tag_text"];
$clean_fp= ["fp_images", "fp_video", "fp_audio", "fp_ask", "fp_chat", "fp_link"];
$DENUM = [
    0 => 'FALSE',
    1 => 'SELF',
    2 => 'TRAIL',
    3 => 'BOTH',
];


function call_tumblr($blog_name, $request_type, $params=[], $with_meta = false) {
    global $api_key;
    $options = ['http' => ['ignore_errors' => true]];
    $params["api_key"] = $api_key;
    $encodedBlogName = urlencode($blog_name);
    $url = "https://api.tumblr.com/v2/blog/{$encodedBlogName}.tumblr.com/$request_type?" . http_build_query($params);
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
    $query_english = str_replace("_tsquery('simple',", "_tsquery('en_us_hunspell',", $query);
    list($weight_string, $filter_string, $image_only) = parseParams($search_params);
    if(!$image_only)
        return getPostSearchString($query_simple, $query_english, $match_condition, $weight_string, $filter_string);
    else 
        return getImageSearchString($query, $match_condition);
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
                c.post_url, 
                c.post_date,
                c.blocks,
                c.tag_text, 
                (ts_rank('$weight_string', c.simple_ts_vector, $query_simple) + ts_rank('$weight_string', c.english_ts_vector, $query_english)) as score,
                COALESCE(agg.tags, array_to_json(ARRAY[]::integer[])) as tags
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
            ORDER BY 
                score
        ";
}

/**query match for just posts. Useful if you want to posthoc and*/
function getInnerSearchString($query_simple, $query_english, $match_condition="p.blog_uuid = :q_uuid ", $filter_string="") {
    $result = "SELECT 
            p.post_id, 
            p.post_url,
            p.post_date, 
            p.blocks,
            p.tag_text,
            p.simple_ts_vector,
            p.english_ts_vector
        FROM 
            posts p
        WHERE 
            $match_condition
            AND
            (p.simple_ts_vector @@ $query_simple
            OR
            p.english_ts_vector @@ $query_english)
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


/*
Handles the following cases: 
1. the blog_name does not exist in my table, nor does the blog_uuid.
2. the blog_name does not exist in my table, but the uuid does. (can happen if a user changes their blog name)
3.. the blog_name exists in my table, and the blog_uuid is the same one tumblr reports. (returning user who hasn't changed their blog name)
4. the blog_name exists in my table, but the blog_uuid is different from the one tumblr reports
  4a. the blog_uuid tumblr reports is not in my table (a new user has adopted the now abandoned name of an existing user). 
  4b. the blog_uuid tumblr reports is in my table (one of my existing users has abandoned their name, and another of my existing users has adopted it)
5. I have been in hell this entire time.
 */
function resolve_uuid($db, $blogNameFromTumblr, $blogUuidFromTumblr) {
    try {
        $stmt = $db->prepare("SELECT blog_uuid FROM blogstats WHERE blog_name = :blog_name");
        $stmt->execute(['blog_name' => $blogNameFromTumblr]);
        $existingBlogUuidForName = $stmt->fetchColumn();
        $stmt2 = $db->prepare("SELECT blog_name FROM blogstats WHERE blog_uuid = :blog_uuid");
        $stmt2->execute(['blog_uuid' => $blogUuidFromTumblr]);
        $existingBlogNameForUuid = $stmt2->fetchColumn();

        $db->beginTransaction();
        if (!$existingBlogNameForUuid && !$existingBlogUuidForName) {
            // Situation 1
            $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid) VALUES (:blog_name, :blog_uuid)");
            $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
        } elseif ($existingBlogNameForUuid && !$existingBlogUuidForName) {
            // Situation 2
            $stmt = $db->prepare("UPDATE blogstats SET blog_name = :blog_name WHERE blog_uuid = :blog_uuid");
            $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
        } elseif ($existingBlogUuidForName == $blogUuidFromTumblr) {
            // Situation 3
        } else {
            // Situation 4
            
            if (!$existingBlogNameForUuid) {
                // Situation 4a 
                $uuid_end = substr($existingBlogUuidForName, -5);         
                $stmt = $db->prepare("UPDATE blogstats SET blog_name = CONCAT(blog_name, '_archfrom_', :uuid_end::text) WHERE blog_uuid = :blog_uuid");
                $stmt->execute(['blog_uuid' => $existingBlogUuidForName, 'uuid_end' => $uuid_end]);

                // Insert a new entry for the new blog_uuid and blog_name.
                $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid) VALUES (:blog_name, :blog_uuid)");
                $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
            } else {
                // Situation 4b
                $uuid_end = substr($existingBlogUuidForName, -5);
                $stmt = $db->prepare("UPDATE blogstats SET blog_name = CONCAT(blog_name, '_archfrom_', :uuid_end::text) WHERE blog_uuid = :old_blog_uuid");
                $stmt->execute(['old_blog_uuid' => $existingBlogUuidForName, 'uuid_end' => $uuid_end]);

                $stmt = $db->prepare("UPDATE blogstats SET blog_name = :blog_name WHERE blog_uuid = :blog_uuid");
                $stmt->execute(['blog_name' => $blogNameFromTumblr, 'blog_uuid' => $blogUuidFromTumblr]);
            }
            
        }
        $db->commit();
    } catch (PDOException $e) {
        $db->rollback();
        throw $e;
    }
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

    
    $over_weight[] = $over_assoc["self_text"] ? 1.0 : 0.0; 
    $over_weight[] = $over_assoc["trail_text"] ? 1.0 : 0.0;
    $over_weight[] = $over_assoc["tag_text"] ? 1.0 : 0.0;
    $over_weight[] = $over_assoc["image_text"] ? 1.0 : 0.0;
    $weight_string = "{".implode(", ", $over_weight)."}";
    $weightsum = 0.0;
    foreach($over_weight as $k => $v) {
        $weightsum += $v;
    }
    $image_only = $weightsum > 0 ? false : true;
    $filter_string = "";
    foreach($filter_assoc as $k => $v) {
        if($v == 3) {
            $filter_string .= "AND ($k = '$DENUM[1]' OR $k = '$DENUM[2]' OR $k = 'BOTH')";
        } else {
            $both_cond = $v >= 0 && $v < 3 ? " OR $k = 'BOTH'" : "";
            $filter_string .= "AND ($k = '$DENUM[$v]'$both_cond)";
        }
    }
    return array($weight_string, $filter_string, $image_only);
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
