<?php
require_once '/var/www/siikr/internal/post_processor.php';
require_once '/var/www/siikr/internal/globals.php';
$possible_encodings =['UTF-8', 'ASCII', 'JIS', 'EUC-JP', 'SJIS', 'ISO-8859-1'];


function replacer($matches) {
    return sprintf("\\u%04x", ord($matches[0]));  // Converts the character to a unicode escape sequence
}
function fake_insert_media(&$db_post_obj, &$media_list) {
    global $stmt_insert_media;
    foreach ($media_list as &$med) {
        $media_item = (object)$med;
        $title = property_exists($media_item, "title") ? substr($media_item->title, 0, 250) : NULL;
        $description =  property_exists($media_item, "description") ? substr($media_item->description, 0, 1000) : NULL;
        $preview_url = property_exists($media_item, "preview_url") ? $media_item->preview_url : NULL;
        $media_id =  random_int(10, 9000);
        $med["db_id"] = $media_id;
        $db_post_obj->media_by_id[$media_id] = $media_item->media_url;
        $db_post_obj->media_by_url[$media_item->media_url][] = $media_id;
    }
}

//$post_obj = json_decode($jsonstr)->response->posts[0];
//echo substr($jsonstrblg, 2700, 1500);
$params = ["npf" => "true", "id" => 759260750978203648, "limit" => 50 /*"id" => 759260750978203648*/, "sort" => "desc", "notes_info" => "true"];
$reblog_obj = call_tumblr('antinegationism', 'posts', $params, false)->posts[0];//mb_convert_encoding($jsonstrblg, 'UTF-8', $possible_encodings);
$db_reblog_object = extract_db_content_from_post($reblog_obj);
fake_insert_media($db_reblog_object, $db_reblog_object->self_media_list);
fake_insert_media($db_reblog_object, $db_reblog_object->trail_media_list);
$npf = transformNPF($reblog_obj, $db_reblog_object);
echo "done";
