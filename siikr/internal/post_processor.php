<?php 
require_once 'globals.php';
$stopword = ' a ';
/**A collection of functions for processing and transforming tumblr NPF posts */
$mediatype_textColumn_map = [

];

function addHintCandidate($blog_name, &$into) {
    $predeactivated = explode("-deactivated", $blog_name);
    if(count($predeactivated) > 1) {
        $into[$predeactivated[0]] = $blog_name;     
    } 
}


/**
 * attempts to infer the popularity of a post from a sample of its notes.
 * if a post has more than 50 notes, tumblr api truncates them, so in a best attempt to 
 * account for s-curves we offset such that end up using the odest reported note if ther
 * are more than twice as many notes as the array we're provided, we use the range from
 * post_date to the oldest note. 
 * */
function getHitRate($post, $isReblog) {
    $range_min = $post->timestamp;
    $trail = $post->trail;
    $window= 604800;//one week;
    if($isReblog) {
        /*for reblogs the API spec says we get to know the timestamp, but actually we don't 
        so we have to approximate by note range. 
        Included though is a branch just in case they fix the API*/
        
        if($isReblog && count($post->trail)>0 && ($post->trail[0]?->post?->timestamp ?? ($range_min+1)) < $range_min) {
            $range_min = min($range_min, $post->trail[0]?->post?->timestamp ?? $range_min);
        } else if(property_exists($post, "notes") && count($post?->notes)>0) {
            $note_sample_count = count($post?->notes);
            $range_min = $post->notes[$note_sample_count-1]?->timestamp;
            $range_max = max($post->timestamp, $post->notes[0]->timestamp);
            $range_max = max($range_max, $range_min + min($window, time()-$range_max));
            $timeRange = abs($range_max-$range_min);
            return $note_sample_count/(log($timeRange)+1);
        }
    }
    if(!property_exists($post, "notes")) {
        return 0;
    }
    $newest_idx = 0;
    $post_sample_count = count($post->notes);
    
    $newest_idx = min($post_sample_count,
                max(0, min(($post->note_count/2) - $post_sample_count, $post_sample_count-1)));
    $adjustedCount = $post->note_count - $newest_idx;
    $range_max = $post->notes[$newest_idx]->timestamp;
    $range_max = max($range_max, $range_min+ min($window, time()-$range_max));
    $timeRange =  log(abs($range_max-$range_min));
    return $adjustedCount/($timeRange+1);
}


$formatting_map = [
    "bold" => "b",
    "small" => "s",
    "italic" => "i",
    "strikethrough" => "str",
    "color" => "col", 
    "link" => "href",
    "href" => "href",
    "mention" => "ment",
    "ment"=> "ment"
];


