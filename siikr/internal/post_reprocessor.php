<?php
require_once 'post_processor.php';


/**
 * returns a version of this siikr content sub block that looks a bit more like
 * tumblr's. Not a full or robust conversion, just enough that the downstream content extraction code can be recycled
 */
function subblock_to_tumblr(&$siikr_block, $db_media_map, &$soFar) {
    $sblock = (object)unserialize(serialize($siikr_block));
    $tumblrized_block = (object)[];
    $media_text = "";
    if( property_exists($sblock, "t") ) {
        $tumblrized_block->type = $sblock->t;
        if ($sblock->t == "txt") {
            $tumblrized_block->type = "text";
            $tumblrized_block->text = $sblock->c;
        } else if($sblock->t == "img" || 
        $sblock->t == "vid" || 
        $sblock->t == "aud" || 
        $sblock->t == "lnk") {
            $tumblrized_block = &$siikr_block; //change the content of the actual json object that will be inserted into the db. Messy, but whatever.
            $media_obj = $db_media_map[$sblock->db_id];
            $tumblrized_block->db_id = $media_obj->media_id;
            if(isset($media_obj->media_meta["title"]))
                $media_text .= "\n ".$media_obj->media_meta["title"];
            if(isset($media_obj->media_meta["description"]))
                $media_text .= "\n ".$media_obj->media_meta["description"];
        }

        if(property_exists($sblock, "frmt")) {
            foreach($sblock->frmt as $f) {
                if($f->t == 'ment') {
                    $formatObj = (object)[];
                    $formatObj->type = "mention";
                    $formatObj->start = $f->s;
                    $formatObj->end = $f->e;
                    $formatObj->blog = (object)[];
                    $formatObj->blog->name = $f->name;
                    $formatObj->blog->url = $f->url;
                    if(property_exists($f, "uuid"))
                        $formatObj->blog->uuid = $f?->uuid;
                    @$tumblrized_block->formatting[] = $formatObj;
                }
            }
        }
    }
    return [&$tumblrized_block, $media_text];
}

function extract_media($subpost, &$soFar) {

}

function extract_siikr_subblocks_from_subpost(&$subpost, $db_media_map, &$soFar, $mode) {
    $text_content = ["no_mentions" => [], "with_mentions" => [], 
    "text_regular" => [], /*kind of redundant because is equivalent to what either 
    of the other two strings will be if there aren't any mentions, but might as well be explicit*/
    "media_text" => [],/*it's acceptable to have this be out of order with the text content blocks because each media item is 
    it's own block, and we store in a seperate column in the database anyway*/
    ];
    $content = &$subpost->content;
    $blockct = 0;
    $dibsArr = []; //stores string objects that should be inserted before a block of a given index.
    
    foreach($subpost->layout as $lay_item) {
        if($lay_item->type == "ask") {
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
   
    foreach ($content as &$sikkrblock) {
        list($block, $media_text) = subblock_to_tumblr($sikkrblock, $db_media_map, $soFar);
        $text_content["media_text"][] = $media_text;
        try {
            if (property_exists($block, 'text')) {                
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
                    $content_text = $sikkrblock->pq;
                    foreach($sikkrblock?->al as $answer) {
                        $content_text .= $answer."\n";
                    }
                    $text_content["no_mentions"][] = $content_text;
                    $text_content["with_mentions"][] = $content_text; 
                    $text_content["text_regular"][] =  $content_text;
                    continue; 
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



/**
 * returns the same sort of information as extract_db_content_from_post 
 * but taking a siikr formatted post entry for input
*/
function extract_db_content_from_siikr_post(&$post, $db_media_map) {
    global $possible_encodings;
    global $RENUM;
    $soFar = 
    ["self_text" => "", 
    "trail_text" => "",
    "title" => "",
    "has_text" => $RENUM[$post->has_text], 
    "has_link" => $RENUM[$post->has_link], 
    "has_chat" => $RENUM[$post->has_chat],
    "has_ask" => $RENUM[$post->has_ask], 
    "has_images" => $RENUM[$post->has_images],
    "has_audio" => $RENUM[$post->has_audio],
    "has_video" => $RENUM[$post->has_video],
    "media" => [],
    "media_refs" => [], //this holds direct references to the media arrays within each post object
    "mentions" => []];

    list(
        $self_text_no_mention, 
        $self_text_augmented_mentions, 
        $self_text_regular,
        $self_media_text) = extract_siikr_subblocks_from_subpost($post->blocks->self, $db_media_map, $soFar, $mode=0b01);
    $self_mentions = $soFar["mentions"];
    $self_media = $soFar["media"];
    $soFar["mentions"] = [];
    $soFar["media"] = [];
    $deactivationHints = [];
    $trail_text_no_mention = "";
    $trail_text_augmented_mentions = "";
    $trail_text_regular = "";
    $trail_media_text = "";
    if(property_exists($post->blocks, "trail")) {
        $soFar["trail_users"] =[];
        foreach ($post->blocks->trail as &$trail_item) {
            if(@$trail_item?->blog?->name != null) {
                $soFar["trail_users"][$trail_item->blog->name] = true;
                addHintCandidate($trail_item->blog->name, $deactivationHints);
            } else if (@$trail_item?->by != null) {
                $soFar["trail_users"][$trail_item->by] = true;
                addHintCandidate($trail_item->by, $deactivationHints);
            } else {
                echo "----------bork\n, username unavailable";
            }
            list(
                $_trail_text_no_mention, 
                $_trail_text_augmented_mentions, 
                $_trail_text_regular,
                $_trail_media_text) = extract_siikr_subblocks_from_subpost($trail_item, $db_media_map, $soFar, 0b10);
                $trail_text_no_mention .= "\n$_trail_text_no_mention";
                $trail_text_augmented_mentions .= "\n$_trail_text_augmented_mentions";
                $trail_text_regular .= "\n$_trail_text_regular";
                $trail_media_text .= "\n$_trail_media_text";
        }
        $soFar["trail_usernames"] = implode(" " , array_keys($soFar["trail_users"]));
    }

    $trail_array = $post->blocks->trail ?? [];
    $self = $post->blocks->self;
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
    return $asObj;
}