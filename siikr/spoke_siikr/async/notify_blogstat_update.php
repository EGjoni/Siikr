<?php
//require_once './../node_state.php';
$self_file = explode("/", $_SERVER["PHP_SELF"]); array_pop($self_file);
$self_dir = implode("/", $self_file);
require_once "$self_dir/../broadcast.php";
$blog_uuid = null;

if($argv[1] != null && $argv[2] != null) {
    $blog_uuid = $argv[1];
    $db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);
    //$blogstat_obj = build_blogstat_obj($db, $blog_uuid);
    call_me_back($blog_uuid, $argv[2]);
}