function extract_db_content_from_post($post) {
    global $possible_encodings;
    $soFar = 
    ["self_text" => "", 
    "trail_text" => "",
    "title" => "",
    "has_text" => 0b00, 
    "has_link" => 0b00, 
    "has_chat" => 0b00,
    "has_ask" => 0b00, 
    "has_images" => 0b00,
    "has_audio" => 0b00,
    "has_video" => 0b00,
    "media" => [],
    "mentions" => []];
    $npf_reblog = property_exists($post, "trail") ? (count($post->trail) > 0 ? 1 : 0): 0;
    $legacy_reblog = property_exists($post, "parent_post_url") || property_exists($post, "parent_post_id") ? 1 : 0;
    $soFar["is_reblog"] = $npf_reblog || $legacy_reblog ? 1 : 0;
    $soFar["hit_rate"] = getHitRate($post, $soFar["is_reblog"]);

    if(property_exists($post, "notes")) {
        foreach($post?->notes as $note) {
            if($note->type == 'reblog') {
                addHintCandidate($note->reblog_parent_blog_name, $deactivationHints);
            }
        }
    }
    
    
    list(
        $self_text_no_mention, 
        $self_text_augmented_mentions, 
        $self_text_regular,
        $self_media_text) = extract_blocks_from_content($post, $soFar, 0b01);
    $self_mentions = $soFar["mentions"];
    $self_media = $soFar["media"];
    $soFar["mentions"] = [];
    $soFar["media"] = [];
    $deactivationHints = [];
    
    
    
    $trail_text_no_mention = "";
    $trail_text_augmented_mentions = "";
    $trail_text_regular = "";
    $trail_media_text = "";

    

    if (property_exists($post, 'trail')) {
        $soFar["trail_users"] =[];
        
        foreach ($post->trail as $trail_item) {
            if(property_exists($trail_item, "blog") && $trail_item->blog->name != null) {
                $soFar["trail_users"][$trail_item->blog->name] = true;
                addHintCandidate($trail_item->blog->name, $deactivationHints);
            } else {
                echo "----------bork\n, username unavailable";
            }
            list(
                $_trail_text_no_mention, 
                $_trail_text_augmented_mentions, 
                $_trail_text_regular,
                $_trail_media_text) = extract_blocks_from_content($trail_item, $soFar, 0b10);
                $trail_text_no_mention .= "\n$_trail_text_no_mention";
                $trail_text_augmented_mentions .= "\n$_trail_text_augmented_mentions";
                $trail_text_regular .= "\n$_trail_text_regular";
                $trail_media_text .= "\n$_trail_media_text";
        }
        $soFar["trail_usernames"] = implode(" " , array_keys($soFar["trail_users"]));
    }
    

    $soFar["self_text_no_mentions"] = ensure_valid_string($self_text_no_mention);
    $soFar["self_text_augmented_mentions"] = ensure_valid_string($self_text_augmented_mentions);
    $soFar["self_text_regular"] = ensure_valid_string($self_text_regular); 
    $soFar["self_mentions_list"] = $self_mentions;
    $soFar['self_media_list'] = $self_media;
    $soFar['self_media_text'] = ensure_valid_string($self_media_text);
    $soFar["trail_text_no_mentions"] = ensure_valid_string($trail_text_no_mention);
    $soFar['trail_text_augmented_mentions'] = ensure_valid_string($trail_text_augmented_mentions);
    $soFar['trail_text_regular'] = ensure_valid_string($trail_text_regular);
    $soFar['trail_mentions_list'] = $soFar["mentions"];
    $soFar['trail_media_list'] = $soFar['media'];
    $soFar['trail_media_text'] = ensure_valid_string($trail_media_text);
    $asObj = (object)$soFar;
    unset($soFar["self_mentions_list"], $soFar[""]); unset($soFar["media"]);
    $asObj->deactivation_hints = $deactivationHints; 
    return $asObj;
}


