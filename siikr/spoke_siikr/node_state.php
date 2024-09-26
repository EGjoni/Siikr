<?php

$n_stmts = [];

function maybe_build_n_stmts($db) {
    global $n_stmts;
    if(!isset($n_stmts["get_blogcheck_stats"])) {
        $n_stmts["get_blogcheck_stats"] = $db->prepare(
            "SELECT EXTRACT(EPOCH FROM time_last_indexed) AS time_last_indexed,
            is_indexing, success, indexed_post_count, 
            serverside_posts_reported, index_request_count, 
            smallest_indexed_post_id, largest_indexed_post_id
            FROM blogstats WHERE blog_uuid = :blog_uuid");
    }

}

function build_nodestate_obj($db) {
    global $am_unlimited, $language, $deletion_rate, $n_stmts;
    global $node_in_maintenance_mode; //may be defined in auth/config, 
    //if true, will signal to the hub that this node should not be relied upon for searches
    //unset or set to false again in auth/config when you have finished maintenance.
    $free_space_mb = capped_freespace($db);
    $estimated_remaining_post_capacity = estimatePostIngestLimit($db, $free_space_mb);
    $result = [
        "have_blog" => false,
        "estimated_remaining_post_capacity" => $estimated_remaining_post_capacity,
        "free_space_mb" => $free_space_mb
    ];
    $last_stat_obj = $db->query(
        "SELECT EXTRACT(epoch FROM req_time)::INT as req_time, response_code::INT, est_reavailable FROM self_api_hist ORDER by req_time desc LIMIT 1"
        )->fetch(PDO::FETCH_OBJ);
    $pending_posts = $db->prepare(
        "SELECT SUM(GREATEST(1, bl.serverside_posts_reported - (bl.indexed_post_count*$deletion_rate))) as approximate_pending_post_count
        FROM blogstats bl, archiver_leases al WHERE al.blog_uuid = bl.blog_uuid AND time_last_indexed < now() - INTERVAL '24 hours'")->fetchColumn();;

    $summary_stat_obj = $db->query("SELECT * FROM self_api_summary")->fetch(PDO::FETCH_OBJ);
    $hr_limit = $am_unlimited ? 50000 : 1000;
    $day_limit = $am_unlimited ? 250000 : 5000;
    $est_revailability = null;
    $est_calls_remaining = 1000;
    $hr_calls_remaining = $hr_limit - $summary_stat_obj->requests_this_hour;
    $day_calls_remaining = $day_limit - $summary_stat_obj->requests_today;
    $est_calls_remaining = min($hr_calls_remaining, $day_calls_remaining);
    $result["time_right_now"] = $last_stat_obj->req_time;
    $result["spoke_language"] = $language;
    $result["down_for_maintenance"] = isset($node_in_maintenance_mode) && $node_in_maintenance_mode;

    if($last_stat_obj->response_code == 429) {    
        if($last_stat_obj?->est_reavailable != null) {
            $est_revailability = $last_stat_obj->est_reavailable;
        } else if($summary_stat_obj?->est_reavailable != null) {
            $est_revailability = (int)($summary_stat_obj->est_reavailable);
        } else {
            $est_revailability = 24*60*60;
        }
        $est_revailability = $est_revailability - ((int)time()-$last_stat_obj->req_time);
        if($est_revailability > 0) {
            $result["estimated_reavailability"] = $est_revailability;
            $est_calls_remaining = 0;
        }
    }
    $result["estimated_calls_remaining"] = (int)($est_calls_remaining - ($pending_posts/50));
    return $result;
}

function build_blogstat_obj($db, $blog_uuid) {
    global $n_stmts;
    maybe_build_n_stmts($db);
    $result = [];
    if($blog_uuid != null) {
        try {
            $get_blogstats = $n_stmts["get_blogcheck_stats"];
            $blogstats = $get_blogstats->exec(["blog_uuid" => $blog_uuid])->fetch(PDO::FETCH_OBJ);
            if($blogstats != false) {
                $response_info = [ 
                    "time_last_indexed" => $blogstats->time_last_indexed,
                    "is_indexing" => $blogstats->is_indexing === false ? 'FALSE' : 'TRUE',
                    "success" => $blogstats->success  === false ? 'FALSE' : 'TRUE',
                    "indexed_post_count" => $blogstats->indexed_post_count,
                    "serverside_posts_reported" => $blogstats->serverside_posts_reported,
                    "index_request_count" => $blogstats->index_request_count,
                    "largest_indexed_post_id" => $blogstats->largest_indexed_post_id,
                    "smallest_indexed_post_id" => $blogstats->smallest_indexed_post_id
                ];
                $result["blogstat_info"] = $response_info;
            
                $result["have_blog"] = true;
            }
        } catch(Exception $e) {
            $result["need_help"] = true; 
        }
    }
    return $result;
}