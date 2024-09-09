<?php

require_once '../internal/globals.php';
$userInfo = posix_getpwuid(posix_geteuid());
$db = new PDO("pgsql:dbname=$db_name", $userInfo["name"], null);
$basePath = "/mnt/volume_sfo3_02/archived/";
$outpath = "/var/www/archived";

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
    global $basePath, $outpath, $db_name;

    // Assuming $db_name is defined in 'globals.php' and accessible here
    $username = 'eron'; // Your PostgreSQL username
    $password = ''; // PostgreSQL password, if required

    $post_columns = [
        "post_id", "blog_uuid", "tag_text", "post_date",
        "archived_date", "reblog_key", "has_text", "has_ask",
        "has_link", "has_images", "has_video", "has_audio", "has_chat",
        "blocksb", "html"
    ];

    $images_columns = ['image_id', 'img_url'];

    $blogName = $blogInfo['blog_name'];
    $blogUuid = $blogInfo['blog_uuid'];

    $exportQueries = [
        "posts" => "SELECT ".implode(',', $post_columns)." FROM posts WHERE blog_uuid = '$blogUuid'",
        "images_posts" => "SELECT ip.* FROM images_posts ip JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid'",
        "images" => "SELECT DISTINCT i.".implode(', i.',$images_columns).", CAST(EXTRACT(EPOCH FROM i.date_encountered) AS INT) as millis_timestamp FROM images i JOIN images_posts ip ON i.image_id = ip.image_id JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid'"
    ];

    $tempArchivePath = "$outpath/temp/{$blogName}__{$blogUuid}.tar";
    $compressedArchivePath = "$outpath/temp/{$blogName}__{$blogUuid}.tar.gz";

    // Prepare and execute psql command, and compress each query result into a single .tar.gz
    foreach ($exportQueries as $tableName => $query) {
        echo "Selecting $tableName...\n";
        $fullCommand = "psql -d '$db_name' -U '$username' -c " . escapeshellarg($query) . " | gzip > '{$outpath}/temp/{$tableName}.gz'";
        system($fullCommand);
    }

    // Create a tar archive from the gzipped files
    echo "Compressing $tempArchivePath\n";
    $tarCommand = "tar -cf $tempArchivePath -C '{$outpath}/temp/' " . implode(' ', array_map(function ($tableName) { return "{$tableName}.gz"; }, array_keys($exportQueries)));
    system($tarCommand);

    // Compress the tar archive

    $gzipCommand = "gzip $tempArchivePath";
    system($gzipCommand);

    // Return the path of the compressed archive
    return $compressedArchivePath;
}

// Main
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