function extract_blocks_from_content($subpost, &$soFar, $mode=0b01) {
    global $possible_encodings; 
    $text_content = ["no_mentions" => [], "with_mentions" => [], 
    "text_regular" => [], /*kind of redundant because is equivalent to what either 
    of the other two strings will be if there aren't any mentions, but might as well be explicit*/
    "media_text" => [],/*it's acceptable to have this be out of order with the text content blocks because each media item is 
    it's own block, and we store in a seperate column in the database anyway*/
    ];
    $content = $subpost->content;
    $blockct = 0;
    $dibsArr = []; //stores string objects that should be inserted before a block of a given index.
    
    foreach($subpost->layout as $lay_item) {
        if($lay_item->type == "ask") {
            $soFar["has_ask"] |= $mode;
            if(property_exists($lay_item, "attribution") && $lay_item->attribution?->blog?->name != null) {
                $asking_blog_info = $lay_item?->attribution?->blog;
                if(property_exists($asking_blog_info, "name")) {
                    //$soFar["mentions"][$asking_blog_info->name] = $asking_blog_info;
                    $fakeBlock = (object)[];
                    $fakeBlock->type = "text"; 
                    $fakeBlock->text = $asking_blog_info->name;
                    $block_idx = $lay_item->blocks[0];
                    
                    $generatedMention = (object)[];
                    $generatedMention->start = 0;
                    $insertionLength = mb_strlen($asking_blog_info->name." ");
                    $generatedMention->end = $insertionLength-1;
                    $generatedMention->type = "mention";
                    $generatedMention->blog = $asking_blog_info;
                    $fakeBlock->formatting = [$generatedMention];
                    $soFar["mentions"][$generatedMention->blog->name] = $generatedMention->blog;
                    $fakeText = [];
                    list($content_text_stopword_mention, 
                    $content_text_augmented_mention, 
                    $content_text_regular, 
                    $inline_links, $mentions) = convert_to_dual_form($fakeBlock, $soFar);
                    $fakeText["no_mentions"] = $content_text_stopword_mention;
                    $fakeText["with_mentions"] = $content_text_augmented_mention;
                    $fakeText["text_regular"] =  $content_text_regular;
                    $dibsArr[$block_idx][] = $fakeText;
                }
            }
        }
    }
   
    foreach ($content as $block) {
        try {
            if (property_exists($block, 'text')) {
                if(property_exists($block, "subtype")) {
                    if ($block->subtype == 'chat')
                        $soFar["has_chat"] |= $mode;
                }
                $content_text = $block->text;
                if(isset($dibsArr[$blockct])) {
                    foreach($dibsArr[$blockct] as $fakeBlock) {
                        $text_content["no_mentions"][] = $fakeBlock["no_mentions"];
                        $text_content["with_mentions"][] = $fakeBlock["with_mentions"];
                        $text_content["text_regular"][] =  $fakeBlock["text_regular"];
                    }
                }

                list($content_text_stopword_mention, 
                    $content_text_augmented_mention, 
                    $content_text_regular, 
                    $inline_links, $mentions) = convert_to_dual_form($block, $soFar);
                
                $text_content["no_mentions"][] = $content_text_stopword_mention;
                $text_content["with_mentions"][] = $content_text_augmented_mention;
                $text_content["text_regular"][] =  $content_text_regular;
                $soFar["has_text"] |= $mode;
                foreach($mentions as $mentionObj) {
                    if(property_exists($mentionObj, "uuid") || !isset($soFar["mentions"][$mentionObj->name])) {
                        $soFar["mentions"][$mentionObj->name] = $mentionObj;
                    }
                }
                if(count($inline_links) > 0) {
                    $soFar["has_link"] |= $mode;
                }
            }
            
            if(property_exists($block, "type")) {
                if($block->type == 'poll') {
                    $content_text = $block->question;
                    foreach($block?->answers as $answer) {
                        $content_text .= $answer->answer_text."\n";
                    }
                    $text_content["no_mentions"][] = $content_text;
                    $text_content["with_mentions"][] = $content_text; 
                    $text_content["text_regular"][] =  $content_text;
                    continue; 
                } 
                $media_text = "";
                if ($block->type == 'image') {
                    $soFar["has_images"] |= $mode;
                    $media_info = [
                        "media_url" => normalizeURL($block->media[0]->url), 
                    "type" => 'I'];
                    
                    /*if(count($block->media) > 1) {
                       
                        $preview_url = $block->media[count($block->media) -1]->url; 
                        $splitted = array_pop(explode("_", $preview_url));
                        $media_info["preview_url"] = $suffix;
                    }*/
                    //annoyingly, images are often alt_texted with just "image", and there's no reason to store that
                    if(property_exists($block, "caption") && strtolower($block->caption) != "image") {
                        $media_info["title"] = $block->caption;
                        $media_text = $media_info["title"]."\n";
                    }
                    if(property_exists($block, "alt_text") && strtolower($block->alt_text) != "image") {
                        $media_info["description"] = $block->alt_text;
                        $media_text = $media_info["description"]."\n";
                    }
                    $soFar["media"][] = $media_info;
                    $text_content["media_text"][] = $media_text;
                }
                else if ($block->type =='audio') {
                    $soFar["has_audio"] |= $mode;
                    $url = isset($block->url) ? $block->url : $block->media->url;
                    $media_info = ["media_url" => normalizeUrl($url), "type" => "A"];
                    if(property_exists($block, "title")) { 
                        $media_info["title"] = $block->title;
                        $media_text .= $media_info["title"]."\n";
                    }
                    if(property_exists($block, "artist")) {
                        $author_text = $block->artist;
                        $media_info['description'][] = "<AUTH>".$author_text."</AUTH>";
                        $media_text .= $author_text."\n";
                    }
                    if(property_exists($block, "album")) {
                        $album_text = $block->album;
                        $media_info['description'][] = "<ALB>".$album_text."</ALB>";
                        $media_text .= $album_text."\n";
                    } 
                    if(isset($media_info['description'])) $media_info["description"] = implode("", $media_info['description']);
                    if(property_exists($block, "poster")) {
                        $media_info["preview_url"] = normalizeURL($block->poster[0]->url);
                    }
                    
                    $soFar["media"][] = $media_info;
                    $text_content["media_text"][] = $media_text;
                }
                else if ($block->type == 'video') {
                    
                    $soFar["has_video"] |= $mode;
                    $url = isset($block->url) ? $block->url : $block->media->url;
                    $media_info = ["media_url" => normalizeURL($url),
                     "type" => "V"];
                   
                    if(property_exists($block, "attribution")) {
                        $attribution = $block->attribution;
                        if(is_array($block->attribution)) 
                            $attribution = $block->attribution[0];
                        if($attribution != null) {
                            if(property_exists($attribution, "title")) {
                                $media_info["title"] = $attribution->title;
                                $media_text .= $media_info["title"]."\n";
                            }
                            if(property_exists($attribution, "display_text")) { 
                                $media_info["description"][] = $attribution->display_text;
                            }
                            if(property_exists($attribution, "description")) {
                                $media_info["description"][] = $attribution->description;
                            }
                        }
                    } 
                    if(property_exists($block, "poster")) $media_info["preview_url"] = normalizeURL($block->poster[0]->url);
                    if(isset($media_info["description"])) {
                        $media_info["description"] = implode("\n", $media_info["description"]);
                        $media_text .= $media_info["description"];
                    }
                    $soFar["media"][] = $media_info;
                    $text_content["media_text"][] = $media_text;
                } 
                else if ($block->type =='link') {
                    $soFar["has_link"] |= $mode;
                    $media_info = ["media_url" => normalizeURL($block->url), 
                        "type" => "L"];
                        
                    if(property_exists($block, "display_url")) {
                        $durl = normalizeURL($block->display_url);
                        if($durl != $media_info["media_url"]) $media_info["preview_url"] = $durl;
                        
                    }
                    if(property_exists($block, "title")) {
                        $media_info["title"] = $block->title;
                        $media_text .= $media_info["title"]."\n";
                    }
                    if(property_exists($block, "author")) {
                        $author_text = $block->author;
                        $media_info['description'][] = "<AUTH>".$author_text."</AUTH>";
                        $media_text .= $author_text."\n";
                    }
                    
                    if(property_exists($block, "description")) {
                        $desc_text = $block->description;
                        $media_info["description"][] = $desc_text;
                        $media_text .= $desc_text;
                    }
                    if(property_exists($block, "poster")) //we ovverride preview url if a nicer one supposedly exists
                        $media_info["preview_url"] = normalizeURL($block->poster[0]->url);
                    
                    if(isset($media_info["description"])) 
                        $media_info["description"] = implode("\n", $media_info["description"]);
                    $soFar["media"][] = $media_info;
                    $text_content["media_text"][] = $media_text;
                }
            }
        } catch (Exception $e) {
            echo "error decoding text $e";
        }
        $blockct++;
    }
    $result = [
        implode("\n", $text_content["no_mentions"]),
        implode("\n", $text_content["with_mentions"]),
        implode("\n", $text_content["text_regular"]),
        implode("\n", $text_content["media_text"])
        ];
    return $result;
}

