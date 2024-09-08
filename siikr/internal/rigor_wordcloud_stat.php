<?php
require_once 'globals.php';
//require_once 'math.php';




/**
 * try to maintain wordcloudestats with some degree of atomiticity. 
 * 
 * there is a dedicated analyzed_blogs table which stores the blog_uuids of any blogs whose stats have been incorporated into the lexemes table.
 * 
 * 
 * the analyzed_blogs.blog_uuid table contains a list of all blog_uuids that have been fully or partially incorporated into lexemes_blogstats summary table.
 * per blog, it contains: 
 *  1. the number of posts that have been incorporated.
 *  2. the most recent time at which the cntents of the blog were updated
 *  3. whether some failure occurred while incorporating the blog (indicating reincorporation may be required)
 * 
 * the lexeme_blogstats_english table has columns:
 *  blog_unuuid, lexeme
 * 
 * any deletion of a blog from the server and blogstats table is fine, so long as it remains in analyzed_blogs
 * then its values are necessarily partially or fully incorporated into lexeme_blogstats_english and lexemes tables.
 * 
 * there are four broad classes of functionality required. 
 * 
 * 0. insertion of new lexemes into the lexemes table
 *  - upon insertion, initialize the lexeme with all values at 0, except for blogs_considered, which should have count(analyzed_blogs)
 * 
 * 1. progressive update of the global lexeme_blogstats_english and lexemes tables whenever new posts are added to a blog that already exists.
 *      - the newness of a post is determined by comparing its archived_date against the blog's analyzed_blogs.post_count_last_update column.
 *      - if post_count_last_update is null, then we do step 2 first (see below) to remove the blog history before inserting the new post.
 *      - after deleting all traces of the blog, we reinsert all new posts using the
 *      - post insertion procedes as follows: 
 **/ 
 function insertNewPosts($db, $blog_uuid) {
          $wordclouded_info = $db->prepare("SELECT * FROM analyzed_blogs where blog_uuid = :blog_uuid")->exec(["blog_uuid"=>$blog_uuid]);
          $last_stat_update = $wordclouded_info->last_stats_update;
          $pre_update_postcount = $wordclouded_info->post_count_at_stat;
          $new_post_count = $db->prepare("SELECT COUNT(*) FROM posts where blog_uuid = :blog_uuid and archived_date >= :last_stats_update");
 }


 /**
  * for self text, we want to insert both nentry counts 
  *(how frequently a user uses a word), and ndoc counts (how many documents the user uses the word in). 
  * for trail text, we don't keep nentries because we don't care how frequently other users use the word
  */

function buildQuery($filterOn, $into_lexeme_blogstats_table) {
    $lexeme_blogstat_nentry_str = "nentry = $into_lexeme_blogstats_table.nentry + EXCLUDED.nentry";
    $lexeme_blogstat_nentry_freq_str = "nentry_freq = ($into_lexeme_blogstats_table.nentry + EXCLUDED.nentry)::FLOAT/(:new_post_count + :pre_update_postcount)::FLOAT, ";

    $baseString = "WITH lexeme_stats AS (
              SELECT st.lexeme, st.nentry, st.ndoc, COALESCE(l.id, NULL) AS lexeme_id
              FROM 
                get_blog_lexeme_stats( --returns lexeme summaries using ts_stat
                    :blog_uuid, 
                    :vec_colum, -- name of the vector column to get stats for, see below
                    :fields, --uses the lexeme weight fields to determine which text type to incorporate. 
                            --for en_hun_simple: aet to 'a' for self text, set to 'c' for trail text. Do not include b or d, as these are weights for user mentions
                            --for ts_meta (if incorporating stats about things blogged about), 'a' = tag_text, 'b' = self media text, 'c' = trail media text. Do not use 'd', as it is reserved for usernames
                    :posts_after::TIMESTAMP --limit query to posts archived after this time
                    
                    ) AS st
              LEFT JOIN lexemes AS l ON l.lexeme = st.lexeme
          ),
          WITH lbe_sums AS (
            SELECT SUM(ls.nentry) total_new_nentries FROM lexeme_stats ls
          ),
          lexeme_mapped AS (
              INSERT INTO lexemes (lexeme, global_ndocs, global_nentries)
              SELECT ls.lexeme_text, ls.ndoc, ls.nentry, 
              FROM lexeme_stats ls
              ON CONFLICT (lexeme)
              DO UPDATE SET global_self_nentries = lexemes.global_nentries + EXCLUDED.global_nentries, --by construction  should be equivalent to lexeme_stat.nentry
                          global_self_ndocs = lexemes.global_ndocs + EXCLUDED.global_ndocs, --by construction equivalent to lexeme_stat.ndoc
                          global_trail_ndocs = lexemes.global_ndocs + EXCLUDED.global_ndocs
              RETURNING id, lexemes.lexeme as lexeme_text
          )
          INSERT INTO 
            $into_lexeme_blogstats_table (lexeme_id, unuuid, ndoc, post_freq, ndoc_freq)
            SELECT lm.id, :unuuid, ls.ndoc, 
                    ls.ndoc::FLOAT/(:new_post_count + :pre_update_postcount)::FLOAT,
                    ls.nentry::FLOAT/(total_new_nentries + :total_existing_nentries)::FLOAT
            FROM lexeme_mapped lm, lbe_sums lbes
            JOIN lexeme_stats ls ON lm.lexeme_text = ls.lexeme_text
            ON CONFLICT (unuuid, lexeme_id)
            DO UPDATE SET  
                ndoc = $into_lexeme_blogstats_table.ndoc + EXCLUDED.ndoc,
                ndoc_freq = ($into_lexeme_blogstats_table.ndoc + EXCLUDED.ndoc)::FLOAT/(:new_post_count + :pre_update_postcount)::FLOAT
            ";
}

 /** 
 *
 * 2. True removal of a blog from the LBE table, and corresponding update of the lexemes_self and lexemes_trail tables to account for the removal.
 * NOTE: This should really only be used when some conflict or inconsistency has been detected or is likely (for example if we've updated the scheme by which lexemes are represented, or we have deleted a blog from the blogstats table, but someone is asking to archive it again. To avoid double counting, we thereby remove the content that used to be related to this blog before adding it back in)
 *      -  the removal proceeds by 
 */
