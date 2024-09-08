<?php
require_once '../internal/globals.php';
//require_once '../internal/math.php';

$userInfo = posix_getpwuid(posix_geteuid());
$db = new SPDO("pgsql:dbname=$db_name", $pg_user, $pg_pass);




function repair_totals($db) {
    $total_blogs = $db->prepare("SELECT COUNT(*) FROM wordclouded_blogs")->exec([])->fetchColumn();
    //$global_total_posts = $db->prepare("SELECT SUM(post_count_at_stat) FROM wordclouded_blogs")->exec([])->fetchColumn();

    try { 
        $db->beginTransaction(); 
        echo("updating counts and freq totals\n");
        $update_global_sums_stmt = $db->prepare(
            "WITH lbe_sums AS (
            SELECT 
                lexeme_id,
                SUM(nentry) as nentries,
                SUM(ndoc) as ndocs,
                SUM(post_freq) as post_freq_sum,
                SUM(blog_freq) as blog_freq_sum
            FROM lexeme_blogstats_english 
            GROUP BY lexeme_id
            )
            UPDATE lexemes SET 
                global_nentries = lbe_sums.nentries,
                global_ndocs = lbe_sums.ndocs, 
                post_freq_total = lbe_sums.post_freq_sum,
                blog_freq_total = lbe_sums.blog_freq_sum,
                blogs_considered = :total_blogs
            FROM lbe_sums
            WHERE lbe_sums.lexeme_id = id 
        ")->exec(["total_blogs" => $total_blogs]);

        echo("Done. \nUpdating freq averages\n");

        $update_means_stmt =
        $db->prepare("UPDATE lexemes SET 
            avg_post_freq = post_freq_total / blogs_considered,
            avg_blog_freq = blog_freq_total / blogs_considered
        ")->exec([]);

        echo("Done. \nUpdating variances totals and std deviations\n");
        $update_std_devs = $db->prepare(
        "WITH varsums AS 
            (SELECT varsq.lexeme_id, SUM(varsq.post_sq_dist) as post_variance_total, SUM(varsq.blog_sq_dist) as blog_variance_total
                FROM (
                    SELECT lbe.lexeme_id, 
                        POWER(COALESCE(lbe.post_freq, 0) - l.avg_post_freq, 2) as post_sq_dist, 
                        POWER(COALESCE(lbe.blog_freq, 0) - l.avg_blog_freq, 2) as blog_sq_dist
                    FROM lexemes l
                    LEFT JOIN lexeme_blogstats_english lbe ON l.id = lbe.lexeme_id) as varsq
                GROUP BY varsq.lexeme_id
            )
            UPDATE lexemes SET 
                post_variance_total = varsums.post_variance_total,
                blog_variance_total = varsums.blog_variance_total,
                std_dev_blog_freq = SQRT(varsums.blog_variance_total/lexemes.blogs_considered),
                std_dev_post_freq = SQRT(varsums.post_variance_total/lexemes.blogs_considered)
            FROM varsums
            WHERE varsums.lexeme_id = lexemes.id
        ")->exec([]); 
        $db->commit(); 
    } catch(PDDOException $e) {
        $db->commit();
    }

    echo("\n\n DONE! \n\n");
}

function repairFreqs($db, $range=500, $startOn = 0) {
    global $fixer; 
    global $cursor;
    global $db_name;
    global $userInfo;

    $fixer = $db->prepare(
        "UPDATE lexeme_blogstats_english 
        SET post_freq = :post_freq 
        WHERE lexeme_id = :lexeme_id
        AND blog_uuid =  :blog_uuid");
    
    $cursor = $db->prepare(
        "SELECT * FROM 
            (select da.expect_freq, lbe.* 
                FROM delete_me_after da, lexeme_blogstats_english lbe 
                WHERE da.blog_uuid = lbe.blog_uuid 
                AND da.lexeme_id = lbe.lexeme_id)
             as res 
        WHERE res.expect_freq != res.post_freq", 
        array(\PDO::ATTR_CURSOR => \PDO::CURSOR_SCROLL))->exec([]);

    $total = 1693793; //$db->prepare("SELECT COUNT(*) FROM delete_me_after nu")->exec([])->fetchColumn();
    $loop = $startOn;
    $pos = $startOn;


    $pg_conn = pg_connect("dbname=$db_name user=".$userInfo['name']);
   
    do {
        //$fixer->exec([$pos, $range]);
        $row = $cursor->fetch(PDO::FETCH_OBJ);
        if(!$row)
            break;

        $query = "UPDATE lexeme_blogstats_english SET post_freq = {$row->expect_freq} WHERE lexeme_id = {$row->lexeme_id} AND blog_uuid = '{$row->blog_uuid}'";
        @pg_send_query($pg_conn, $query);
        //$fixer->exec(["post_freq" => $row->expect_freq, "blog_uuid" => $row->blog_uuid, "lexeme_id" => $row->lexeme_id]);
        if($loop % $range == 0) {
            echo "($pos/$total) ";
            
            while ($result = pg_get_result($pg_conn)) {
                if (pg_result_status($result) != PGSQL_COMMAND_OK) {
                    echo "Update failed: " . pg_last_error($pg_conn);
                }
            }
            echo round(100*$pos/$total, 6)."%  ('$row->blog_uuid', $row->lexeme_id  ->  from: $row->expect_freq to $row->post_freq\n";
        }
        $loop++; 
        $pos = $loop;//$startOn + ($loop*$range);
        
    }
    while($pos < $total);
}


$range = $argc > 1 ? $argv[1]: 500;
$offset = $argc > 2 ? $argv[2]: 0;


//repairFreqs($db, $range, $offset);
repair_totals($db);

//updatePostcountStats($db, $temp_summary_blogstats);
//updateSummaryStats($db, $temp_summary_blogstats);
//updateGlobalSummaryStats($db, $temp_summary_blogstats);

//create_all_lexeme_stats($db, $start_from);