function convert_to_dual_form($block, &$soFar) {
    global $stopword; 
    $content_text_stopword_mention = $block->text;
    $stopRemove = 0;
    $stopAdded = 0; 
    $stopstart = 0;
    $content_text_augmented_mention = $block->text;
    $augmentAdded = 0;
    $content_text_regular = $block->text; 
    $augmentWord = '@siikr.tumblr.com';
    $augmentLength = mb_strlen($augmentWord);
    $inline_links = [];
    $mentions = [];
    if(isset($block->formatting)) {
        $sorted = [];
        foreach($block->formatting as $f) {        
            $mentionObj = is_effective_mention($block, $f);
            if($mentionObj != false) {                
                $sorted[] = $mentionObj;
            } else if($f->type == 'link') {
                $inline_links[] = normalizeURL($f->url);
            }
        }
        @usort($sorted, @function($a, $b) {
            if($a->name == $b->name) {
                if(property_exists($a, "kill_me") != true && property_exists($b, "kill_me") != true) {
                    //resolve overlaps
                    if(max($a->s, $b->s) <= min($a->e, $b->e)) {
                        $a->s = min($a->s, $b->s); 
                        $a->e = max($a->e, $b->e);
                        $b->kill_me = true;
                        if(property_exists($b, "uuid") && $b->uuid != null) 
                            $a->uuid = $b->uuid;
                    }
                }
            }
            return $a->s <=> $b->e;
        });
    
        foreach($sorted as $mentionObj) {
            if(isset($mentionObj->kill_me) && $mentionObj->kill_me == true) 
                continue;       
            $soff = ($mentionObj->s + ($stopRemove + $stopAdded));
            $soff2 = ($mentionObj->s-1) + ($stopRemove + $stopAdded);
            if($soff >=0  && uc($block->text, $soff) != '@' && uc($block->text, $soff2) == '@') {
                $mentionObj->s--;
            }
            $removeLength = $mentionObj->e - $mentionObj->s; 
            $removeText = usub($block->text, $mentionObj->s, $removeLength);                
            $stopstart = $mentionObj->s + ($stopRemove + $stopAdded); 
            $stopRemove -= $removeLength;
            $content_text_stopword_mention = usubstr_replace($content_text_stopword_mention, $stopword, $stopstart, $removeLength);
            $stopAdded += mb_strlen($stopword); 
            if(uc($removeText, 0) == '@') { 
                //conditionally remove the @ prefix and update the augment offsets if needed
                $content_text_augmented_mention= usubstr_replace($content_text_augmented_mention, '', $augmentAdded+$mentionObj->s, 1);
                $augmentAdded--;
            }
            $content_text_augmented_mention = usubstr_replace($content_text_augmented_mention, $augmentWord, $mentionObj->e+$augmentAdded, 0);
            $augmentAdded += $augmentLength;
            $inline_links[] = normalizeURL($mentionObj->url);
            unset($mentionObj->t, $mentionObj->s, $mentionObj->e);
            $mentions[] = $mentionObj;            
        }
    }
    return [
        $content_text_stopword_mention, $content_text_augmented_mention, $content_text_regular, $inline_links, $mentions
    ];
}


