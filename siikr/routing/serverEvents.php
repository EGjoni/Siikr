<?php 
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
$input = $_POST ?: json_decode($rawInput, TRUE);

$generalVals = $input["gvl"]; 
$paramVals = $input["pvl"];
$paramKeys = $input["pkl"];
$interestsJSON = $input["interests"];
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

 function getRelevantSubscribersForPattern($eventPatt) {
     global $subscribersMap; 
     global $wildcardMap;
     $results = @$subscribersMap[$eventPatt]; 
     if($results == null) {
        $results = [];
        foreach($wildcardMap as $wildcardPatt => $funcList) {
            $matches = strpos($swildcardPatt, $eventPatt, 0);//, strlen($subscriberPatt), false);
            if($matches === 0) {
                $results = array_merge($results, $funcList);
            }
        }
    }
    return $results;
 }

 function decompress_event_pattern($compressed) {
    global $generalVals; 

    $explodedIndices = explode("!", $compressed);
    for($i = 0; $i<sizeof($explodedIndices); $i++) {
        $explodedIndices[$i] = $generalVals[$explodedIndices[$i]];
    } 
    $decompressed = implode("!", $explodedIndices); 
    return $decompressed;
 }

 function decompress_query_object($compressed) {
    global $paramKeys;
    global $paramVals; 
    global $generalVals;
    $decompressed = null; 
    if($compressed != null) {
        if(isset( $compressed["qf"])) {
            $decompressed["queryFunction"] = $generalVals[$compressed["qf"]];
        }
        if(isset( $compressed["p"])) {
            $decompressedParams = [];
            $decompressedParamKeys = []; 
            $decompressedParamVals = []; 
            $compressedKeys = $compressed["p"]["k"];
            $compressedVals = $compressed["p"]["v"];
            for($i = 0; $i<sizeof($compressedKeys); $i++) {
                $key = $paramKeys[$compressedKeys[$i]]; 
                $val = $paramVals[$compressedVals[$i]];
                if($key == "data") 
                    $val = json_decode($val, true);
                $decompressedParams[$key] = $val;
            }
            $decompressed["params"] = $decompressedParams;
        }
    }
    return $decompressed; 
 }


//we maintain a dedicated and redundant list for wildcard subscribers 
//if a message finds no match on the main subscrition hashmap lookup O(1),
//it checks against the wildcards O(n).
//the queryFuncs for each wildcard also appear in any of the main subscription associative 
//array entries which the wildcards match, so there is no need to check the wildcardList
//if an associative array entry was found. 
function amendSubscribersMapWithWildcardMatches() {
    global $subscribersMap;
    global $wildcardMap;
    
    foreach($wildcardMap as $wKey => $wValue) {
        foreach($subscribersMap as $sKey => $sValue) {
            $matches = strpos($sKey, $wKey, 0);//, strlen($subscriberPatt), false);
            if($matches === 0) {
                $subscribersMap[$sKey] = array_merge($sValue, $wValue);
            }
        }
    }
 }
  

 function initializeSubcribersMap($subscriptionInfo) {
    global $subscribersMap; 
    //global $poll;
    global $clientSubscriptionPort;
    global $context;
    global $omniListener;   
    global $conn;
    global $paramKeys;
    global $paramVals; 
    global $generalVals;
    global $wildcardMap;
    
    foreach($subscriptionInfo as $s) {
        $response_tag = $s['rti'];
        $event_pattern = $s['ep'];
        $event_pattern = decompress_event_pattern($event_pattern);
        $query_obj = isset( $s['qo']) ? $s['qo'] : null;
        $query_obj = decompress_query_object($query_obj);
        $query_function = null;
        $query_params = null;
        $formatting_status = "Well formed";
        $class_instance = null;

        if(!empty($query_obj)) {
            $query_function = isset( $query_obj['queryFunction']) ? $query_obj['queryFunction'] : null;
            $query_function = sani($query_function);
            if(!empty($query_function)) {
                $query_split_function = explode('/', rtrim($query_function, '/'));
                if($query_split_function[0] == "Timer") {
                    //error_log("timer");
                }
                if (!(isset($query_split_function[0]) && 
                    (class_exists($query_split_function[0]) || $query_split_function[0] == "Timer"))) 
                    $formatting_status = 'valid Class not specified';
                else if (!isset($query_split_function[1])) {
                    $formatting_status = 'method not specified';
                }
                else {
                    $query_params = isset( $query_obj['params']) ? $query_obj['params'] : null;                    
                }
            } else {
                $formatting_status = 'no function specified';
            }
        }
        
        $cleanParams = [];
        if(!empty($query_params)) {
            $cleanParams =  cleanAssocArray($query_params);
        }  
        if(!empty($query_split_function[0])) {
            if(class_exists($query_split_function[0])) {
                $qf = $query_split_function[1]; 
                $qid = sizeof($query_split_function) > 2 ? $query_split_function[2] : null;
                $class_instance = new $query_split_function[0]($conn, $qf, $qid);
                if(!empty($cleanParams) && $cleanParams['id'] != null) {
                    $class_instance->setId($cleanParams['id']);
                }
            }
        }
        
        if(isset( $cleanParams['data'])) {
            $query_params = $cleanParams['data'];
        } else {
            $query_params = [];
        }

        
        //$subscriber_id = spl_object_id($subscriber);
        $sub_info =  [
            's_response_tag' => $response_tag, 
            'event_pattern' => $event_pattern, 
            'query_function' => $query_function,
            'query_params' => $query_params,
            'formatting_status' => $formatting_status,
            'class_instance' => $class_instance
        ]; 
        //error_log("created : " . print_r($sub_info), true); 
        $subscribersMap[$event_pattern][] = $sub_info;
        $lastChar = substr($event_pattern, -1);
        if($lastChar != " ") {
            $wildcardMap[$event_pattern][] = $sub_info;
        }
    }
}

