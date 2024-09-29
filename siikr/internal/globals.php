<?php

ini_set('display_errors', 0);
$archiver_version = '3';
$deletion_rate = 0.994; //approximate, we estimate users are sufficiently ashamed of roughly 0.5% of the things they say to warrant deletion. It would be expensive to determine the exact number on a case by case basis, so this estimate is derived from the average deletion rate on a sample of 200 users. The variance is actually quite high per user but ultimately this number gets divided by 50, so most of the variance gets swept under the 1.5 orders of magnitude.


$possible_encodings =['UTF-8', 'ISO-8859-1', 'ASCII', 'JIS', 'EUC-JP', 'SJIS'];
function ensure_valid_string($string) {
    global $possible_encodings;
    $detected_encoding = mb_detect_encoding($string, $possible_encodings, true);
    return mb_convert_encoding($string, 'UTF-8', $detected_encoding);
}

/**
 * return a utf8 substring at the given position of the givne length 
 * @param string haystack
 * @param int position
 * @param int length
**/
function usub($str, $start, $length) {
    return mb_substr($str, $start, $length, 'UTF-8'); 
}

function usubstr_replace($original, $replacement, $position, $length) {
    $startString = mb_substr($original, 0, $position, 'UTF-8');
    $endString = mb_substr($original, $position + $length, mb_strlen($original), 'UTF-8');
    return $startString . $replacement . $endString;
}

/**
 * returns the utf8 character at the given position 
 * @param string haystack
 * @param string position
*/
function uc($str, $pos) {
    return mb_substr($str, $pos, 1, 'UTF-8');
}

$predir = __DIR__.'/../';
require_once 'SPDO.php';
require_once $predir.'auth/credentials.php';
require_once $predir.'auth/config.php';
require_once 'disk_stats.php';


$clean_sp = [
    "sp_self_text", "sp_self_mentions", "sp_trail_text", "sp_trail_mentions", //v4 ts_content
    "sp_tag_text", "sp_self_media", "sp_trail_media", "sp_trail_usernames", //v4 ts_meta
    "sp_image_text"//v3
];
$clean_fp= ["fp_include_reblogs", "fp_images", "fp_video", "fp_audio", "fp_ask", "fp_chat", "fp_link"];
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

$RENUM = [ 
    'FALSE' => 0, 
    'SELF' => 1,
    'TRAIL' => 2,
    'BOTH' => 3, //indicates that both must have images, not that either has them 
];


/**strips http:// or https:// from the url 
 * also automatically sets utf-8 encoding
*/
function normalizeURL($url) {
    global $possible_encodings;    
    $url = ltrimstr($url, "https://");    
    return rtrim(ltrimstr($url, "http://"), "/");
}
function ltrimstr($string, $substring){
    return preg_replace('/^'.preg_quote($substring, '/').'/', '', $string);
}
function rtrimstr($string, $substring){
    return preg_replace('/'.preg_quote($substring, '/').'$/', '', $string);
}

function get_base_url($blog_name_or_uuid, $request_type) {
    if(substr($blog_name_or_uuid, 0, 2) == "t:")
        return "https://api.tumblr.com/v2/blog/{$blog_name_or_uuid}/$request_type?";
    else   
        return "https://api.tumblr.com/v2/blog/{$blog_name_or_uuid}.tumblr.com/$request_type?";
}

$dbconn = null;
function log_api_call($status_code, $est_reavailable) {
    global $dbconn, $db_name, $db_user, $db_pass;
    if ($dbconn === null) {
        $connection_string = "dbname=$db_name user=$db_user password=$db_pass";
        $dbconn = pg_connect($connection_string);
        register_shutdown_function(function() use (&$dbconn) {
            if ($dbconn) {
                pg_close($dbconn);
                $dbconn = null;
            }
        });
    }
    if($est_reavailable == null || $est_reavailable == -1) $est_reavailable = "NULL";
    #TODO: make less slow
    $query = "INSERT INTO public.self_api_hist (req_time, response_code, est_reavailable) VALUES (NOW(), $status_code, $est_reavailable);
    REFRESH MATERIALIZED VIEW public.self_api_summary;";
    while($r = pg_get_result($dbconn)) {
        $go = true;
    }
    while(pg_connection_busy($dbconn)) {
        usleep(1000);
    }
    
    pg_send_query($dbconn, $query);
}