$subtype_map = [
    "heading1" => "h1",
    "heading2" => "h2", #Intended for section subheadings.
    "quirky" => "quirky", #Tumblr Official clients display this with a large cursive font.
    "quote" => "q", #Intended for short quotations, official Tumblr clients display this with a large serif font.
    "indented" => "ind", #Intended for longer quotations or photo captions, official Tumblr clients indent this text block.
    "chat" => "chat", #Intended to mimic the behavior of the Chat Post type, official Tumblr clients display this with a monospace font.
    "ordered-list-item" => "ol", #Intended to be an ordered list item prefixed by a number, see next section.
    "unordered-list-item" => "ul", #Intended to be an unordered list item prefixed with a bullet, see next section.
    "poll" => 'poll',
    'question' => 'pq', //poll question,
    'answers' => 'al',  //answer list,
];

//converts the tumblr post and its trail to a format suitable for jsonb storage.
function transformNPF($post, $db_post_obj) {
    $compact = (object)[];
    //$compact->self = new stdClass();
    $compact->self = pruneSubPostToJSONB($post, $db_post_obj);
    $compact->trail = [];
    
    foreach($post->trail as $item) {
        $compact->trail[] = pruneSubPostToJSONB($item, $db_post_obj);
    }
    return $compact;
}

/**attempts to repair auto-inferred mentions to link to the deactivated form of the blog if it's detected in the hints list
 * hints list is expected to be of the form ["pre-deactivation-name" => [//post deactivation blog info]]
*/

function repair_mention_links(&$mention_info, $hints) {
    $name = $mention_info->name;
    if(isset($hints[$name])) {
        $matched = $hints[$name];
        $mention_info->url = str_replace("$name.t", "$matched.t", $mention_info->url);
        $mention_info->url = str_replace(".com/$name", ".com/$matched", $mention_info->url);
        $mention_info->url = str_replace(".co/$name", ".co/$matched", $mention_info->url);
    }
}

