<?php
require_once "squid_game.php";
try {
    require_once 'internal/SPDO.php';
    $db = getDb();
    $nodes = $db->query("SELECT node_id, node_url, node_name, node_flare, total_space_mb, free_space_mb, down_for_maintenance from siikr_nodes")->fetchAll(PDO::FETCH_OBJ);
    $nodes = $nodes == false ? [] : $nodes;
    $nodes_j = json_encode($nodes);
} catch (Exception $e) {
    ?>
    <h1>NO NODES FOUND. Maybe you're not a real hub?</h1>
    <?php
}

$injection_base = 'meta_siikr/meta_';
$injectable = "
var subdir = 'meta_siikr/meta_';
var node_list = $nodes_j;";
if(isset($_GET['lemmein']) && $_GET['lemmein'] == true) {
    require_once "show_page.php";
} else if($squid_game_warn == false && $squid_game == true) {
    require_once "maintenance.php";
} else {
	?>
    <!--<span style="color: #FFFA;font-family: sans;text-align: center;filter: drop-shadow(0px 0px 14px white) drop-shadow(0px 0px 18px black) drop-shadow(0px 0px 4px black);">
    <h1 style="color: #FFF8;font-family: sans-serif;font-weight: 100;"> B to the R to the B</h1>
      <h1 style="color: #FFF8;font-family: sans-serif;font-weight: 100;">In Defiance of Daedalus </h1>
        <h1 style="color: #FFFC;font-family: sans;font-weight: 500;">— In Pride and Awe and Ecstasy —</h1> 
        <h1 style="color: #FFFC;font-weight: 400;font-size: 2.2em;font-family: system-ui;">Siikr Has Flown Too Close to the Sun </h1>
        <h2 style="color: #FFFB;font-family: sans-serif;font-weight: 100;font-family: math;">And Even Now</h2> 
        <h2 style="color: #FFF7;/* font-family: sans-serif; */font-family: serif;"><i>Soars Closer Still</i> </h2>
        <br>
        <h3 style="color: #FFFA; font-family: sans-serif;">(BBL, crunching really big numbers)</h3>
    </span> -->

<?php
    require_once "show_page.php";
    /*if (rand(0, 1) == 0) {
        require_once "show_page_boxxy.php";
    } else {
        require_once "show_page_pounce.php";
    }*/
}
?>