function async_call_tumblr($blog_name_or_uuid, $request_type, $params=[], $with_meta = false, $asString = false) {
    global $api_key; global $possible_encodings;
    $params["api_key"] = $api_key;
    $encodedBlogName = urlencode($blog_name_or_uuid);
    $url = get_base_url($blog_name_or_uuid, $request_type).http_build_query($params);
    $result_fiber =  new Fiber(
        function() use ($url, $api_key, $possible_encodings, $with_meta, $asString) {
            $mh = curl_multi_init();  
            $ch = curl_init($url); 
            $headers = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function($curl, $header) use (&$headers) {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if (count($header) < 2) // ignore invalid headers
                    return $len;

                $headers[trim($header[0])] = trim($header[1]);

                return $len;
            });

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_multi_add_handle($mh, $ch);
            
            $count = 5;
            do {
                $status = curl_multi_exec($mh, $active);
                if($active) {
                    $select = curl_multi_select($mh, 0);
                    if ($select === 0) {
                        $count--;
                        if ($count == 0) {
                            $count = 5;
                            Fiber::suspend();
                        }
                    }
                }
            } while ($active && $status == CURLM_OK);

            if ($status != CURLM_OK) {
                throw new Exception("CURL error: " . curl_error($ch));
            }
            $response_s = curl_multi_getcontent($ch);
            
            curl_multi_remove_handle($mh, $ch);
            curl_multi_close($mh);

            $response_j = $response_s;
            $response = json_decode($response_j);
            $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if(json_last_error() & (JSON_ERROR_UTF16 | JSON_ERROR_UTF8 | JSON_ERROR_CTRL_CHAR) > 0) {
                $response_j = ensure_valid_string($response_s);
                if(json_last_error() == JSON_ERROR_CTRL_CHAR) {
                    $response_j = preg_replace('/[[:cntrl:]]/', '', $response_j);
                }
                $response = json_decode($response_j);
            }

            if (json_last_error() !== JSON_ERROR_NONE && $status_code == 200) {
                
                $response = (object)[];
                // Sometimes Tumblr responds with a proper json object, other times with a redirect, and there's no way of knowing when
                $response->meta = parseHeadersToObj($headers); // Use captured headers
            }
            if (isset($headers['Status'])) {
                $status_code = (int) $headers['Status'];
            } else {
                $status_code = $response->meta->status ?? $status_code;
            }

            $est_reavailable = -1;
            if ($status_code == 429) {
                $est_reavailable = determine_reavailability($headers);
            }
            
            log_api_call($status_code, $est_reavailable);

            if($status_code == 500) return null;

            if ($asString) {
                return $response_j;
            }
            if ($with_meta) {
                return $response;
            }
            
            return $response->response;
        }
    );
    return $result_fiber;
}



function call_tumblr($blog_name_or_uuid, $request_type, $params=[], $with_meta = false, $asString = false) {
    global $api_key; global $possible_encodings;
    $options = ['http' => ['ignore_errors' => true]];
    $params["api_key"] = $api_key;
    $encodedBlogName = urlencode($blog_name_or_uuid);
    $url = get_base_url($blog_name_or_uuid, $request_type).http_build_query($params);
    $context = stream_context_create($options);
    $response_s = file_get_contents($url, false, $context);
    $response_j = $response_s;
    $response = json_decode($response_j);
    if(json_last_error() & (JSON_ERROR_UTF16 | JSON_ERROR_UTF8 | JSON_ERROR_CTRL_CHAR) > 0) {
        $response_j = ensure_valid_string($response_s);
        if(json_last_error() == JSON_ERROR_CTRL_CHAR) {
            $response_j = preg_replace('/[[:cntrl:]]/', '', $response_j);
        }
        $response = json_decode($response_j);
    }

    $status_code = 200;
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response = (object)[];
        //sometimes tumblr responds with a proper json object, other times with a redirect, and there's no way of know when it will do which
        $response->meta = parseHeadersToObj($http_response_header);
    }
    $status_code = $response->meta->status;
    $est_reavailable = -1;
    if($status_code == 429) {
        $http_response_header = $http_response_header ?? [];
        $est_reavailable = determine_reavailability($http_response_header);
    }
    
    log_api_call($status_code, $est_reavailable);
    if($asString) {
        return $response_j;
    }
    if($with_meta) {
        return $response;
    }
    
    return $response->response;
}