//converts the tumblr npf post/trail item into a format more easily parseable for jsonb storage
function pruneSubPostToJSONB($post, &$db_post_obj) {
    global $blog_uuid;
    global $subtype_map;
    
    $db_media = $db_post_obj->media_by_url;
    $respost = (object)[];
    $respost->content = [];
    $respost->by = $post->blog->name ?? ($post->blog->broken_blog_name ?? "tumblr user");
    $typeCount = [];
    // Handle the main content
    foreach ($post->content as $block) {
        $item = (object)[];
        switch ($block->type) {
            case 'text':
                $item->t = 'txt';
                $item->c = $block->text;
                if (isset($block->subtype)) {
                    $item->s = $subtype_map[$block->subtype];
                }
                if (isset($block->indent_level)) {
                    $item->il = $block->indent_level;
                }
                if (isset($block->formatting)) {
                    $item->frmt = setPrunedFormatting($block);
                    if(count($db_post_obj->deactivation_hints) > 0) {
                        foreach($item->frmt as &$f) {
                            if($f->t == 'ment') {
                                //attempt to fix effective mentions / links to other blogs if the url was deactivated
                                repair_mention_links($f, $db_post_obj->deactivation_hints);
                            }
                        }
                    }
                }
                break;
            case 'poll':
                $item->t = 'poll';
                $item->pq = $block->question;
                $item->al = [];
                foreach($block?->answers as $answer) {
                    $item->al[] = $answer?->answer_text;
                } 
                break;
            case 'image':
                $image_id = null;
                $item->db_id = array_shift($db_media[normalizeUrl($block->media[0]->url)]);
                
                $item->t = 'img';
                //$item->u = $block->media[0]->url;
                $item->w = $block->media[0]->width;
                $item->h = $block->media[0]->height;
                //$item->alt = $block->alt_text;
                //$item->caption = $block->caption;
                break;
            case 'link':
                $item->t = 'lnk';
                $item->db_id = array_shift($db_media[normalizeURL($block->url)]);
                if(property_exists($block, "poster")) {
                    $poster = $block->poster[0];
                    if(strpos($poster->type, "image") || strpos($poster->type, "video")) {
                        $item->w = $poster->width;
                        $item->h = $poster->height;
                    }
                }
                break;
            case 'audio':
                $item->t = 'aud';
                $url = isset($block->url) ? $block->url : $block->media->url;
                $item->db_id = array_shift($db_media[normalizeURL($url)]);
                break;
            case 'video':
                $item->t = 'vid';
                $url = isset($block->url) ? $block->url : $block->media->url;
                $item->db_id = array_shift($db_media[normalizeURL($url)]);
                $cont = $block?->poster[0] ?? $block?->attribution ??  NULL;
                if(is_array($cont)) $cont = $cont[0];
                if($cont != null && property_exists($cont, "height")) {
                    $item->w = $cont->width;
                    $item->h = $cont->height;
                }
                break;
        }
        $respost->content[] = $item;
    }
    $respost->layout = $post->layout;

    return $respost;
}



/**
 * returns a pruned version of the formatting hint array on the provided content block
 * quirks: 
 *  - collapses links and mentions into basically identical formats
 *  - replaces hardcoded urls with media_ids for any links
 *  - for any @mentions, includes a blog_uuid 
 *  - for any old style mentions, does not include a blog_uuid, does still use a media_id
 * 
 * @return array pruned formatting array
*/
function setPrunedFormatting (&$block) {//, &$db_media) {
    global $formatting_map;
    $pruned = [];
    if(isset($block->formatting)) {
        foreach($block->formatting as $f) {
            $p = (object)[]; 
            $p->t = $formatting_map[$f->type];
            $p->s = $f->start;
            $p->e = $f->end;
            if($f->type == 'mention') {
                $p->uuid = $f->blog->uuid;
                $p->url = normalizeUrl($f->blog->url);
                $p->name = $f->blog->name;
                if(property_exists($f->blog, "uuid"))
                    $p->uuid = $f->blog->uuid;
            }
            else if($f->type == 'link') {
                $modded_link = resolve_blog_or_link($block, $f, $p);
                if($modded_link->t == "ment") {
                    //add the fake mention as a seperate entry
                    $pruned[] = $modded_link;
                } 
                $modded_link->t = $formatting_map[$modded_link->t];
                $p->url = $modded_link->url;
            }
            else if($f->type == 'color') {
                $p->hex = $f->hex;
            }
            $pruned[] = $p;
        }
    }
    return $pruned;
}


/**
 * return an additional pruning format entry to a fake mention in the content item if it links to a blog and doesn't have position identical to the full link, 
 * modifies the link to be a mention if it links to a blog and does have the same position values as the full link and returns nothing
 * or returns nothing if the link is not to a blog
 */
