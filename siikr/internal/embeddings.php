<?php
#Experimental, don't worry about it.
require_once 'globals.php';
$batch_size = 10;
$userInfo = posix_getpwuid(posix_geteuid());
$credentials = base64_encode("$clip_host_username:$clip_host_password");
$options = [
    'http' => [
        'header'  => "Authorization: Basic $credentials\r\n" .
                     "Content-type: application/json\r\n",
        'method'  => 'POST',
        `content` => json_encode([]),
        'ignore_errors' => true,
    ]
];
$db = new PDO("pgsql:dbname=$db_name", $userInfo["name"], null);

$unembedded = $db->prepare("SELECT * FROM images WHERE clip_attempted IS NULL LIMIT 10000"); 
$set_clip_attempted = $db->prepare("UPDATE images SET clip_attempted = now() WHERE image_id = :image_id");
$init_embedding = $db->prepare("INSERT INTO clip_embeddings (image_id) VALUES (:image_id) RETURNING clip_id");
$to_send = ["image_urls" => [], "embedding_uuids" => []];
$images_pending = [];
$firstcontact = 0;
do {
    $unembedded->execute([]);
    $imagebatch = $unembedded->fetchAll(PDO::FETCH_OBJ);
    foreach($imagebatch as $image_row) {
        $images_pending[] = $image_row;        
        if(sizeof($images_pending) >= $batch_size) {
            send_batch();
            sleep(3);
            if($firstcontact==0)
                sleep(5);
            $firstcontact=1;
        }
    }
} while (!empty($imagebatch));

send_batch();

function send_batch() {
    global $db, $images_pending, $to_send, $init_embedding, $set_clip_attempted, $clip_embedding_host, $options;
    $db->beginTransaction();
    foreach($images_pending as $img) {
        $init_embedding->execute(["image_id"=> $img->image_id]);
        $clip_id = $init_embedding->fetchColumn();
        $to_send["image_urls"][] =  $img->img_url;
        $to_send["embedding_uuids"][] =  $clip_id;
        $set_clip_attempted->execute(["image_id" => $img->image_id]);
    }
    try {
        $jtoSend = json_encode($to_send);
        $options['http']['content'] = $jtoSend;        
        echo "sent $clip_id";
        $context  = stream_context_create($options);
        $response = file_get_contents("$clip_embedding_host/get_image_embedding", false, $context);
        if($response == false) 
            throw new Exception("failed at something");
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
    }
    
    $to_send = ["image_urls" => [], "embedding_uuids" => []];
    $images_pending = [];
}