function fireForgetRequest($params, $endpoint, $payload) {    
    if(is_string($params)) $urlparamsstr = $params;
    else $urlparamsstr = http_build_query($params);
    $fullUrl = "$endpoint?" . $urlparamsstr;    
    $ch = curl_init($fullUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 200);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, 200);
    curl_setopt($ch, CURLOPT_NOSIGNAL, true);

    curl_setopt($ch, CURLOPT_POST, true);
    if($payload != null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    curl_exec($ch);
    curl_close($ch);
}

function determine_reavailability($headers) {
    $resetInSeconds = null;
    foreach ($headers as $header) {
        if (preg_match('/X-Ratelimit-Perday-Remaining: 0/i', $header)) {
            // Day limit hit, calculate seconds until reset
            $dayReset = extract_reset_time($headers, 'X-Ratelimit-Perday-Reset');
            $resetInSeconds = $dayReset > $resetInSeconds ? $dayReset : $resetInSeconds;
        } elseif (preg_match('/X-Ratelimit-Perhour-Remaining: 0/i', $header)) {
            // Hour limit hit, calculate seconds until reset
            $hourReset = extract_reset_time($headers, 'X-Ratelimit-Perhour-Reset');
            $resetInSeconds = $hourReset > $resetInSeconds ? $hourReset : $resetInSeconds;
        }
    }
    return $resetInSeconds;
}

function extract_reset_time($headers, $resetHeader) {
    foreach ($headers as $header) {
        if (strpos($header, $resetHeader) !== false) {
            $parts = explode(':', $header);
            return trim($parts[1]);
        }
    }
    return null;
}







function parseHeadersToObj($http_response_header) {
    $status_code = extractFirstStatusCode($http_response_header);
    $fake_obj = (object)["meta"=> (object)["status" => $status_code]];
    $fake_obj->errors = [];
    if($status_code == 404) {
        $fake_obj->errors[] = (object)["title" => "Not Found"];
    }
    if($status_code == 429) {
        $fake_obj->errors[] = (object)["title" =>  "Limit Exceeded"];
    }
    return $fake_obj;
}



function extractFirstStatusCode($http_response_header) {
    foreach ($http_response_header as $header) {
        $status_code = extractStatus($header);
        if($status_code != null) {
            return $status_code;
        }
    } 
    return 200;
}

function extractStatus($header) {
    if (preg_match('/^HTTP\/\d+\.\d+ (\d+) /', $header, $matches)) {
        $status_code = (int)$matches[1];
        return $status_code;
    } else return null;
}

