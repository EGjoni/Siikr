<?php
require_once "../internal/globals.php";
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
require_once "../internal/post_reprocessor.php";
require_once "../internal/adopt_blog.php";


$get_uuid = $db->prepare("SELECT uuid_of(:blog_name)");
$get_total_posts = $db->prepare("SELECT indexed_post_count from blogstats WHERE blog_uuid = :blog_uuid");
$get_post_ids = $db->prepare("SELECT post_id FROM posts WHERE blog_uuid = :blog_uuid ORDER BY post_id desc OFFSET :offs");
$get_post = $db->prepare(
    "SELECT c.post_id_i::TEXT as post_id, c.*, 
    COALESCE(agg.tags, array_to_json(ARRAY[]::text[])) as tags,
    COALESCE(mediaagg.media_info, '[]'::json) media
        FROM (
            SELECT 
                post_id as post_id_i, post_date, 
                blocksb as blocks, tag_text, 
                blog_uuid,
                is_reblog, 
                extract(epoch from post_date)::INT as timestamp,
                post_date,
                ts_meta,
                ts_content,
                has_text,
                has_link,
                has_ask,
                has_images,
                has_video,
                has_audio,
                has_chat,
                index_version,
                hit_rate,
                deleted
            FROM posts
            WHERE post_id = :post_id) as c 
            LEFT JOIN LATERAL
            (SELECT 
                array_to_json(array_agg(t.tag_name)) as tags
             FROM 
                posts_tags pt
             LEFT JOIN 
                tags t ON pt.tag_id = t.tag_id
             WHERE
                pt.blog_uuid = :blog_uuid 
                AND 
                pt.post_id = c.post_id_i
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
                    mp.post_id = c.post_id_i
                GROUP BY
                    mp.post_id
            ) as mediaagg ON true
     ");


    $ts_meta_set = "
    setWeight(to_tsvector('$content_text_config', :tag_text), 'A')
    || setWeight(to_tsvector('$content_text_config', :self_media_text), 'B') 
    || setWeight(to_tsvector('$content_text_config', :trail_media_text), 'C') 
    || setWeight(to_tsvector('simple', :trail_usernames), 'D')";
   
    $nomenA = "setWeight(to_tsvector('$content_text_config', :self_no_mentions), 'A')::TEXT"; 
    $wmenB = "(SELECT replace(setWeight(to_tsvector('$content_text_config', :self_with_mentions), 'B')::TEXT, '@siikr.tumblr.com', ''))::TEXT";
    $nomenC = "setWeight(to_tsvector('$content_text_config', :trail_no_mentions), 'C')::TEXT";
    $wmenD = "(SELECT replace(setWeight(to_tsvector('$content_text_config', :trail_with_mentions), 'D')::TEXT, '@siikr.tumblr.com', ''))::TEXT";
    $doesMention = "
    ($nomenA || ' ' || $wmenB)::tsvector || 
    ($nomenC || ' ' || $wmenD)::tsvector
    ";
    //faster variant when no user mentions
    $noMention = "setWeight(to_tsvector('$content_text_config', :self_text_regular), 'A') || setWeight(to_tsvector('$content_text_config', :trail_text_regular), 'C')";
    
    $update_nomention = $db->prepare("UPDATE posts SET 
        ts_meta = $ts_meta_set,
        ts_content = $noMention
        WHERE post_id = :post_id");
    $update_mention = $db->prepare("UPDATE posts SET
        ts_meta = $ts_meta_set, 
        ts_content = $doesMention
        WHERE post_id = :post_id");

$skip_to = [];//"nuclearspaceheater" => 677193585843699712];
$offset = 12587;
function begin_fix_for($blog_name) {
    global $db, $get_uuid, $get_post_ids, $get_post, $get_total_posts, $update_nomention, 
    $skip_to, $update_mention, $failed_posts, $archiver_version, $offset;
    $blog_uuid = $get_uuid->exec(["blog_name"=>$blog_name])->fetchColumn();
    $total_posts = $get_total_posts->exec(["blog_uuid"=>$blog_uuid])->fetchColumn();
    echo "retrieving posts $offset to  ". ($total_posts)."...";
    $post_ids_all = $get_post_ids->exec(["blog_uuid" => $blog_uuid, "offs" => $offset])->fetchAll(PDO::FETCH_COLUMN);
    echo "retrieved \n";
    $processed = 0; 
    $offset = 0;
    do {
        $curr_post_id = array_pop($post_ids_all);
        $post = $get_post->exec(["blog_uuid"=>$blog_uuid, "post_id" => $curr_post_id])->fetch(PDO::FETCH_OBJ);
        $processed++;
        if($post->index_version < $archiver_version) continue;
        if(count($skip_to) > 0 && $post->post_id != $skip_to[$blog_name]) {
            continue;
        } else if($post->post_id == $skip_to[$blog_name]) {
            array_pop($skip_to);
        }
        try {
            $db->beginTransaction();
                $post->blocks = json_decode($post->blocks);
                $post->tags = json_decode($post->tags, true);
                $post->media = json_decode($post->media, true);
                $fake_map = [];
                foreach($post->media as $med) {
                    $fake_map[$med["media_id"]] = (object)$med;
                }
                $for_db = extract_db_content_from_siikr_post($post, $fake_map);
                $tag_rawtext = implode('\n#', $post->tags);
                if(mb_strlen($tag_rawtext) > 0) $tag_rawtext = "#$tag_rawtext";

                $new_vals = [
                    "post_id" =>$post->post_id,
                    "self_media_text" => $for_db->self_media_text,
                    "trail_media_text" => $for_db->trail_media_text,
                    "trail_usernames" => $for_db->trail_usernames,
                    "tag_text" => $tag_rawtext
                    ];
                if(count($for_db->self_mentions_list) + count($for_db->trail_mentions_list) > 0) {
                    $new_vals["self_no_mentions"] = $for_db->self_text_no_mentions;
                    $new_vals["self_with_mentions"] = $for_db->self_text_augmented_mentions;
                    $new_vals["trail_no_mentions"] = $for_db->trail_text_no_mentions;
                    $new_vals["trail_with_mentions"] = $for_db->trail_text_augmented_mentions;
                    $update_mention->exec($new_vals);
                } else {
                    $new_vals["self_text_regular"] = $for_db->self_text_regular;
                    $new_vals["trail_text_regular"] = $for_db->trail_text_regular;
                    $update_nomention->exec($new_vals);
                }
                quality_assurance($post);
            $db->commit();
            echo "\r \t processed: ".round(100*($processed+$offset)/$total_posts,4) ."% (".($processed+$offset)." / $total_posts) __ $post->post_id __ (".count($failed_posts)." bad posts encountered)";
        } catch(Exception $e) {
            $db->rollback();
            $failed_posts[$post->post_id]["error"] = $e->getMessage();
        }
    } while ($curr_post_id != null);
}

function start() {
    global $failed_posts;
    $to_fix_arr = getToFix();
    $total = count($to_fix_arr);
    echo "\n";
    for($i=0; $i<$total; $i++) {
        $failed_posts = [];
        $current_blog = $to_fix_arr[$i];
        echo "\033[K\rblog ($i / $total): $current_blog --\n";
        begin_fix_for($current_blog);
        if(count($failed_posts) > 0) {
            echo "\n\n\nfailed posts stored in /home/eron/failed_$current_blog.json\n";
            $err_j = json_encode($failed_posts);
            file_put_contents("/home/eron/failed_$current_blog.json", $err_j);
        }
    }
    
}

start();

function getToFix() {
    $to_fix =["o-craven-canto", "discoursedrome", "cromulentenough", "official-kircheis", "shieldfoss", "headspace-hotel", "eightyonekilograms", "deirdreskye", "unsoundedcomic", "amwult", "tactfulsaboteur", "culumacilinte", "geyfrog", "pinkygirlymeg", "sleeplesssmoll", "sinesalvatorem", "self-loving-vampire", "unknought", "mountainsboyhowdy", "sophia-epistemia", "rationalists-out-of-context", "processes", "acidbathcat", "triviallytrue", "lepartiprisdeschoses", "queenlua", "hybridzizi", "easy-copper", "sojourner-between-worlds", "kushblazer666", "galacticwiseguy", "nostalgebraist-autoresponder", "wdhmbt", "a-real-tough-kid", "kamenriderblapck", "feotakahari", "1794", "alltheoxen", "gofancyninjaworld", "palindromordnilap", "transgenderer", "7bitter", "yujin-mikotoba", "patricia-taxxon", "chronotopes", "thahxa", "xylophonetangerine", "summoningspark", "transmutationisms", "karcatgirl-vantas", "victoriawaterfield", "northshorewave", "toasthaste", "meowlgbt", "definitelynotplanetfall", "bogleech", "klint-vanzieks", "balioc", "vesta-knows-besta", "kontextmaschine", "deadpanwalking", "tuesdayisfordancing", "philippesaner", "rustingbridges", "999-roses", "metamatar", "imagineyourepregnant", "fruityyamenrunner", "robustcornhusk", "cetitan", "yorickish", "elancholia", "raginrayguns", "fluxion-fluent", "withasmoothroundstone", "analyticrambles", "hirosensei", "hymneminium", "recreationalwordsayer", "clearlightwired", "kwarrtz", "sigmaleph", "youzicha", "nextworldover", "kvothbloodless", "apollo-cackling", "wellmetmat", "nohoperadio", "cosmogyros", "kaiasky", "shaddy-bee", "dissent-in-the-hivemind", "severalowls", "aromantyczno-liryczna", "markadoo", "gender-trash", "autogeneity", "vash3r", "yagrandmapeach", "markrosewater", "itsbenedict", "supernulperfection", "baconmancr", "loveofdetail", "takashi0", "arcticdementor", "infraredarmy", "lordascapelion", "dagny-hashtaggart", "lipstickchainsaw", "sawthatmountainburn", "brazenautomaton", "prokopetz", "pilfered-words", "peysk", "booksandchainmail", "jenlog", "open-sketchbook", "tlaquetzqui", "angel-in-shibari", "menalez", "friend-o-dorothy", "samueldays", "decepticonsensual", "shanti-ashant-hai", "melongumi", "biganimal92", "regexkind", "veteratorianvillainy", "loving-n0t-heyting", "razehider", "uncomfortablecliche", "wuggen", "haveyoureadthistransbook", "random-thought-depository", "zarohk", "morlock-holmes", "miraclemaya", "semimedieval", "onecornerface", "tsarina-anadyomene", "yrn-te-ao","swordoftheberserkgutsrage", "tanadrin", "pregtboy", "cockatude", "centrally-unplanned", "canmom", "ouidamforeman", "metastablephysicist", "randombubblegum", "smash-or-pass-headphones", "inspector-gina-lestrade", "vienna-salvatori", "ssreeder", "red-shepherds", "viria", "gurguliare", "izzys-little-reblog-corner", "frankendykes-monster", "awildwickedslip", "jambeast", "wherestoriescomefrom", "horseforeplay", "doubleca5t", "teaboot", "lunachats", "three-green-waterbottles", "twilight-phantasms", "sosuperawesome", "lady-inkyrius", "fulfillpurpose", "lesbianchemicalplant", "year-zero-illuminates", "ot3", "andmaybegayer", "phaeton-flier", "anton-exe", "minimoonstar", "goldstarsupremacy", "burneracct69", "argentconflagration", "gowns", "analytically", "sivavakkiyar", "ntrlily", "onrtrp", "enarei", "petewentzisblack1312", "etirabys", "nervousfeminist", "zzedar2", "02x6ifnow", "pavonini", "reachartwork", "awstens-vagina", "der-unverantwortliche", "nyakase", "sexhaver", "heresylog", "emilia0", "arzner", "apas-95", "sugar--pills", "aufline", "everything-narrative", "fulfilledpurpose", "galois-groupie", "profound-yet-trivial", "radicarian", "dizzypoke404", "kremlin", "adiantum-sporophyte", "amtrak-official", "ms-demeanor", "homuncvlus", "kit-is-god", "serinemolecule", "radfem-gossip-reborn", "votoms", "catchaspark", "dandelionmoss", "aistobascistod", "leonardalphachurch", "machine-unlearning", "correctrvbquotes", "thesentdowngirl", "afloweroutofstone", "paratactician", "nonevahed", "barok-vanzieks", "gayllotine", "fatpinocchio", "darker-than-darkstorm", "hellsite-yano", "lonelyhum", "soap-stones", "blujaynoodles", "blujaydoodles", "quantumofawesome", "fruitsoftheape100", "dasha-aibo", "thirteen-jades", "roundearthsociety", "catgirlforeskin", "arealflame", "thathopeyetlives", "the-grey-tribe", "puck1919", "gay-4-space", "nebulaniggatry", "hauntingyourself", "gotyouanyway", "catgirlcummies", "milfygerard", "raidouversusthesoullessarmy", "aurpiment", "eternalfarnham", "does-it-like-women", "familyabolisher", "ryunosuke-naruhodo-blog", "kazuma-asogi-blog", "holidayblues", "thefloatingstone", "reselection", "ineedsomeplacetoshoutfantheories", "fragariavescana", "saint-ambrosef", "picayunepuma", "foreversoaringreblogs", "theoutcastrogue", "yuri-alexseygaybitch", "indigosfindings", "bimboficationblues", "stellisketches", "wmb-salticidae", "cali", "deanmartinwatertorture", "identifying-cellphones-in-posts", "log6", "woman-loving", "poetics-tracy", "althea-the-angel", "random2908", "drw-rw-rw", "kiiamn", "melodylux", "spaghettioverdose", "mitigatedchaos", "max13l", "fearthefuzzybear", "tejonterrible", "greatwyrmgold", "atleastitsnotasbestos", "swordfaery", "mrcatfishing", "uttervogonity", "kinkyrius", "azdoine", "moral-autism", "birlinterrupted", "abodywithorgans", "thatothermortal", "lovelyelbowleech", "longing-for-rain", "ascendandt", "aaronsmithtumbler", "slatestarscratchpad", "somethingwittythiswaycomes", "roborosewater-masters", "ohcorny", "bubbloquacious", "fatestayyuri", "rin-tezuka", "zennistrad", "ineffectualdemon", "autisticstevenuniverse", "homo-del-reyyy", "heavenlymusickcorporation", "lumsel", "cyberbun", "giucomix", "eikotheblue", "archangelic-aeon", "danzafila", "captainjonnitkessler", "spinningthehamsterwheel", "rightside-left", "gutsy-service-pred", "glitchpaladin", "catgirl-greatsword", "lollystocks", "erissdoesart", "erissthemean", "skittypretty", "seserakh", "st-just", "multiheaded1793", "hyphyp", "themostdesperatehoney", "irrealisms", "sysid-ace", "jewelsfromthesky", "natalieironside", "frutify", "xenostalgic", "hondacivictrucknuts", "eccentric-opinion", "rayadraws", "mayfriend", "scissortailedsaint", "baroquespiral", "artbyblastweave", "chahaa-piun-ja", "spiralingintocontrol", "sulkybender", "erisenyo", "jet-apologistmybadhomies", "keynes-fetlife-mutual", "gasmeros", "lambdaphagy", "allgremlinart", "birdmemes", "lafflanes", "ohnofersure", "luff-lamfada", "mugentakeda", "incarnation-issues", "squareallworthy", "determinate-negation", "duran-duran-less-official", "duckdotcom", "werewolf-cuddles", "addadashofpepper", "annabelle--cane", "evenstarfalls", "unfavorableinstigation", "algorizmi", "hbmmaster", "apricops", "tripleboy", "teenjournalist", "heritageposts", "comicsansstein", "dreissigconversion", "turnstileskyline", "therobotmonster", "sarsariya", "uququ", "xenosagaepisodeone", "ducktoothcollection", "wewererogue", "the-unseelie-court-official", "theygotbitchesinmedia", "knickynoo", "that-starlight-prince", "siikr", "retroactivebakeries", "keithstack", "0x4468c7a6a728", "crustacean-on-main", "memecucker", "businesstiramisu", "garmbreak1", "fiveeurocup", "wisteriasymphony", "gonad-transformer", "velvetvexations", "strelka", "foone", "against-forms-recognizable", "zzedar", "justwhumpythings", "vriskakinnieaynrand", "literary-illuminati", "mycophob1a", "sespursongles", "hedgehog-moss", "theminecraftbee", "dizzyghoast", "swarnpert", "just-golden-shower-thoughts"];
    return $to_fix;
};