function cleanAssocArray($arr) {
    $cleanParams = [];
    foreach($arr as $key => $value) {        
        $newKey = sani($key);
        $newVal = "error";
        if(is_array($value)) {
             if(isAssoc($value)) {
                $newVal = cleanAssocArray($value);
             } else {
                 $newVal = cleanArray($value);
             }
        } else {
            $newVal = sani($value);
            $newVal = strval($newVal);
        }
        $cleanParams[strval($newKey)] = $newVal;
    } 
    return $cleanParams;
}

function cleanArray($arr){
    $cleanResult = [];
    foreach($arr as $a) {
        $cleanResult[] = sani($a);
    }
    return $cleanResult;
}

function executeFunctionFor($info, $messageOnly) {
    //global $subscribersMap; 
    //$info = $subscribersMap[strval($objId)]; 
    $func_name = $info['query_function'];
    $event_pattern = $info['event_pattern'];
    $response_tag = $info['s_response_tag'];
    $class_instance = $info['class_instance'];
    $formatting_status = $info['formatting_status'];
    $data = [
        's_response_tag' => $response_tag, 
        'event_pattern' => $event_pattern,
    ]; 
    $messageOnly = json_decode("".$messageOnly, true);
    $data['event_message'] = $messageOnly;
    $query_params = resolveParams($messageOnly, $info['query_params']);
    $result = [];

    $result['error_status'] = "success";
    error_clear_last();
    if(!empty($func_name)) {
        //error_log("RUNNING FUNCTION $func_name with params : " .print_r($query_params, true));
        $data['query_result'] = $class_instance->run($query_params);
        //error_log("result was : " . print_r($data['query_result'], true));
        $lastError = error_get_last();
        $lastError['type_name'] = array_search($lastError['type'], get_defined_constants());
        if (!empty($lastError) && $lastError != "FALSE" && $lastError['type_name']) {
           $message = "error";
            if(isset( $lastError['message'])) {
                $message = $lastError['message'];
            }
            $result['error_status'] = $lastError['type_name']." : ".$message;            
        }
    } else {
        $data['query_result'] = null;
    }
    
    $result['data'] = $data;
    $result['formatting_status'] = $formatting_status;
    return $result;
}

/**
 * for each parameter value, 
 * -if the specified parameter value is a concrete value, returns that value.
 * -if the specified parameter value is "message.[whateve]" returns the value corresponding 
 *  to that message element. (messages are always associative arrays). 
 */
function resolveParams($message, $params) {
    $resolved = []; 
    foreach($params as $key => $value) {
        if(startsWith($value, "message.")) {
            $resolved[$key] = $message[explode("message.", $value)[1]];
        } else {
            $resolved[$key] = $value;
        }
    } 
    return $resolved;
}


function printOutputFor($resultVar) {
    global $myID;

    $data = $resultVar['data'];
    $response_tag = $data['s_response_tag'];
   
    $json_result = [
        'formatting_status' => $resultVar['formatting_status'],
        'errorStatus' => $resultVar['error_status'],
        'eventPattern' => $data['event_pattern'],
        'eventMessage' => $data['event_message'],
        'queryResult' => $data['query_result'],
        's_responseTag' => $data['s_response_tag']
    ];
    
    $encoded = json_encode($json_result);


    //error_log("--------FROM ID: $myID -----------------");
    //error_log("--------TO IP: ".$_SERVER['REMOTE_ADDR']);
    error_log("event: " .trim($response_tag)); 
    //error_log("data: $encoded \n"); 

    $result = "\n
    event: ".trim($response_tag).
            "\ndata: $encoded\n\n";

    echo $result;    
    flush();    
    $connection_status = connection_status();
    if(connection_aborted()) {
        shutdown();
    }
}

function startsWith($haystack, $needle)
{
     $length = strlen($needle);
     return (substr($haystack, 0, $length) === $needle);
}

function shutdown() {
    global $omniListener; 
    //global $poll;
    global $clientSubscriptionPort;
    global $context;
    global $myID;
    global $conn;

    //error_log("--------------------------------------------------");
    //error_log("------------Ending childprocess $myID ------------");
    //error_log("--------------------------------------------------");
    if(connection_aborted()) {
        //error_log("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
        error_log("!!!!!!!!!!!!!!! REALLY EXITING !!!!!!!!!!!!!!!!!!!!");
        //error_log("!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!");
        //foreach($subscribersMap as $key => $value) {
        //$omniListener = $value['subscriberObj'];
        $omniListener->setSockOpt(ZMQ::SOCKOPT_UNSUBSCRIBE, "");            
        $omniListener->disconnect($clientSubscriptionPort);
        $conn = null;
        //$context->disconnect();
        usleep(1000);
        //$poll->clear();


        exit();
    }
    
}


flush();


//socket->unbind(ZMQ::SOCKOPT_LAST_ENDPOINT);
//$socket->disconnect($endpoint);