function trulyDeleteBlog($db, $blog_uuid) {
    $wordclouded_entry = $db->prepare("SELECT * FROM analyzed_blogs where blog_uui = :blog_uuid")->exec(["blog_uuid"=>$blog_uuid])->fetchObj(PDO::FETCH_OBJ);
    $blogstats_entry = $db->prepare("SELECT * FROM blogstats where blog_uui = :blog_uuid")->exec(["blog_uuid"=>$blog_uuid])->fetchObj(PDO::FETCH_OBJ);

 /**
  * letting LBE denote the lexeme_blogstats_english_(self or trail) table 
  *         l denote the lexemes table.
  *         s/t denote "self or tral"
*     -  going through every LBE(lexeme_id, blog_uuid = to_remove.blog) 
 *          - for each LBE.lexeme_id in l.lexeme_id:
 *                  l.blogs_considered = l.blogs_considered - 1 
 *                  l.global_ndocs = l.global_ndocs - LBE.ndocs 
 *                  l.ndoc_variance_total = l.ndoc_variance_total - POWER(LBE.ndoc_freq_s/t - l.avg_ndoc_freq_s/t, 2)
 *                  l.ndoc_freq_total_s/t = l.ndoc_freq_total_s/t - LBE.ndoc_freq_s/t
 *          - for each LBE.lexeme_id NOT in lexemes.id:
 *                  l.blogs_considered = l.blogs_considered - 1 
 *                  l.ndoc_variance_total = l.ndoc_variance_total_s/t - POWER(l.avg_ndoc_freq, 2)
 *                  l.ndoc_freq_total = l.ndoc_freq_total - LBE.ndoc_freq_s/t
 *          - deleting all LBE(blog_uuid, lexeme_id)
 *          - updating lexemes table such that
 *                  l.avg_ndoc_freq = l.ndoc_freq_total / l.blogs_considered; 
 *                  l.std_dev_ndoc_freq = l.ndoc_variance_total_s/t / l.blogs_considered;
 *         
 *      - finally a sanity check is performed by checking that COUNT(DISTINCT l.blogs_considered) = 1 (so as to ensure all lexems have the same number of blogs considered0 and that l.blogs_considered = count(analyzed_blogs) - 1; (so as to ensure that we have discounted exactly one blog, which has yet to be removed from the analyzed_blogs table)
 *          if this passed, we delete the analyzed_blogs entry and commit.
 */
 } 
 /* 
 * 3. insertion of a new blog into analyzed_blogs
 *      - the insertion process proceeds by
 *          - inserting the blog into the wordclouds table, setting last_stats_update to 0 to ensure any new posts are accepted regardless of last_archived_date
 *          - parsing all posts on the blog
 *          - upserting all found lexemes into the lexemes table with empty values, doing nothing on update and returning any lexemes which triggered an update
 * 
 * */









//TODO: handle stylistic stats



 /**
 * When analyzing writing style, we unfortunately can't rely on the aggregate statistics ts_stat
 * provides, since this doesn't allow us to accurately distinguish the frequency 
 * with which a user uses any given word.  
 * 
 * Consider for example a blog with 4 posts 
 * post 1 : has 1400 words, 1% (14) of which are "catgirl" 
 * post 2 : has 300 words, 33% (100) of which are "catgirl",
 * post 3 : has 200 words, 15% of which (36) are "catgirl". (sadly, quite realistic for our 
 * post 4 : has 52 words, 25% of which (13) are "catgirl".
 * 
 * If we take take these in aggregate, we estimate 
 * =(14+100+36+13)/(1400+300+200+52)
 * =163/1952
 * = ~8.3% catgirl use. But this doesn't agree with what we're actually seeing. 
 * One very long post on probably some heady mathematical topic 
 * where the author was mature enough to only reference catgirls a sparing and tasteful 1% 
 * should not outweigh 3 toxoplasmotic shit posts clearly indicating a "catgirl" tendency much closer to 20%. 
 * 
 * we must therefore individually consider each post, giving us 
 * (1%+33%+15%+25%) / 4
 * = ~18%. 
 * Which is much more reasonable. But annoyingly slower.
 */

function insertNewNentries($db, $blog_uuid) {

    $old_post_count = $get_start_post_count->exec([$blog_uuid]);
    

}