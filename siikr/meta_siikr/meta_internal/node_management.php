<?php
$all_nodes_prep = $db->prepare("SELECT * FROM siikr_nodes WHERE down_for_maintenance = false");
$all_nodes = $all_nodes_prep->exec([])->fetchAll(PDO::FETCH_OBJ); 
$insert_into_nodemap = $db->prepare(
    "INSERT INTO blog_node_map 
            (blog_uuid, node_id, is_indexing, success,
            indexed_posts, last_index_count_modification_time, 
            smallest_indexed_post_id, largest_indexed_post_id
            )
        VALUES (:blog_uuid, :node_id, :is_indexing, :success,
            :indexed_posts, :last_index_count_modification_time,
            :smallest_indexed_post_id, :largest_indexed_post_id)"); 

$update_blog_nodemap_stats = $db->prepare(
    "UPDATE blog_node_map  
        SET last_index_count_modification_time = 
            CASE
                WHEN indexed_posts = :indexed_posts 
                THEN last_index_count_modification_time -- only change if the index_count changes
                ELSE now()
            END
            indexed_posts = :indexed_posts, 
            success = :success,
            is_indexing = :is_indexing,
            largest_indexed_post_id = :largest_indexed_post_id,
            smallest_indexed_post_id = :smallest_indexed_post_id
        WHERE blog_uuid = :blog_uuid,
            node_id = :node_uuid"
);

$upsert_blog_nodemap_stats = $db->prepare(
    "INSERT INTO blog_node_map 
        (blog_uuid, node_id, is_indexing, success, indexed_posts, largest_indexed_post_id, smallest_indexed_post_id, last_index_count_modification_time)
    VALUES 
        (:blog_uuid, :node_id, :is_indexing, :success, :indexed_posts, :largest_indexed_post_id, :smallest_indexed_post_id, now())
    ON CONFLICT (blog_uuid, node_id)  -- only change if the index_count changes
    DO UPDATE SET 
        is_indexing = EXCLUDED.is_indexing,
        success = EXCLUDED.success,
        indexed_posts = EXCLUDED.indexed_posts,
        largest_indexed_post_id = EXCLUDED.largest_indexed_post_id,
        smallest_indexed_post_id = EXCLUDED.smallest_indexed_post_id,
        last_index_count_modification_time = CASE
            WHEN blog_node_map.indexed_posts = EXCLUDED.indexed_posts THEN blog_node_map.last_index_count_modification_time
            ELSE now()
        END"
);

$update_nodeStats = $db->prepare(
    "UPDATE siikr_nodes SET
        last_pinged_ourtime = now(),
        last_pinged_nodetime = to_timestamp(:node_ping_time),
        free_space_mb = :free_space_mb,
        total_space_mb = :total_space_mb, 
        node_name = :node_name,
        node_flare = :node_flare,
        node_notice = :node_notice,
        estimated_calls_remaining = :estimated_calls_remaining,
        reliability = LEAST(reliability, :reliability),
        node_language = :lang,
        down_for_maintenance = :down_for_maintenance
    WHERE node_id = :node_id"
);

$remove_nodecache = $db->prepare( "DELETE FROM cached_blog_node_map WHERE node_id = :node_id");

/**
 * updates the blogs_node_map table to include an entry from the given blog to the given node
 */
function registerToBlogNodeMap($blog_uuid, $node) {
    global $insert_into_nodemap; 
    global $upsert_blog_nodemap_stats;
    global $update_nodeStats;

    $upsert_blog_nodemap_stats->exec([
        "blog_uuid" => $blog_uuid,
        "node_id" =>  $node->node_id,
        "success" => $node?->success ?? false ? "TRUE" : "FALSE" , 
        "is_indexing" => $node?->is_indexing ?? false ? "TRUE" : "FALSE" ,
        "largest_indexed_post_id" => $node->largest_indexed_post_id,
        "smallest_indexed_post_id" => $node->smallest_indexed_post_id,
        "indexed_posts" => $node->indexed_post_count
    ]);
}

function updateNodeStats($node) {
    global $update_nodeStats, $remove_nodecache;
    $update_nodeStats->exec([
        "node_id" => $node->node_id, 
        "free_space_mb" => $node->free_space_mb,
        "node_name" => $node?->node_name,
        "node_flare" => $node?->node_flare,
        "node_notice" => $node?->node_notice,
        "total_space_mb" => $node?->total_space_mb,
        "reliability" => $node->reliability,
        "estimated_calls_remaining" => (int)$node->estimated_calls_remaining,
        "node_ping_time" => $node->time_right_now,
        "lang" => $node->spoke_language ?? 'en',
        "down_for_maintenance" => isset($node->down_for_maintenance) && $node->down_for_maintenance ? 'TRUE' : 'FALSE'
    ]);
    if($node->down_for_maintenance) {
        $remove_nodecache->exec(["node_id" => $node_id]);
    }
}


function multiCall($urls, $notify_response_update, &$all_responses) {
    $mh = curl_multi_init();
    $curlArray = [];
    foreach ($urls as $i => $url) {
        $curlArray[$i] = curl_init($url);
        curl_setopt($curlArray[$i], CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlArray[$i], CURLOPT_TIMEOUT, 5);
        curl_setopt($curlArray[$i], CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curlArray[$i], CURLOPT_FOLLOWLOCATION, 5);
        curl_multi_add_handle($mh, $curlArray[$i]);
    }
    $active = null;
    do {
        while (($mrc = curl_multi_exec($mh, $active)) == CURLM_CALL_MULTI_PERFORM);
        if ($mrc != CURLM_OK) break;
        $new_results = [];        
        while ($done = curl_multi_info_read($mh)) {
            $info = curl_getinfo($done['handle']);
            $res = [];
            if ($done['result'] == CURLE_OK) {
                $res["status"] = "OK";
                $res["response"] = curl_multi_getcontent($done['handle']);
            } else {
                $res["status"] = "Error";
                $res["response"] = curl_error($done['handle']);
            }
            $res["url"] = $info['url'];
            $all_responses[] = $res;
            $new_results[] = $res;
            
            curl_multi_remove_handle($mh, $done['handle']);
            curl_close($done['handle']);
        }
        // Block for data in / output;
        if(count($new_results)>0)
            $notify_response_update($new_results);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active);
    curl_multi_close($mh); // Close handles
}





/**asks all nodes if they have the blog. 
 * Secretly judges their responses to determine if any are truly worthy.
 * Returns the chosen one.
*/
function askAllNodes($blog_uuid, $blog_info=null) {
    global $all_nodes;
    $url_list = []; 
    $nodes_by_url = [];
    foreach($all_nodes as $node) {
        $blogcheck_url = "$node->node_url/spoke_siikr/blog_check.php?blog_uuid=$blog_info->blog_uuid";
        $node->blogcheck_url = $blogcheck_url;
        $nodes_by_url[$blogcheck_url] = $node;
    }
    $url_list = array_keys($nodes_by_url);
    $all_responses = [];
    $nodes_by_id = [];
    $hosting_nodes = []; // contains nodes that purport to host the blog
    $available_nodes = []; // contains nodes that appear to be alive
    

    $processNodeResponse = function($new_results) use (&$nodes_by_url, &$available_nodes, &$hosting_nodes, &$blog_info, &$blog_uuid) {
        foreach($new_results as $result) {
            $node = $nodes_by_url[$result["url"]];
            $json_result = json_decode($result["response"], true);
            if($result["status"] == "Error") {
                $node->reliability_boost -=0.125;
            } else if(!$json_result["down_for_maintenance"]) {
                $node->reliability_boost +=0.06125;
                $available_nodes[] = $node;
            }
            $nodes_by_id[$node->node_id] = $node;
            foreach($json_result as $k => $v) if($k != "blogstat_info") $node->$k = $v;
            updateNodeStats($node);
            if($json_result["have_blog"] && isset($json_result["blogstat_info"])) {
                foreach($json_result["blogstat_info"] as $k => $v) {
                    $node->$k = $v;
                }
                if(property_exists($blog_info, "blog_uuid") && $blog_info->blog_uuid != null) {
                    registerToBlogNodeMap($blog_info->blog_uuid, $node);
                    $hosting_nodes[] = $node;
                }
            }
        }
    };

    multiCall($url_list, $processNodeResponse, $all_responses);
    $bestNode = null;
    foreach($available_nodes as $an) {
        if(!isset($an->indexed_post_count)) {
            $an->indexed_post_count = 0;
        }
    } 
    /*if(count($hosting_nodes) > 0) {
        $bestNode = tieBreaker($hosting_nodes, $blog_uuid, $blog_info);
    }
    if($bestNode == null || $bestNode->indexed_post_count == 0) {*/
        $bestNode = tieBreaker($available_nodes, $blog_uuid, $blog_info);
    //}
    return $bestNode;
}

$cachedNode_upsert = $db->prepare(
    "INSERT INTO cached_blog_node_map 
        (blog_uuid, node_id, established, last_interaction)
    VALUES 
        (:blog_uuid, :node_id, now(), now())
    ON CONFLICT (blog_uuid)  -- only change if the index_count changes
    DO UPDATE SET 
        node_id = EXCLUDED.node_id,
        last_interaction = now(),
        established = CASE
            WHEN cached_blog_node_map.node_id = EXCLUDED.node_id THEN cached_blog_node_map.established
            ELSE now()
        END"
); 

function cacheBestNode($blog_uuid, $node) {
    global $cachedNode_upsert;
    if($node?->down_for_maintenance) {

    } else {
        $cachedNode_upsert->exec([
            "blog_uuid" => $blog_uuid, 
            "node_id" => $node->node_id
        ]);    
    }
}

$cached_prep = $db->prepare(
    "SELECT 
        sn.node_id,
        sn.node_url,
        sn.free_space_mb,
        sn.total_space_mb,
        sn.node_name,
        sn.node_flare,
        EXTRACT(epoch FROM sn.last_pinged_ourtime)::INT as last_pinged_ourtime,
        EXTRACT(epoch FROM sn.last_pinged_nodetime)::INT as last_pinged_nodetime,
        EXTRACT(epoch FROM bnm.last_index_count_modification_time)::INT as last_index_count_modification_time,
        bnm.success, bnm.is_indexing, bnm.indexed_posts as indexed_post_count,
        sn.estimated_calls_remaining,
        sn.reliability,
        sn.down_for_maintenance
    FROM cached_blog_node_map cbnm, 
        blog_node_map bnm, 
        siikr_nodes sn 
    WHERE cbnm.blog_uuid = :blog_uuid 
        AND sn.node_id = cbnm.node_id 
        AND bnm.node_id = cbnm.node_id 
        AND bnm.blog_uuid = cbnm.blog_uuid
        AND sn.down_for_maintenance = false");
$known_prep = $db->prepare(
    "SELECT
        bnm.success,
        bnm.is_indexing,
        EXTRACT(epoch FROM bnm.last_index_count_modification_time)::INT as last_index_count_modification_time,
        bnm.indexed_posts as indexed_post_count, 
        sn.node_url,
        sn.free_space_mb,
        sn.total_space_mb,
        sn.node_name,
        sn.node_flare,
        EXTRACT(epoch FROM last_pinged_ourtime)::INT as last_pinged_ourtime,
        EXTRACT(epoch FROM last_pinged_nodetime)::INT as last_pinged_nodetime,
        sn.estimated_calls_remaining,
        sn.reliability,
        sn.node_id,
        sn.down_for_maintenance
    FROM blog_node_map bnm, siikr_nodes sn 
    WHERE bnm.blog_uuid = :blog_uuid 
    AND bnm.node_id = sn.node_id");

/**Checks to see if we know of a node that already has a bunch of posts archived from this blog. This is intended for a quick initial search. Afterward, a call should be made to 'findBestArchiverNode' to determine if the blog will need to be transferred over to a seperate node for archiving.
* Attempts to return the best node for the job if we do returns null if no nodes no of the blog or none are viable
*/
function findBestSearchNode($blog_uuid, $blog_info=null) {
    global $checkinQueue;
    global $cached_prep;
    global $known_prep;
    global $deletion_rate;
              
    $known_hosts = $known_prep->exec(["blog_uuid"=>$blog_uuid])->fetchAll(PDO::FETCH_OBJ);
    $best_search_node = $known_hosts[0];
    if($best_search_node == false) $best_search_node = null;
    foreach($known_hosts as $host) {
        if($host->indexed_post_count > $best_search_node->indexed_post_count)
            $best_search_node = $host;
    }
    return $best_search_node;
}

/**Checks to see if we know of a node that already has a bunch of posts archived from this blog. This is intended for a quick initial search. Afterward, a call should be made to 'findBestArchiverNode' to determine if the blog will need to be transferred over to a seperate node for archiving.
* Attempts to return the best node for the job if we do returns null if no nodes no of the blog or none are viable
*/
function findBestArchivingNode($blog_uuid, $blog_info=null) {
    global $checkinQueue;
    global $cached_prep;
    global $known_prep;
    global $deletion_rate;
    
    $cached_node = $cached_prep->exec(["blog_uuid"=>$blog_uuid])->fetch(PDO::FETCH_OBJ);
    if($cached_node != false) {
        $cached_node->from_cache = true;
        return $cached_node;
    } else {        
        $known_hosts_init = $known_prep->exec(["blog_uuid"=>$blog_uuid])->fetchAll(PDO::FETCH_OBJ);
        if($known_hosts_init) {
            $known_hosts = [];
            foreach($known_hosts_init as $host) {
                if($host->down_for_maintenance == false)
                    $known_hosts[] = $host;
                $host->indexed_post_count *= $deletion_rate;
            }

            if(count($known_hosts) == 1) {
                if(property_exists($known_hosts[0], "indexed_post_count") && $known_hosts[0]->indexed_post_count > 0) {
                    $known_hosts[0]->indexed_post_count /= $deletion_rate;
                    return $known_hosts[0];
                }
                else return null;
            } 
            if(count($known_hosts) > 0) {
                $bestCandidate = tieBreaker($known_hosts, $blog_uuid, $blog_info);
                if($bestCandidate != null) {
                    $bestCandidate->indexed_post_count /= $deletion_rate;
                    return $bestCandidate;
                }
            }
        }
        return null;
    }
}


/**
 * given multiple hosts, determines which one should perform the search based 
 * solely on capacity, 
 * indexed_post_count, 
 * and reliability score  
 * //TODO: make it not just be a random number
 **/
function tieBreaker($hostList, $blog_uuid, $blogInfo=null) {
    global $checkinQueue;
    
    $viableCandidates = [];
    foreach($hostList as $host) {
        if($host->reliabiltiy < 0 ) {
            $checkinQueue[] = $host; 
            continue; //spmethine has gone wrong to get a score this low
        }
        if(property_exists($host, "is_indexing")) {
            if(!$host->is_indexing && !$host->success) { //if the host failed, stop relying on it
                $checkinQueue[] = $host; 
                continue;
            } else if ($host->is_indexing 
            &&  ($host->indexed_post_count - $host->prior_indexed_post_count) == 0
            && ($host->last_index_update - $host->second_to_last_update) > 5) {
                $checkInQueue[] = $host; //took too long. no good.
                continue;
            }
        }
        if($host->estimated_calls_remaining <= 0) { //this host can't interact with tumblr for a while
            continue;
        }
        $score = 1.0+((float)$host->reliabiltiy/10.0);
        if(property_exists($host, "indexed_post_count")) { 
            $posts_indexed = 1+(float)($host->indexed_post_count ?? 0);
            $posts_existing = (isset($blogInfo) ? (float)$blogInfo->posts : 1);
            $score *= (float)$posts_indexed / (float)$posts_existing; 
            $estimated_calls_required = max(0.02, 0.1+((float)($posts_existing - $posts_indexed)/50.0));
            //prefer not to use hosts that don't have very many calls left
            $score *= $host->estimated_calls_remaining/($estimated_calls_required);
        }
        $score *= (float)$host->free_space_mb;
        $host->score = $score;
        $viableCandidates[] = $host;
    }
    if(count($viableCandidates) == 0 ) return null; 
    else {
        $bestCandidate = $viableCandidates[0];
        foreach ($viableCandidates as $candidate) {
            if($candidate->score > $bestCandidate->score) 
                $bestCandidate = $candidate;
        }
        return $bestCandidate;
    } 
}


/**
 * @param payload is optional. if provided, we expect a php object which gets encoded as json to send as a post parameter to the endpoint
 */
function forwardRequest($params, $endpoint, $node, $payload = null, $streaming = true) {
    if(is_string($params)) 
        $urlparamsstr = $params;
    else 
        $urlparamsstr = http_build_query($params);
    $fullUrl = $node->node_url . "/$endpoint?" . $urlparamsstr;

    if($streaming == false) {
        $http_params = ['ignore_errors' => true];
        if($payload != null) {
            $http_params = [
                'method' => 'POST',
                'header' => 'Content-Type:application/json',
                'content' => json_encode($payload),              
                'ignore_errors' => true
            ];
        }
        $options = ['http' => $http_params];
        $context = stream_context_create($options);
        $response = file_get_contents($fullUrl, false, $context);
        echo $response;
        ob_flush();
        flush();
        ob_clean();
    } else {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        //curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 500);
        ob_implicit_flush(true);
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
            echo $data;
            //ob_flush();      
            flush();
            //ob_clean();
            return strlen($data);
        });
        if($payload != null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
        //curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);  //for streaming the response directly
        
        /*ob_implicit_flush(true);
        $counts = 0;
        while (@ob_end_flush()) {
            $counts++;
        };*/
        
        curl_exec($ch);
        curl_close($ch);
    }
    ob_end_clean();
}


$clean_stale_cache = $db->prepare(
    "DELETE FROM cached_blog_node_map WHERE last_interaction < NOW() - INTERVAL '6 hours'"
);
function cleanStaleCacheEntries() {
    global $clean_stale_cache;
    $clean_stale_cache->exec([]);
}
