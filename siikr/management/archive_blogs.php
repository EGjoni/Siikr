<?php

function prompt($message) {
    echo $message;
    $handle = fopen("php://stdin", "r");
    $input = trim(fgets($handle));
    fclose($handle);
    return $input;
}

function fetchBlogInfo($identifier, $db) {
    if (strpos($identifier, 't:') === 0) {
        // Assume it's a blog_uuid
        $stmt = $db->prepare("SELECT blog_name, blog_uuid FROM blogstats WHERE blog_uuid = :identifier");
    } else {
        // Assume it's a blog_name
        $stmt = $db->prepare("SELECT blog_name, blog_uuid FROM blogstats WHERE blog_name = :identifier");
    }
    $stmt->execute(['identifier' => $identifier]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}



function exportData($blogInfo) {
    $post_columns = [ 
    "post_id",
    "blog_uuid",
    "tag_text",
    "post_date",
    "post_url",
    "archived_date",
    "reblog_key",
    "slug",
    "has_text",
    "has_ask",
    "has_link",
    "has_images",
    "has_video",
    "has_audio",
    "has_chat",
    "blocks",
    "html"];
    
    $images_columns = ['image_id', 'img_url', 'date_encountered'];
   
    // Paths and filenames
    $basePath = "/var/www/archived/"; // Adjust the base path
    $blogName = $blogInfo['blog_name'];
    $blogUuid = $blogInfo['blog_uuid'];

    // Define export queries for each table
    $exportQueries = [
        'posts' => "COPY (SELECT ".implode(',', $posts)." FROM posts WHERE blog_uuid = '$blogUuid') TO STDOUT WITH (FORMAT CSV, HEADER, DELIMITER E'\t')",
        'images_posts' => "COPY (SELECT ip.* FROM images_posts ip JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid') TO STDOUT WITH (FORMAT CSV, HEADER, DELIMITER E'\t')",
        'images' => "COPY (SELECT ".implode(', i.*',$images_columns)." FROM images i JOIN images_posts ip ON i.image_id = ip.image_id JOIN posts p ON ip.post_id = p.post_id WHERE p.blog_uuid = '$blogUuid') TO STDOUT WITH (FORMAT CSV, HEADER, DELIMITER E'\t')"
    ];


    $files = [];
    foreach ($exportQueries as $tableName => $query) {
        $filename = "{$blogName}__{$blogUuid}__{$tableName}.tsv";
        $filepath = "$basePath/temp/$filename";
        $command = "PGPASSWORD='$password' psql -h '$host' -d '$dbname' -U '$username' -c " . escapeshellarg($query) . " > " . escapeshellarg($filepath);
        
        system($command);
        $files[] = $filepath;
    }
    return $files;
}

function zipFiles($files, $blogInfo) {
    $zip = new ZipArchive();
    $zipFilename = "/var/www/archived/zipped/{$blogInfo['blog_name']}__{$blogInfo['blog_uuid']}.zip"; 
    if ($zip->open($zipFilename, ZipArchive::CREATE) !== TRUE) {
        exit("Cannot open <$zipFilename>\n");
    }

    foreach ($files as $file) {
        $zip->addFile($file, basename($file));
    }

    $zip->close();
    return $zipFilename;
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

    $files = exportData($blogInfo, $db);
    $zipFilename = zipFiles($files, $blogInfo);
    echo "Archived data to $zipFilename\n";
}

?>
