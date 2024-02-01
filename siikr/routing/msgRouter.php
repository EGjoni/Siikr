<?php
//phpInfo();
//$serverPullPort = "tcp://127.0.0.1:5551";
$serverEventsPort = "tcp://127.0.0.1:5550";
$clientSubscriptionPort = "tcp://127.0.0.1:5558";
$nowherePort = "tcp://127.0.0.1:5559";
$lastHeartBeat = microtime(1) - 10;
//$serverEventsPort = "ipc:///tmp/serverEvents.sock";
//$clientSubscriptionPort = "ipc:///tmp/subscriptions.sock";


$context = new ZMQContext(1, true);
$server_inputs  = $context->getSocket(ZMQ::SOCKET_PULL);
$client_outputs = $context->getSocket(ZMQ::SOCKET_PUB);
$nowhere = $context->getSocket(ZMQ::SOCKET_PUB);

$server_inputs->setSockOpt(ZMQ::SOCKOPT_HWM, 10000);
$server_inputs->setSockOpt(ZMQ::SOCKOPT_RCVTIMEO, 5000);
$server_inputs->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL, -1);
$client_outputs->setSockOpt(ZMQ::SOCKOPT_HWM, 10000);
$client_outputs->setSockOpt(ZMQ::SOCKOPT_SNDTIMEO, 50);
$client_outputs->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL, -1);
$nowhere->setSockOpt(ZMQ::SOCKOPT_HWM, 1);


$server_inputs->bind($serverEventsPort);
$client_outputs->bind($clientSubscriptionPort);
$nowhere->bind($nowherePort);
error_log("STARTED MESSAGEROUTER");
echo "bound \n";
$count = 0;
while(true) {
    //error_log("awaiting:" .$count."");
    $message = $server_inputs->recv();
    /*if($message) {
        //echo "\n responding to $message";
        $server_inputs->send("ACK");
    }*/
    $currentTime = microtime(1);    
    $delta = $currentTime - $lastHeartBeat;    
    if($message == false/*startsWith($message, "???beacon???")*/) {
        /*if($delta > 5) {
            $lastHeartBeat = $currentTime;
            $delta = 0;
            //error_log("broadcasting: $message \n");
            $client_outputs->send($message);
        } else {*/
            //echo "suppressed beacon due to insufficient delta: $delta";
            $nowhere->send($message);
        //}       
    } else if(strlen($message) > 0) {
        echo "broadcasting: $message \n";
        //error_log("broadcasting: $message \n");  
        $client_outputs->send($message);
    }    
    
    /*if($delta > 5) {
        //echo "brodcasting: ???beacon???  boot loiterers\n";
        $client_outputs->send("???beacon???  boot loiterers");
        $lastHeartBeat = $currentTime;
    }    */
    $count = $count+1;
}
 

function startsWith($haystack, $needle)
{
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}





exit(1);