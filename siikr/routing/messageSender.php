<?php 
session_start();
if(!@$_SESSION['user']) {
session_write_close();
    die("user not logged in");
}
session_write_close();
//session_start();
header("Cache-Control: no-cache");
//header("Connection: keep-alive");
define("SERVER_EVENTS_PORT", "tcp://127.0.0.1:5550");
//define("SERVER_EVENTS_PORT", "ipc:///tmp/serverEvents.sock");
ini_set('log_errors', 0);
ini_set("error_log", "/var/www/PHP_ERRORS.log");
//$serverEventsPort = "ipc:///tmp/serverEvents.sock";
$evtPatts = $_REQUEST["e"]; 
//error_log("CONNECTED ! ");
$evtCount = sizeof($evtPatts);
if($evtCount > 0) {
    $messageSenderContext = new ZMQContext(1, true);
    $sessID = $_COOKIE['PHPSESSID'];
    $messageSenderSocket= new ZMQSocket($messageSenderContext, 
    ZMQ::SOCKET_PUSH, 'mss'.$sessID, 'initializeMessageSenderSockets');

    $msgs = $_REQUEST["m"];    
    for($i=0; $i<$evtCount; $i++) {
        $eventPattern = $evtPatts[$i];
        if(isAllowed($eventPattern)) {
            $message = $msgs[$i];
            //echo "messageSender: sending message:\n eventPattern- $eventPattern \n message- $message";
            $messageSenderSocket->send($eventPattern."  ".$message); 
        } else {
            echo "Error: Illegal eventPattern : \"$eventPattern\" \n
            The eventPattern you specified is reserved for issue by the server only.";
        }
        //$ack =  $server_message_socket->recv();
    }
}

function isAllowed($eventPattern) {
    $reserved = ["UPDATE!", "INSERT!", "DELETE!", "REORDER!"];
    $ep = trim($eventPattern); 
    $matches = strpos($sKey, $wKey, 0);//, strlen($subscriberPatt), false);
    for($i = 0; $i<sizeof($reserved); $i++) {
        $r = $reserved[$i]; 
        if(strpos($ep, $r, 0)) {
            return false;
        }
    }
    return true;
}

function initializeMessageSenderSockets(ZMQSocket $socket, $persistent_id = null) {
    $socket->setSockOpt(ZMQ::SOCKOPT_LINGER, 1001);
    $socket->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL, 100);
    $socket->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL_MAX, 2000);    
    $socket->connect(SERVER_EVENTS_PORT);
};

/**
 * $messageArray should be an array of associative Arrays. 
 * 
 * The associative arrays should be of the form
 * [eventPattern => (someString),
 *  message => (someString)]; 
 * 
 * Each should will be formatted and sent off as a seperate event.
 * 
 * `eventPattern` should be a string, 
 * `message` should be a PHP associative array containing 
 * at the very least the data that was passed into the function 
 * that fired the event. This associative array will be serialized 
 * as a JSON object when it is fired.
 *
 */

?>
