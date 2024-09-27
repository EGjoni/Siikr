<?php
//require_once './../node_state.php';
require_once __DIR__."/../broadcast.php";
$blog_uuid = null;

if($argv[1] != null && $argv[2] != null) {
    $blog_uuid = $argv[1];
    $db = getDb();//new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
    //$blogstat_obj = build_blogstat_obj($db, $blog_uuid);
    call_me_back($blog_uuid, $argv[2]);
}