<?php 

require_once 'sharedEventFuncs.php';

/**
 * So here's a fun fact, ZMQ does message-filtering subscriber side. 
 * Is this stupid? Absolutely. Does it mean that having multiple subscription
 * sockets to poll through is therefore especially bad? 
 * 
 * PROBABLY!
 * 
 * So let's not do that! 
 */
ini_set('log_errors', 0);
//ini_set("error_log", "/var/www/PHP_ERRORS.log");
ini_set('max_execution_time', 60*60*7.5); //7.5 hours. Just shy of mysql's default wait_timeout. 

header("Cache-Control: no-cache");
header('Access-Control-Allow-Origin: *');
header("Access-Control-Allow-Headers: Content-Type");  // Explicitly allow Content-Type
header("Content-Type: text/event-stream");
header('X-Accel-Buffering: no');

$clientSubscriptionPort = "tcp://127.0.0.1:5558"; 
$myID = microtime(); 

//$clientSubscriptionPort = "ipsc:///tmp/subscriptions.sock";

ob_flush();
ob_end_flush();

while (ob_get_level()) {ob_end_clean();} 
flush();
ob_implicit_flush();

register_shutdown_function('shutdown');  


$rawInput = file_get_contents('php://input');
$baseInterests = isset($_GET["interests"]) ? $_GET["interests"] : null;
$input = $_POST ?: json_decode($rawInput, TRUE);

$generalVals = isset($input["gvl"]) ? $input["gvl"] : []; 
$paramVals = isset($input["pvl"]) ? $input["pvl"] : []; 
$paramKeys = isset($input["pkl"]) ? $input["pkl"] : []; 
$interestsJSON = isset($input["interests"]) ? $input["interests"] : json_decode($baseInterests);
$subInfo = $interestsJSON;
$wildcardMap = [];

if($subInfo == null) {
 shutdown();
 //error_log("subInfo specified");
} else {  

$context = new ZMQContext(1, false);

$omniListener = new ZMQSocket($context, ZMQ::SOCKET_SUB);
$omniListener->connect($clientSubscriptionPort);
$omniListener->setSockOpt(ZMQ::SOCKOPT_SUBSCRIBE, "");
$omniListener->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 1000);

$subscribersMap = [];
initializeSubcribersMap($subInfo);
amendSubscribersMapWithWildcardMatches();

//free up every last bit of memory we can
$generalVals = NULL;
$paramKeys = NULL;
$paramVals = NULL;
$interestsJSON = NULL;
$subInfo = NULL;
$input["gvl"] = NULL;
$input["pkl"] = NULL;
$input["interests"] = NULL;

//signals to the client that a connection has been made. 
echo "event: server side event connection established\n"; 
echo "data: {} \n\n";
ob_flush();
flush();

$readable = $writable = array();


$receivedCount = 0; 
$heartbeatCount = 0;
while (true) {
    $events = 0;    
                                   
        $message = $omniListener->recv(); 
        $msgArr = explode("  ", $message, 2);
        $eventPatt = $msgArr[0];         
        if($message != false) {                      
            $subscribers = getRelevantSubscribersForPattern($eventPatt." ");
            $subslen = sizeof($subscribers);
            for($i=0; $i< $subslen; $i++) {
                $callBackResult = executeFunctionFor($subscribers[$i], $msgArr[1]);
                printOutputFor($callBackResult);
            }
        } else {
            $heartbeatCount++;
            //error_log("EMPTY LOOPING ".$_COOKIE['PHPSESSID']);
            //error_log("hb$heartbeatCount");
            echo "hb$heartbeatCount\n"; //send an empty empty string 
            //because it's the only way for PHP to know 
            //if a connection has aborted.
            flush();
            if (connection_aborted()) {
                shutdown();    
            }                
        }      
    }
}




flush();


//socket->unbind(ZMQ::SOCKOPT_LAST_ENDPOINT);
//$socket->disconnect($endpoint);