function resolve_blog_or_link($block, &$f_rule, $p) {
    global $formatting_map;
    $mentionObj = is_effective_mention($block, $f_rule);
    $isMention = $mentionObj != false;
    if($isMention != false) { 
        if($mentionObj->s == $f_rule->start && $mentionObj->e == $f_rule->end) {
            $p->t = 'ment';
            $p->url = normalizeURL($mentionObj->url);
            $p->name = $mentionObj->name;
            return $p;
        } else {
            $pres = clone $p;
            $pres->url = normalizeUrl($f_rule->url);
            return $pres;
        }
    }
    $pres = clone $p;
    $pres->url = normalizeUrl($f_rule->url);
    return $pres;
}

$fake_mention1 = '/^(https?:\/\/)?(www\.)?tumblr\.com\/(blog\/)?([a-z0-9-]+)(\/.*)?$/i';
$fake_mention2 = '/^(https?:\/\/)?(www\.)?([a-z0-9-]+)\.tumblr\.com(\/.*)?$/i';
/**returns false if the provided formatting rule does not specify a link which effectively resolves to a mention 
 * otherwise returns an object specifying mention start position, end position, blog_name, and blog_url
*/
function is_effective_mention($block, $f_rule) {
    global $fake_mention1;
    global $fake_mention2;
    $blog_name = '';
    $blog_url = '';
    $makeMention = false;
    if($f_rule->type == 'link') {
        $text = isset($block->c) ? $block->c : $block->text;
        if (preg_match($fake_mention1, $f_rule->url, $matches)) {
            $namepart = preg_split('/[^\w-]+/', $matches[4])[0];
            $makeMention = true;
            $blog_url = normalizeUrl($f_rule?->url);
            $blog_name = $namepart;
        } 
        if(!$makeMention) {
            if(preg_match($fake_mention2, $f_rule?->url, $matches)) {
                $namepart = preg_split('/[^\w-]+/', $matches[3])[0];
                $makeMention = true;
                $blog_url = normalizeUrl($f_rule?->url);
                $blog_name = $namepart;
            }
        }
        if($makeMention) {
            
            $linktext = usub($text, $f_rule->start, $f_rule->end - $f_rule->start);
            $username_pos_start = strpos($linktext, $blog_name);
            $raw_url_pos_start = strpos($linktext, "blr.co");
            if($username_pos_start !== false && $raw_url_pos_start === false) {
                $username_pos_start = strpos($text, $linktext) + $username_pos_start; //avoid any potential mentions of the username outside of the link range 
                $username_pos_end = $username_pos_start + mb_strlen($blog_name);
                if($text[$username_pos_start] != '@' && $text[$username_pos_start-1] == '@')
                    $username_pos_start--; 
                $result = (object)[]; 
                $result->t = 'mention';
                $result->s = $username_pos_start;
                $result->e = $username_pos_start + mb_strlen($blog_name); 
                $result->url = normalizeUrl($blog_url);
                $result->name = $blog_name;
                return $result;
            } else {
                return false;
            }
        }
    } else if($f_rule->type == 'mention') {
        $result = (object)[]; 
        $result->t = $f_rule->type;
        $result->s = $f_rule->start;
        $result->e = $f_rule->end;
        $result->url = normalizeUrl($f_rule->blog->url);
        $result->name =$f_rule->blog->name;
        $result->uuid = null;
        if(property_exists($f_rule, "blog") && property_exists($f_rule->blog, "uuid"))
            $result->uuid =$f_rule?->blog?->uuid;
        return $result;
    }
    else return false;
}


function get_db_type_for_block($block) {
    if($block->type == "text") {
        $result = ["type"=> "txt", "val" => $block->text."\n"]; 
        return [$result];
    }
    $propety_map = [
        "title" => "text",
        "description" => "text",
        "alt_text" => "image",
        "caption" => "image"
    ];
    $results = [];
    foreach($propety_map as $k => $v) {
        if($block->{$k} != null) {
            if($k == "alt_text" && $block->{$k} == "image") 
                continue; //wow that's annoying. sometime images are just alt_texted with "image"
            $results[] = ["type" => $v, "val" => $block->{$k}."\n"];
        }
    }

    return $results;
}