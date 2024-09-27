<?php
require_once 'globals.php';
require_once 'math.php';

if ($blog_uuid == null) {
	$userInfo = posix_getpwuid(posix_geteuid());
	$db_user = $userInfo["name"];
}
$db = getDb();//new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
$blog_uuid = $_GET['blog_uuid'];
$blog_name = $argc > 1 ? $argv[1] : "askagni";
if ($blog_uuid == null) {
	$blog_uuid = $db->prepare("SELECT blog_uuid FROM blogstats WHERE blog_name = :blog_name")->exec(["blog_name" => $blog_name])->fetchColumn();
}

$stmt_select_blog = $db->prepare("SELECT blog_uuid, post_count_at_stat::BIGINT, last_stats_update FROM wordclouded_blogs WHERE blog_uuid = ?");
$blog_info = $stmt_select_blog->exec([$blog_uuid])->fetch(PDO::FETCH_OBJ);
$user_posts_on_stat = $blog_info->post_count_at_stat;
$dataset_post_count = $db->prepare("SELECT SUM(post_count_at_stat) FROM blogstats")->exec([])->fetchColumn();
//testing hypergeometric approach
$all_lexeme_appearances = $db->prepare("SELECT SUM(global_ndocs) FROM lexemes")->exec([])->fetchColumn();

$estimated_total_user_words = (float) $db->prepare(
	"SELECT SUM(lbe.nentry)::FLOAT FROM lexeme_blogstats_english lbe
    WHERE lbe.blog_uuid = :blog_uuid"
)->exec(["blog_uuid" => $blog_info->blog_uuid])->fetchColumn();

/**
 * Edge case handling:
 * Just because the user hasn't ever made a post containing X, doesn't mean that the user has a zero
 * probability of doing so.
 *
 * Ultimately the probabilistically correct way to handle this is to just do the full bernoulli
 * distribution calculation but, god damn that's a lot of factorials.
 *
 *  this would look like
 *  user_posts! / ((ndoc!(user_posts - ndoc)!)*((avg_freq)^ndoc)*(1 - avg_freq)^(user_posts - ndoc))
 *  Perhaps a reasonable proxy is the prior probability that the next post made is one containing X
 *  AND that is made by this user
 *
 * In other words let
 *  global_ndocs_freq = (global.ndocs/dataset_post_count)
 *  user_freq = user_post_count / dataset_post_count
 *  fake_ndoc = user_freq * global_ndocs_freq
 *
 */

$limit = 100;
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
        COALESCE(lbe.post_freq, 0.000000/:user_posts::FLOAT) AS post_freq,
        COALESCE(lbe.blog_freq, 0.000000/:total_words::FLOAT) AS blog_freq,
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
WHERE o.appearance_z_score * :dir > 0";

$max_appr_threshold = 9999;
$stats_positive = $db->prepare($stats_str . " ORDER BY percentile desc, o.trial_prob asc, o.rel desc LIMIT $limit");
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
	]
)->fetchAll(PDO::FETCH_OBJ);

$stats_negative = $db->prepare($stats_str . " ORDER BY percentile desc, o.trial_prob desc, o.rel desc LIMIT $limit");
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
	]
)->fetchAll(PDO::FETCH_OBJ);

//echo count($negativeUses)." results\n";

function printUses($uses) {
	$cols = str_pad("ROW", 6) . ": "
	. str_pad("(std_dev_post_freq", 22) . ", "
	. str_pad("avg_post_freq", 22) . ", "
	. str_pad("post_freq", 22) . ", "
	. str_pad("ndoc", 6) . ", "
	. str_pad("global_ndocs", 10) . "),"
	. str_pad("my_percentile", 16) . ","
	. str_pad("gsl_percentile", 16) . "";

	$i = 0;
	$rownum = 0;
	$printed = 0;
	echo "$cols\n";
	foreach ($uses as $nu) {

		$i++;
		$rownum++;
		//if($nu->lexeme != 'shitty' ) {
		/*if(($rownum > 300 && $rownum < count($uses)-300)) {
		$i--;
		continue;
		}*/
		if ($i % 15 == 0) {
			echo "$cols ROW: $rownum\n";
		}

		echo str_pad($rownum, 6) . ": (" . str_pad($nu->std_dev_post_freq, 22) . ", "
		. str_pad($nu->avg_post_freq, 22) . ", "
		. str_pad($nu->post_freq, 22) . ", "
		. str_pad($nu->ndoc, 6) . ", " . str_pad($nu->global_ndocs, 10) . "), "
		. str_pad(sigmaToPercentile($nu->appearance_z_score), 16) . ", "
		. str_pad(100 * $nu->percentile, 16) . ", "
		. "rfb=" . str_pad($nu->trial_prob, 22)
		. "   cfb_p=" . str_pad($nu->trial_prob_cumm, 22)
		. " chg_p=" . str_pad($nu->hyper_geom_cum, 22) .
		/*" ($nu->ndoc, $nu->global_ndocs, "
		.($all_lexeme_appearances - $nu->global_ndocs).
		", $user_posts_on_stat) ".*/
		str_pad($nu->lexeme, 15, " ", STR_PAD_LEFT) . "\n";
		$printed++;
	}

	//echo "(".str_pad($nu->ndoc, 6).", ".str_pad($nu->avg_post_freq, 22).", $user_posts_on_stat), rfb=".str_pad($nu->trial_prob,22)."   cfb_p=".str_pad($nu->trial_prob_cumm, 22)." chg_p=".str_pad($nu->hyper_geom_cum, 22)./*" ($nu->ndoc, $nu->global_ndocs, ".($all_lexeme_appearances - $nu->global_ndocs).", $user_posts_on_stat) ".*/str_pad($nu->lexeme, 20, " ", STR_PAD_LEFT)."\n";
}

echo "\nPOSITIVE: \n";
printUses($positiveUses);
echo "\nNEGATIVES: \n";
printUses($negativeUses);

//echo count($negativeUses)." results\n($printed printed)";

/**
 *
 * The simplest possible version of this looks like:
 * 1. estimate the total number of words the user has written
 * 2. divide nentry by this value  = user_propensity
 * 3. estimate the total number of words everyone has written
 * 4. divide global_nentries by that value  = global_propensity
 * 5. fingerprint tags are the ones with the largest value of SQRT(POW(1 - (user_propensit/global_propensity), 2))
 *
 * A different perspective might look like
 *
 * // Ideally we'd know the frequency with which words appear in the documents in which they appear
 * // but we'll have to make do with estimating the average word per document as
 * CREATE VIEW word_usage_estimates AS
 *  SELECT blog_uuid, SUM(lbe.nentry) / SUM(bl.indexed_post_count) as avg_words_per_doc
 *  FROM blogstats bl, lexeme_blogstats_english lbe
 *  WHERE bl.blog_uuid = lbe.blog_uuid
 *
 * lexeme_blogstats
 * user |   lexeme   | ndoc  | nentry |  total_doc | doc_ratio       |
 *      |    A       |   13  |   124  |       90   |  13/90=0.144    |
 *      |   B        |   78  |   259  |       86   |                 |
 *
 *
 *
 */