function execAllSearches($db, $search_array, $match_condition, $exec_params, $notify_only) {
    $multiResults = []; 
    foreach($search_array as $search_item) { 
        $search_query = $search_item->query_text;
        $search_params = $search_item->query_params;
        $blog_uuid = $search_item->blog_uuid;
        $results = $notify_only ? [] : execSearch($db, $search_query, $search_params, $match_condition, $exec_params); 
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
    //$post_check_stmt->debug($exec_params);
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
    
    list($content_weight_string, $meta_weight_string, $stem_weight_string, $filter_string) = parseParams($search_params);
    //if(!$image_only)
    return getPostSearchString($query, $match_condition, $content_weight_string, $meta_weight_string, $stem_weight_string, $filter_string);
    //else 
    //    return getImageSearchString($query, $match_condition);
}

function normalize(&$over_weight) {
    $weightsum = 0.0;
    foreach($over_weight as $k => $v) $weightsum += $v;
    if($weightsum > 0)
        foreach($over_weight as $k => &$v) $v = number_format($v/(float)$weightsum, 6, '.', '');
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
        if($k=="fp_include_reblogs") {
            $filter_assoc["include_reblogs"] = $v;
            continue;
        }
        if($v == false || $v == "false") continue;
        if(in_array($k, $clean_sp))
            $over_assoc[substr($k, 3)] = $v; 
        else if(in_array($k, $clean_fp)) {
            $filter_assoc[ "has_".substr($k, 3)] = $v;
        }
    }
    
    //if(!$over_assoc["sp_self_text"] && !$over_assoc) 
    //$over_fields = [];
    if(isset($over_assoc['image_text'])) {//v3 -> v4
        $over_assoc['self_media'] = $over_assoc['image_text']; 
        $over_assoc['trail_media'] = $over_assoc['image_text']; 
        $over_assoc['trail_usernames'] = $over_assoc['trail_text'];
    }

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
    
    $over_content[] = isset($over_assoc["self_text"]) ? 1.0 : 0.0;
    $over_content[] = isset($over_assoc["self_mentions"]) ? 1.0 : 0.0;  
    $over_content[] = isset($over_assoc["trail_text"]) ? 1.0 : 0.0;
    $over_content[] = isset($over_assoc["trail_mentions"]) ? 1.0 : 0.0;
    
    $over_meta[] = isset($over_assoc["tag_text"]) ? 1.0 : 0.0;
    $over_meta[] = isset($over_assoc["self_media"] )? 1.0 : 0.0;
    $over_meta[] = isset($over_assoc["trail_media"]) ? 1.0 : 0.0;
    $over_meta[] = isset($over_assoc["trail_usernames"]) ? 1.0 : 0.0;
    
    $over_stems[] = isset($over_assoc["self_text"]) ? 1.0 : 0.0;
    $over_stems[] = isset($over_assoc["self_media"]) ? 1.0 : 0.0;
    $over_stems[] = isset($over_assoc["trail_text"]) ? 1.0 : 0.0;
    $over_stems[] = isset($over_assoc["trail_media"]) ? 1.0 : 0.0;
    //$weight_string = "{".implode(", ", $over_weight)."}";
    
    
    normalize($over_content);
    normalize($over_meta);
    $filter_string = isset($filter_assoc["include_reblogs"]) && $filter_assoc["include_reblogs"] == 0 ? "AND is_reblog = false " : "";
    unset($filter_assoc["include_reblogs"]);
    foreach($filter_assoc as $k => $v) {
        if($v == 3) {
            $filter_string .= "AND ($k = '$DENUM[1]' OR $k = '$DENUM[2]' OR $k = 'BOTH')";
        } else {
            $both_cond = $v >= 0 && $v < 3 ? " OR $k = 'BOTH'" : "";
            $filter_string .= "AND ($k = '$DENUM[$v]'$both_cond)";
        }
    }
    $content_weight_string = "ARRAY[".implode(", ", $over_content)."]::float4[]";
    $meta_weight_string = "ARRAY[".implode(", ", $over_meta)."]::float4[]";
    $over_stems_string = "ARRAY[".implode(", ", $over_stems)."]::float4[]";
    return array($content_weight_string, $meta_weight_string, $over_stems_string, $filter_string);
}



