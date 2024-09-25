<?php
require_once __DIR__."/../spoke_siikr/broadcast.php";
$db = new SPDO("pgsql:dbname=$db_name", $db_user, $db_pass);

$all_uuids = $db->query("SELECT blog_uuid FROM blogstats WHERE blog_uuid NOT IN (SELECT blog_uuid FROM blog_node_map)")->fetchAll(PDO::FETCH_COLUMN);

foreach($all_uuids as $uuid) {
    call_me_back($uuid, "siikr.giftedapprentice.com");
}