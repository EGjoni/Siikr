<?php
function getRelevantSubscribersForPattern($eventPatt) {
     global $subscribersMap; 
     global $wildcardMap;
     
     if(!isset($subscribersMap[$eventPatt])) {
        $results = [];
        foreach($wildcardMap as $wildcardPatt => $funcList) {
            $matches = strpos($wildcardPatt, $eventPatt, 0);//, strlen($subscriberPatt), false);
            if($matches === 0) {
                $results = array_merge($results, $funcList);
            }
        }
    } else {
        $results = $subscribersMap[$eventPatt];
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
        $qf = isSet($query_obj) ? $query_obj["queryFunction"] : null;
        $qp = isSet($query_obj) ? $query_obj["params"] : null;
        $formatting_status = "Well formed";
        $cleanParams = [];

        if(isset( $cleanParams['data'])) {
            $query_params = $cleanParams['data'];
        } else {
            $query_params = [];
        }        
        //$subscriber_id = spl_object_id($subscriber);
        $sub_info=[];
        $sub_info['s_response_tag'] = $response_tag;
        $sub_info['event_pattern'] = $event_pattern;
        $sub_info['query_function'] = $qf;
        $sub_info['query_params'] = $qp;
        $sub_info['formatting_status'] = $formatting_status;
        
        //error_log("created : " . print_r($sub_info), true); 
        $subscribersMap[$event_pattern][] = $sub_info;
        $lastChar = substr($event_pattern, -1);
        if($lastChar != " ") {
            $wildcardMap[$event_pattern][] = $sub_info;
        }
    }
}


function executeFunctionFor($info, $messageOnly) {
    //global $subscribersMap; 
    //$info = $subscribersMap[strval($objId)]; 
    $event_pattern = $info['event_pattern'];
    $response_tag = $info['s_response_tag'];
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
    /*if(!empty($func_name)) {
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
    } else {*/
        $data['query_result'] = null;
    //}
    
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
    //error_log("event: " .trim($response_tag)); 
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