<?php 
define("SERVER_EVENTS_PORT", "tcp://127.0.0.1:5550");
/**minimum time to wait for ZMQ to establish a connection
 *  before sending messages is allowed, in microseconds.
 * This is here as a quick and dirty hack to deal with
 * the fact that the first few messages are basically guaranteed
 * to be dropped if the connection hasn't been established yet.
 *
 * TODO: A proper handshake procedure
 */
define('MIN_SOCKET_CONNECT_WAIT', 5110);
register_shutdown_function('kill_push_socket');
$zmq_context = new ZMQContext(1, true);
//we initialize one socket per user and reuse it for all serverside notifications
//eachuser also has a socket allocated in messageSender.php for all user-broadcasted messages.
$zmq_sesssion_push_socket = new ZMQSocket($zmq_context, ZMQ::SOCKET_PUSH, 'fmss'.$zmqsock_identifier, 'initializeFunctionMessageSenderSockets');
function initializeFunctionMessageSenderSockets(ZMQSocket $socket, $persistent_id = null) {    
    $socket->setSockOpt(ZMQ::SOCKOPT_LINGER, 2000);
    $socket->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL, 100);
    $socket->setSockOpt(ZMQ::SOCKOPT_RECONNECT_IVL_MAX, 10000);
    $socket->connect(SERVER_EVENTS_PORT);
    usleep(MIN_SOCKET_CONNECT_WAIT);
}

$zmq_global_event_queue = [];
$zmq_dedup_fired = []; //associative array of eventpatterns and messages (used for message de-duplication)

/**
 * $evMsgArr should be an array of associative Arrays.
 *
 * The associative arrays should be of the form
 * [eventPattern => (someString),
 *  message => (someString)];
 *
 * Each should will be formatted and sent off as a seperate event.
 *
 * See "queueEvent" for info on eventPattern formatting
 * */
function queueEventList($evMsgArr)
{
    global $zmq_global_event_queue;
    foreach ($evMsgArr as $em) {
        queueEvent($em['eventPattern'], $em['message']);
    }
}

/**
 * $eventPattern is used to indicate who the message is intended for 
 * $message should be a php associative array which will get serialized as a json object when the message gets broadcast to a client
 */
function queueEvent($eventPattern, $message = [])
{
    global $zmq_global_event_queue;
    $zmq_global_event_queue[] = ['eventPattern' => $eventPattern, 'message' => $message];
}

/**
 * Fire all queued zeroMQ events.
 * Upon firing, all messages will be serialized as JSON objects.
 */
function fireEventQueue()
{
    global $zmq_sesssion_push_socket;
    global $time_since_zmq_context_initialization;
    global $zmq_global_event_queue;
   
    $lastEventPattern = ""; 
    $lastMessage = "";
    foreach ($zmq_global_event_queue as $msg) {
        $eventPattern = $msg['eventPattern'];
        $rawMsg = $msg['message'];             
        //check for duplicate messages and ignore them 
        //(since by the time they get to any client that wants to do a db_call in response, any relevant transactions will have been atomically commited)
        if(!hasFired($eventPattern, $rawMsg)) {
            $message = json_encode($rawMsg);
            $zmq_sesssion_push_socket->send($eventPattern . "  " . $message);
            setFired($eventPattern, $rawMsg);
        }
    }
    $zmq_global_event_queue = [];
    clearFired();
}

/**returns true if the given eventPattern-message pair has already been fired
 * from this eventQueue
 */
function hasFired($eventPattern, $message) {
    global $zmq_dedup_fired;
    $pattArr = $zmq_dedup_fired[$eventPattern];
    if ($pattArr != null) {
        if(in_array($message,$pattArr)) {
            return true;
        }
    }
    return false;
}

function setFired($eventPattern, $message) {
    global $zmq_dedup_fired;
    $zmq_dedup_fired[$eventPattern][] = $message;
}
function clearFired() {
    global $zmq_dedup_fired;
    $zmq_dedup_fired = [];
}

/**
 * Fire a single event immediately.
 *
 * This bypasses the queue entirely.
 *
 * See the comments on queueEvent for information on
 * formatting $eventPattern and $message.
 *
 * This function will serialize $message as a JSON object upon firing.
 */
function sendEvent($eventPattern, $message = [])
{
    global $zmq_sesssion_push_socket;
    global $time_since_zmq_context_initialization;
    $zmq_sesssion_push_socket->send($eventPattern . "  " . json_encode($message));
}

function kill_push_socket()
{
    global $zmq_sesssion_push_socket;
    global $zmq_global_event_queue;
    $zmq_global_event_queue = array();
}