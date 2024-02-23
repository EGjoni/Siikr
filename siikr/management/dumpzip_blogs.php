<?php

require_once '../internal/globals.php';
$db = get_db();
$basePath = "/mnt/volume_sfo3_01/archived/";

function prompt($message) {
    echo $message;
    $handle = fopen("php://stdin", "r");
    $input = trim(fgets($handle));
    fclose($handle);
    return $input;
}

function fetchBlogInfo($identifier, $db) {
    if (strpos($identifier, 't:') === 0) {
        $stmt = $db->prepare("SELECT blog_name, blog_uuid FROM blogstats WHERE blog_uuid = :identifier");
    } else {
        $stmt = $db->prepare("SELECT blog_name, blog_uuid FROM blogstats WHERE blog_name = :identifier");
    }
    $stmt->execute(['identifier' => $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function createFifo($fifoPath) {
    if (!file_exists($fifoPath)) {
        posix_mkfifo($fifoPath, 0666);
    }
}

function exportData($blogInfo) {
    global $basePath, $db_name, $db_user;

    $post_columns = [
        "post_id", "blog_uuid", "tag_text", "post_date", "post_url",
        "archived_date", "reblog_key", "slug", "has_text", "has_ask",
        "has_link", "has_images", "has_video", "has_audio", "has_chat",
        "blocks", "html"
    ];

    $images_columns = ['image_id', 'img_url'];

    $blogName = $blogInfo['blog_name'];
    $blogUuid = $blogInfo['blog_uuid'];

    $exportQueries = [
        "posts" => "SELECT ".implode(',', $post_columns)." FROM posts WHERE blog_uuid = '$blogUuid'",
        "images_posts" => "SELECT ip.* FROM images_posts ip JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid'",
        "images" => "SELECT DISTINCT i.".implode(', i.',$images_columns).", CAST(EXTRACT(EPOCH FROM i.date_encountered) AS INT) as millis_timestamp FROM images i JOIN images_posts ip ON i.image_id = ip.image_id JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid'"
    ];

    $tempArchivePath = "$basePath/temp/{$blogName}__{$blogUuid}.tar";
    $compressedArchivePath = "$basePath/zippes/{$blogName}__{$blogUuid}.tar.gz";

    // Prepare and execute psql command, and compress each query result into a single .tar.gz
    foreach ($exportQueries as $tableName => $query) {
        $fullCommand = "psql -d '$db_name' -U '$db_user' -c " . escapeshellarg($query) . " | gzip > '{$basePath}/temp/{$tableName}'";
        system($fullCommand);
    }

    // Create a tar archive from the gzipped files
    $tarCommand = "tar -cf $tempArchivePath -C '{$basePath}/temp/' " . implode(' ', array_map(function ($tableName) { return "{$tableName}.gz"; }, array_keys($exportQueries)));
    system($tarCommand);

    // Compress the tar archive
    $gzipCommand = "gzip $tempArchivePath";
    system($gzipCommand);

    return $compressedArchivePath;
}


$identifiers = $argc > 1 ? array_slice($argv, 1) : [];
if (empty($identifiers)) {
    $blogName = prompt("Enter the blog name: ");
    $identifiers[] = $blogName;
}

foreach ($identifiers as $identifier) {
    $blogInfo = fetchBlogInfo($identifier, $db);
    if (!$blogInfo) {
        echo "Blog not found for identifier: $identifier\n";
        continue;
    }

    $archivePath = exportData($blogInfo, $db);
    echo "Data archived to $archivePath\n";
}
