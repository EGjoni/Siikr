<?php
require_once 'internal/globals.php';
header('Content-Type: application/json');
ob_start('ob_gzhandler');

//$db_user = "www-data";
$blog_uuid = $_GET['blog_uuid'];
$limit = $_GET["limit"] ?? 100;

if ($blog_uuid == null) {
	$userInfo = posix_getpwuid(posix_geteuid());
	//$db_user = $userInfo["name"];
}
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$blog_name = null;
if(isset($argc)) 
	$blog_name = $argc > 1 ? $argv[1] : null;
if ($blog_uuid == null) {
	$blog_uuid = $db->prepare("SELECT blog_uuid FROM blogstats WHERE blog_name = :blog_name")->exec(["blog_name" => $blog_name])->fetchColumn();
}

$stmt_select_blog = $db->prepare("SELECT blog_uuid, post_count_at_stat::BIGINT, last_stats_update FROM wordclouded_blogs WHERE blog_uuid = ?");
$blog_info = $stmt_select_blog->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
if($blog_info != null)
	$user_posts_on_stat = $blog_info->post_count_at_stat;

$fingerprint = (object) [];
if ($blog_info->last_stats_update == null) {
	$fingerprint->unavailable = true;
	echo json_encode($fingerprint);
	flush();
} else {
    $all_lexeme_appearances = $db->prepare("SELECT SUM(global_ndocs) FROM lexemes")->exec([])->fetchColumn();
	$word_estimator = $db->prepare(
		"SELECT SUM(lbe.nentry)::FLOAT FROM lexeme_blogstats_english lbe
    WHERE lbe.blog_uuid = :blog_uuid"
	)->exec(["blog_uuid" => $blog_info->blog_uuid], true);
	$estimated_total_user_words = (float) $word_estimator->fetchColumn();

	
/* true percentile driven implementation. . . slow
	 " WITH bl AS (
		SELECT
			lexeme,
			lexeme_id,
			post_freq, post_freq_total
		FROM
			lexeme_blogstats_english lbei,
			lexemes l
		WHERE
			lbei.lexeme_id = l.id
			AND lbei.blog_uuid = 't:4rirVWD0kanLaxYPga1vNg'
		ORDER BY
			lbei.post_freq / l.avg_post_freq DESC
		LIMIT 100
		)
		SELECT
		blbase.lexeme,
		lbe.lexeme_id,
		(100*SUM(lbe.post_freq)/blbase.post_freq_total) as percentile
		FROM
		bl AS blbase,
		lexeme_blogstats_english AS lbe
		WHERE
		blbase.lexeme_id = lbe.lexeme_id
		AND lbe.post_freq < blbase.post_freq
		GROUP BY
		lbe.lexeme_id, blbase.lexeme, blbase.post_freq_total
		ORDER BY
		percentile DESC"
*/
	$stats_str =
		"SELECT * FROM (SELECT
    i.lexeme, i.nentry, i.ndoc,
    (i.post_freq - avg_post_freq)/std_dev_post_freq as appearance_z_score,
    cdf_gaussian_p((i.post_freq - avg_post_freq)/std_dev_post_freq, 1) as percentile,
    rdf_binomial(i.ndoc::INT, i.avg_post_freq::FLOAT, :user_posts::INT) as trial_prob,
    cdf_binomial_p(i.ndoc::INT, i.avg_post_freq::FLOAT, :user_posts::INT) as trial_prob_cumm,
    cdf_hypergeometric_p(i.ndoc::INT, i.global_ndocs::INT, :all_global_lexeme_appearances::INT - i.global_ndocs::INT, :user_posts::INT) as hyper_geom_cum,
    --nentry::FLOAT/ndoc::FLOAT as tfpd,
    post_freq/avg_post_freq as rel,
    post_freq,
    blog_freq,
    avg_post_freq,
    avg_blog_freq,
    std_dev_post_freq,
    std_dev_blog_freq,
    global_nentries,
    global_ndocs
FROM (SELECT
        l.id AS lexeme_id,
        l.lexeme,
        COALESCE(lbe.nentry, 0)::BIGINT AS nentry,
        COALESCE(lbe.ndoc, 0)::BIGINT AS ndoc,
        COALESCE(lbe.post_freq, 0.0001/:user_posts::FLOAT) AS post_freq,
        COALESCE(lbe.blog_freq, 0.0001/:total_words::FLOAT) AS blog_freq,
        l.avg_post_freq,
        l.avg_blog_freq,
        l.std_dev_post_freq,
        l.std_dev_blog_freq,
        l.global_nentries::BIGINT,
        l.global_ndocs::BIGINT
    FROM lexemes l
    LEFT JOIN lexeme_blogstats_english lbe
    ON l.id = lbe.lexeme_id AND lbe.blog_uuid = :blog_uuid
    ) as i
    WHERE i.ndoc::FLOAT/i.global_ndocs::FLOAT <= :global_rat_threshold
    AND i.ndoc > :min_appearance_threshold
    AND i.global_ndocs >= :min_global_appearance_threshold
    ) as o
    WHERE o.appearance_z_score * :dir > 0
