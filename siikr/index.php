<?php
require_once "squid_game.php";
if(isset($_GET['lemmein'])) {
    require_once "show_page.php";
} else if($squid_game_warn == false && $squid_game == true) {
    require_once "maintenance.php";
} else {
    require_once "show_page.php";
}
?>