function getPostSearchString($query_text, $match_condition="p.blog_uuid = :q_uuid ", $content_weight_string, $meta_weight_string, $stem_weight_string, $filter_string) {
    global $content_text_config;
    $query_text_hun = "websearch_to_tsquery('$content_text_config', '$query_text')";
    $query_text_meta = $query_text_hun;
    $query_text_literal = "websearch_to_tsquery('simple', '$query_text')";
    //$query_text_stem = "websearch_to_tsquery('english_stem_simple', '$query_text')";
    //--// + ts_rank_cd($stem_weight_string, base_results.stems_only) as score
    return "WITH results AS
                (SELECT * FROM 
                    (SELECT 
                        base_results.post_id as post_id_i, 
                        --//c.post_url, 
                        base_results.post_date,
                        base_results.blocks,
                        base_results.tag_text,
                        base_results.is_reblog,
                        base_results.hit_rate,
                        base_results.ts_meta,
                        base_results.ts_content,
                        (   ts_rank_cd($content_weight_string, base_results.ts_content, $query_text_hun, 21)
                         + ts_rank_cd($meta_weight_string, base_results.ts_meta, $query_text_meta, 21)
                         ) as score
                            --// fultext rank codes:
                            --//  1 = divides by 1+logarithm of document length
                            --//  4 = divides by distance between words (mean harmonic length, only compatible with coverage distance ranker ts_rank_cd)
                            --//  16 = log of number of unique words in the document
                        
                         
                    FROM 
                        (".getInnerSearchString($query_text_hun, $query_text_meta, /*$query_text_stem,*/ $query_text_literal, $match_condition, $filter_string).") as base_results
                    ) as scored
                )
            SELECT r.post_id_i::TEXT as post_id, r.*, COALESCE(agg.tags, array_to_json(ARRAY[]::integer[])) as tags,
                        COALESCE(mediaagg.media_info, '[]'::json) media 
            FROM results as r
            LEFT JOIN LATERAL 
                (
                    SELECT 
                        array_to_json(array_agg(t.tag_id)) as tags
                    FROM 
                        posts_tags pt
                    LEFT JOIN 
                        tags t ON pt.tag_id = t.tag_id
                    WHERE 
                        pt.post_id = r.post_id_i
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
                        mp.post_id = r.post_id_i
                    GROUP BY
                        mp.post_id
                ) as mediaagg ON true
            
        ";
}

/**query match for just posts. Useful if you want to posthoc and*/
function getInnerSearchString($tsquery_hun, $tsquery_meta, /*$tsquery_stem,*/ $tsquery_literal, $match_condition="p.blog_uuid = :q_uuid ", $filter_string="") {
    //$tsquery_stem as stem_q,
    $result = "WITH queries AS (
            SELECT $tsquery_hun as en_hun_q, 
            $tsquery_meta as meta_q,            
            $tsquery_literal as literal_q
        )
        SELECT 
            p.post_id, 
            --p.post_url,
            p.post_date, 
            p.blocksb as blocks,
            p.tag_text,
            p.ts_meta,
            p.ts_content,
            p.is_reblog,
            p.hit_rate
        FROM 
            posts p, queries q
        WHERE 
            $match_condition
            AND
            (
                (p.ts_meta || p.ts_content) @@ q.literal_q
                --OR 
                --p.ts_meta @@ q.literal_q
                OR
                --(p.ts_meta || p.ts_content) @@ q.meta_q
                --OR
                (p.ts_meta || p.ts_content) @@ q.en_hun_q
            )
            $filter_string";
             //-- //OR p.stems_only @@ $query_text_stem
    return $result;
}


class Parser {
    private $tokens;
    private $index;
    private $language;

