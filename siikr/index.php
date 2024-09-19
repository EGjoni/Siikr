<?php
$no_config = true; 
try {
    include_once 'auth/config.php';
    $no_config = false;
} catch (Exception $e) {
    require_once 'index_local.php';
}

if($no_config == false) {
    if(isset($default_meta) && $default_meta == true) {
        require_once 'index_meta.php'; 
    } else {
        require_once 'index_local.php';
    }
}
?>
