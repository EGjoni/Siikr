<?php
try {
    include_once 'auth/config.php';
    $no_config = false;
} catch (Exception $e) {}
require_once "squid_game.php";
$injection_base = '';
$injectable = '
var subdir = "";
var server_events_override_url = undefined;';
if(isset($_GET['lemmein']) && $_GET['lemmein']==true) {
    require_once "show_page.php";
} else if($squid_game_warn == false && $squid_game == true) {
    require_once "maintenance.php";
} else {
	?>
    <!--<span style="color: #FFFA;font-family: sans;text-align: center;filter: drop-shadow(0px 0px 14px white) drop-shadow(0px 0px 18px black);">
        <h1 style="color: #FFF8;font-family: sans-serif;font-weight: 100;">In Defiance of Daedalus </h1>
        <h1 style="color: #FFFC;font-family: sans;font-weight: 500;">— In Pride and Awe and Ecstasy —</h1> 
        <h1 style="color: #FFFC;font-weight: 400;font-size: 2.2em;font-family: system-ui;">Siikr Has Flown Too Close to the Sun </h1>
        <h2 style="color: #FFFB;font-family: sans-serif;font-weight: 100;font-family: math;">And Even Now</h2> 
        <h2 style="color: #FFF7;/* font-family: sans-serif; */font-family: serif;"><i>Soars Closer Still</i> </h2>
        <br>
        <h3 style="color: #FFFA; font-family: sans-serif;">(BBL, crunching really big numbers)</h3>
    </span>-->

<?php
    if(isset($node_in_maintenance_mode) && $node_in_maintenance_mode) $node_maintenance_mode_reminder = true;
    require_once "show_page.php";
    /*if (rand(0, 1) == 0) {
        require_once "show_page_boxxy.php";
    } else {
        require_once "show_page_pounce.php";
    }*/
}
?>