";

	$stats_positive = $db->prepare($stats_str . " ORDER BY appearance_z_score desc, percentile desc, o.trial_prob asc, o.rel desc LIMIT $limit");
	$positiveUses = $stats_positive->exec(
		[
			"blog_uuid" => $blog_uuid,
			"user_posts" => $user_posts_on_stat,
			"total_words" => $estimated_total_user_words,
			"all_global_lexeme_appearances" => $all_lexeme_appearances,
			"global_rat_threshold" => 1 / 7.0, //filter out words almost no one but the user has used
			"min_appearance_threshold" => 2, //filter out words the user hasn't used enough to give much signal
			"min_global_appearance_threshold" => 7, //filter out words likely to have very few other users
			"dir" => 1,
			// "order_by" => "appearance_z_score",
			//"_limit"=>1050
		], true
	)->fetchAll(PDO::FETCH_OBJ);

	$stats_negative = $db->prepare($stats_str . " ORDER BY percentile asc, o.trial_prob asc, o.rel asc LIMIT $limit");
	$negativeUses = $stats_negative->exec(
		[
			"blog_uuid" => $blog_uuid,
			"user_posts" => $user_posts_on_stat,
			"total_words" => $estimated_total_user_words,
			"all_global_lexeme_appearances" => $all_lexeme_appearances,
			"global_rat_threshold" => 1, //filter out words almost no one but the user has used
			"min_appearance_threshold" => -1, //filter out words the user hasn't used enough to give much signal
			"min_global_appearance_threshold" => 7, //filter out words with very few users
			"dir" => -1,
			// "order_by" => "appearance_z_score",
			//"_limit"=>1050
		], true
	)->fetchAll(PDO::FETCH_OBJ);
}



/**
$stmt_select_blog = $db->prepare("SELECT * FROM blogstats WHERE blog_uuid = ?");
$blog_info = $stmt_select_blog->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
$fingerprint = (object)[];
if($blog_info->last_stats_update == null) {
$fingerprint->unavailable = true;
echo json_encode($fingerprint);
flush();
} else {

$stats_str = "SELECT
l.lexeme, lbe.nentry, lbe.ndoc,
(lbe.post_freq - l.avg_post_freq)/l.std_dev_post_freq as appearance_z_score,
lbe.nentry::FLOAT/lbe.ndoc::FLOAT as tfpd,
lbe.post_freq/l.avg_post_freq as rel,
lbe.post_freq,
l.avg_post_freq,
l.avg_blog_freq,
l.std_dev_post_freq,
l.global_nentries,
l.global_ndocs
FROM
lexeme_blogstats_english AS lbe,
lexemes AS l
WHERE
l.id = lbe.lexeme_id AND lbe.blog_uuid = :blog_uuid
AND lbe.ndoc::FLOAT/l.global_ndocs::FLOAT < :global_rat_threshold
AND ndoc > :min_appearance_threshold --for positive case only
";

$ord = "DESC";
$stats_positive = $db->prepare("$stats_str ORDER BY appearance_z_score DESC LIMIT :limit");
$postiveUses = $stats_positive->exec(
[
"blog_uuid" => $blog_uuid,
"global_rat_threshold" => 1.0/7.0, //filter out words almost no one but the user has used
"min_appearance_threshold" => 2, //filter out words the user hasn't used enough to give much signal
//"order_by" => "appearance_z_score",
"limit"=>100
], true
)->fetchAll(PDO::FETCH_OBJ);

$word_estimator = $db->prepare(
"SELECT SUM(lbe.nentry)::FLOAT FROM lexeme_blogstats_english lbe
WHERE lbe.blog_uuid = :blog_uuid"
)->exec(["blog_uuid"=>$blog_info->blog_uuid], true);
$estimated_total_user_words = (float)$word_estimator->fetchColumn();

$stats_negative = $db->prepare("SELECT rev.* FROM ($stats_str ORDER BY appearance_z_score ASC LIMIT :limit) as rev ORDER BY rev.appearance_z_score DESC");
$negativeUses = $stats_negative->exec(
[
"blog_uuid" => $blog_uuid,
"global_rat_threshold" => 1.0/7.0, //filter out words almost no one but the user has used
"min_appearance_threshold" => -1, //filter out words the user hasn't used enough to give much signal
// "order_by" => "appearance_z_score",
"limit"=>100
], true
)->fetchAll(PDO::FETCH_OBJ);
}
 **/

$fingerprint->blog_uuid = $blog_uuid;
$fingerprint->estimated_total_words = $estimated_total_user_words;
$fingerprint->total_posts_on_stat = $blog_info->post_count_at_stat;
$fingerprint->overused = $positiveUses;
if(!$negativeUses) $negativeUses = [];
$fingerprint->underused = array_reverse($negativeUses);
$fingerprint->perf = (object) [];
//$fingerprint->perf->post_counter = $post_counter->execution_time;
$fingerprint->perf->word_estimation = $word_estimator->execution_time;
$fingerprint->perf->pos_query = $stats_positive->execution_time;
$fingerprint->perf->neg_query = $stats_negative->execution_time;

echo json_encode($fingerprint);
flush();