    public function __construct($language = null) {
        global $content_text_config;
        if($language == null) $language = $content_text_config;
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
 * @return object a blogInfo object containing the resolved blog_uuid and name. This should be checked against the one tumblr purported before calling this function
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
function resolve_uuid($db, $blogNameFromUser, $blogInfoFromTumblr, $blogNameFromTumblr) {
    $return_result = (object)[];
    $blogUuidFromTumblr = $blogInfoFromTumblr->uuid; 
    $tumblr_posts_reported = $blogInfoFromTumblr->total_posts;
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
            $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid, serverside_posts_reported) 
                            VALUES (:blog_name, :blog_uuid, :tumblr_posts_reported)");
            $stmt->execute([
                'blog_name' => $blogNameFromTumblr, 
                'blog_uuid' => $blogUuidFromTumblr, 
                "tumblr_posts_reported" => $tumblr_posts_reported]);
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
        } elseif ($existingBlogNameForUuid && !$existingBlogUuidForName) {
            // Situation 2
            $stmt = $db->prepare("UPDATE blogstats 
                        SET blog_name = :blog_name,
                            serverside_posts_reported = :tumblr_posts_reported
                        WHERE blog_uuid = :blog_uuid");
            $stmt->execute([
                'blog_name' => $blogNameFromTumblr, 
                'blog_uuid' => $blogUuidFromTumblr, 
                "tumblr_posts_reported" => $tumblr_posts_reported]);
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
        } elseif ($existingBlogUuidForName == $blogUuidFromTumblr) {
            $return_result->name = $blogNameFromTumblr;
            $return_result->uuid = $blogUuidFromTumblr;
            $update_post_count = $db->prepare("UPDATE blogstats 
                        SET serverside_posts_reported = :tumblr_posts_reported
                        WHERE blog_uuid = :blog_uuid");
            $update_post_count->exec(["blog_uuid" => $blogUuidFromTumblr, "tumblr_posts_reported" => $tumblr_posts_reported]);
        } elseif ($existingBlogUuidForName != NULL && $blogUuidFromTumblr == NULL) {
            // Situation 4
            $new_blogname_info = call_tumblr($existingBlogUuidForName, "info", [], true);
            if($new_blogname_info->meta->status != 200) {
                $error_string = implode("\n", $new_blogname_info->response->errors);
                throw new Exception("Failed to determine new blog_name for a blog name tumblr reports no longer exists. Tumblr says: \"$error_string\"");
            }
            $new_blogInfo = $new_blogname_info->response->blog;
            $new_name = $new_blogInfo->name;
            $change_name = $db->prepare("UPDATE blogstats 
                SET blog_name = :new_name,
                    serverside_posts_reported = :tumblr_posts_reported 
                WHERE blog_uuid = :blog_uuid")->exec(["new_name"=>$new_name, "blog_uuid"=>$existingBlogUuidForName]);
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
                $stmt = $db->prepare("INSERT INTO blogstats (blog_name, blog_uuid, serverside_posts_reported) 
                            VALUES (:blog_name, :blog_uuid, :tumblr_posts_reported)");
                $stmt->execute([
                    'blog_name' => $blogNameFromTumblr, 
                    'blog_uuid' => $blogUuidFromTumblr, 
                    "tumblr_posts_reported" => $tumblr_posts_reported]);
                $return_result->name = $blogNameFromTumblr;
                $return_result->uuid = $blogUuidFromTumblr;
            } else {
                // Situation 5b
                $uuid_end = substr($existingBlogUuidForName, -5);
                $stmt = $db->prepare("UPDATE blogstats SET blog_name = CONCAT(blog_name, '_archfrom_', :uuid_end::text) WHERE blog_uuid = :old_blog_uuid");
                $stmt->execute(['old_blog_uuid' => $existingBlogUuidForName, 'uuid_end' => $uuid_end]);

                $stmt = $db->prepare("UPDATE blogstats 
                    SET blog_name = :blog_name,
                        serverside_posts_reported = :tumblr_posts_reported 
                    WHERE blog_uuid = :blog_uuid");
                $stmt->execute([
                    'blog_name' => $blogNameFromTumblr, 
                    'blog_uuid' => $blogUuidFromTumblr, 
                    "tumblr_posts_reported" => $tumblr_posts_reported]);
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
function ensureSpace($db, $db_blogInfo) {
	global $db_disk;
	global $db_min_disk_headroom;
    global $other_anticipated_posts;
    $blog_uuid = $db_blogInfo->blog_uuid;
	$indexed_post_count = min($db_blogInfo->serverside_posts_reported, $db_blogInfo->indexed_post_count);
	$posts_anticipated = $db_blogInfo?->serverside_posts_reported - $indexed_post_count;
	$averagePostSize = 3300;
	$anticipatedSpaceRequired = $averagePostSize * $posts_anticipated;
    if($other_anticipated_posts == null) {
        $other_anticipated_posts = $db->prepare("SELECT COALESCE(SUM(b.serverside_posts_reported - LEAST(b.indexed_post_count, b.serverside_posts_reported)), 0) 
            FROM blogstats b, archiver_leases l WHERE l.blog_uuid = b.blog_uuid AND l.blog_uuid != :blog_uuid")->exec(['blog_uuid' => $blog_uuid])->fetchColumn();
    }
    $anticipatedSpaceRequired += $averagePostSize * $other_anticipated_posts;
    $free_space = disk_free_space($db_disk);
	$current_wal = $db->query("SELECT wal_bytes FROM pg_stat_wal")->fetchColumn();
	$max_wal = sizeToBytes($db->query("SHOW max_wal_size")->fetchColumn());
    
	$required_wal_headroom = $max_wal;// - $current_wal;
	$free_space -= $required_wal_headroom + $db_min_disk_headroom;
	$anticipated_free_space = $free_space - $anticipatedSpaceRequired;
	return $anticipated_free_space >= 0;
}

/**returns an estimate of the number of posts we have room for */
function estimatePostIngestLimit($db, $free_space_mb = null) {
    $averagePostSize = 0.0033;
    if($free_space_mb == null)
        $free_space_mb = capped_freespace($db);
    return $free_space_mb/$averagePostSize;
}

/**returns the number of bytes of space siikr's db is still allowed to use */
function capped_freespace($db) {
    global $db_disk;
	global $db_min_disk_headroom;
    $free_space = disk_free_space($db_disk);
    $current_wal = $db->query("SELECT wal_bytes FROM pg_stat_wal")->fetchColumn();
	$max_wal = sizeToBytes($db->query("SHOW max_wal_size")->fetchColumn());
	$required_wal_headroom = $max_wal;// - $current_wal;
    $free_space -= $required_wal_headroom + $db_min_disk_headroom;
    return $free_space;
}


function sanitizeParams($paramArr) {
    global $clean_sp;
    global $clean_fp;
    $sanitary = [];
    
    foreach($paramArr as $k => $v) {
        if(in_array($k, $clean_sp) && filter_var($v, FILTER_VALIDATE_BOOLEAN)) {
            $sanitary[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
        }
        else if(in_array($k, $clean_fp) && (int)$v) {
            $sanitary[$k] = $v;
        } else if(in_array($k, $clean_fp) && $k == "fp_include_reblogs") {
            $sanitary[$k] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
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
                            WHERE blog_uuid = :blog_uuid AND deleted = FALSE ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
    $get_post_at_filt_offset = $db->prepare(
                            "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                            WHERE blog_uuid = :blog_uuid AND deleted = FALSE  and post_date >= to_timestamp(:min_date) ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
    $get_fake_post_offset = $db->prepare(
                            "SELECT COUNT(*) FROM posts 
                            WHERE blog_uuid = :blog_uuid AND deleted = FALSE  and post_date < to_timestamp(:max_date) and post_date >= to_timestamp(:min_date)");
    $get_slow_post_offset = $db->prepare(
                            "SELECT COUNT(*) FROM posts 
                            WHERE blog_uuid = :blog_uuid AND deleted = FALSE  and post_date < to_timestamp(:max_date)");
    $get_oldest_post_after = $db->prepare(
                            "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                             WHERE blog_uuid = :blog_uuid AND deleted = FALSE AND post_date > to_timestamp(:min_date) ORDER BY post_date ASC LIMIT 1");
    $verify_offset = $db->prepare(
                        "SELECT post_id as id, EXTRACT(epoch FROM post_date)::INT as timestamp FROM posts 
                         WHERE blog_uuid = :blog_uuid AND deleted = FALSE ORDER BY post_date ASC LIMIT 1 OFFSET :offset");
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



function uuid_create_v4() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
