<?php
/**
 * CWMP Auto Configuration Server (ACS)
 *
 * @package default
 */

declare(encoding='UTF-8');

ini_set('display_errors', 0);	// Just for testing
ini_set('expose_php', 0);     // This doesn't work, and has to be moved to php.ini

require 'processing.php';

require 'CPE_Session.php';
require 'Message_Parser.php';

define('REQTRACE', 'request.txt');


// Map of functions called for CPE requests
$request_map = array(
	'Inform' => 'cwmp_inform',
	'GetRPCMethods' => 'cwmp_getrpcmethods',
	'TransferComplete' => 'cwmp_transfercomplete'
);

// Map of the functions called for CPE responses to our requests
$response_map = array(
	'GetRPCMethodsResponse' => 'cwmp_getrpcmethodsresponse',
	'GetParameterNamesResponse' => 'cwmp_getparameternamesresponse'
);


function cwmp_inform(&$headers, &$args) {
	send_message($headers, 'InformResponse', array('MaxEnvelopes' => 1));
	return 0;
}

function cwmp_getrpcmethods($args, &$new_args) {
	$new_args = array('MethodList' => array('GetRPCMethods', 'Inform', 'TransferComplete'));
	return 'GetRPCMethodsResponse';
}

function cwmp_transfercomplete($args, &$new_args) {
	$new_args = array();
	return 'TransferCompleteResponse';
}

/////////////////////////////////////////////////////////////////////////

function cwmp_getrpcmethodsresponse($args) {
	return true;
}

function cwmp_getparameternamesresponse($args) {
	return true;
}

/////////////////////////////////////////////////////////////////////////

//  switch ($result) {
//    // Dispatch
//    case CWMP_OK:
//      $request_map[$message_name]($headers, $in_args);
//      break;
//    case CWMP_ERR_NOT_CWMP:
//      send_fault($headers, 'Client', '8003', 'Incorrect CWMP protocol');
//      break;
//    case CWMP_ERR_BAD_MESSAGE:
//      send_fault($headers, 'Client', '8003', 'Bad message');
//      break;
//    case CWMP_ERR_BAD_ARG:
//      send_fault($headers, 'Client', '8003', 'Bad argument');
//      break;
//    // Just ignore poorly formed XML
//    case CWMP_ERR_BAD_XML:
//      break;
//    // Receiving a fault in request mode makes no sense
//    case CWMP_ERR_SOAP_FAULT:
//      break;     
//  }

/////////////////////////////////////////////////////////////////////////

function simple_log($message) {
	file_put_contents(REQTRACE, $message, FILE_APPEND);
}  


$cpe = new CPE_Session();

// Dev mode
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
	header('Content-Type: text/plain');

	$HTTP_RAW_POST_DATA = file_get_contents('req_inform.txt');
	$dev_mode = 1;

} else {
	header('Content-Type: text/xml');
	$dev_mode = 0;
}

// Log POST headers
simple_log("< Headers:\n" . print_r(apache_request_headers(), TRUE));

ob_start();

// Message handling confusing due to the fact, that CWMP requests are sometimes in HTTP replies, and vice versa.
//
// Each session starts in request mode, where the CPE is putting requests in the HTTP request.
// We, the ACS, place the reply in the HTTP response.  The CPE will send at least one request message (an Inform).
// Once the CPE runs out of requests, it sends a blank request.  This triggers a turn around in the message flow,
// and the ACS can now start placing requests in the HTTP responses.  This is response mode.
// If the ACS has no requests, it will send a blank request, and the session is over.  Otherwise the ACS can 
// continue to place requests in the HTTP responses.


if ($cpe->expecting_request()) {

	if (empty($HTTP_RAW_POST_DATA)) {
		simple_log("< Request: Empty\n");
		
		// If we have a queued request send it, and change to expecting response state
		if ($request = $cpe->get_request()) {
			$cpe->set_response_state();
			send_message(array(), $request[0], $request[1]);
			simple_log("> Request:\n" . ob_get_contents() . "\n------\n\n");

		} else {
			header("HTTP/1.1 253 No Content");
			simple_log("> Request: No Content\n");
		}

	// Normal request message
	} else {
		simple_log("< Request:\n" . $HTTP_RAW_POST_DATA . "\n------\n\n");

		if ($r = CWMP_Message::handle(array('Inform' => 1, 'GetRPCMethods' => 1, 'TransferComplete' => 1), $HTTP_RAW_POST_DATA)) {
			simple_log('Error: ' . $r[1]);
		}

		simple_log("> Response:\n" . ob_get_contents() . "\n------\n\n");
	}

} elseif ($cpe->expecting_response) {

	if (empty($HTTP_RAW_POST_DATA)) {
		simple_log("< Request: (Empty) (Response expected!)\n");
		error_log('Expected Response, but received blank POST');
		// TODO: If we are waiting for a Response message, but get a blank message, what do we do?
		//       Options: resend the last request, or give up.
		$cpe->set_request_state();

	} else {
		simple_log("< Response:\n" . $HTTP_RAW_POST_DATA . "\n------\n\n");

		$message = new Message_Parser($HTTP_RAW_POST_DATA);
		if ($message->error) {
			// TODO: handle error
		} else {
			if (isset($response_map[$message->name])) {
				$response_name = $response_map[$message->name]($message->args);
			}
		}

		// Send a request
		if ($request = $cpe->get_request()) {
			send_message(array(), $request[0], $request[1]);      
			simple_log("> Request:\n" . ob_get_contents() . "\n------\n\n");
			
		} else {
			// Send nothing, and go back to request state
			header("HTTP/1.1 253 No Content");
			$cpe->set_request_state();
		}
	}
}

simple_log("> Headers:\n" . print_r(apache_response_headers(), TRUE));

ob_end_flush();

		//send_message($message->headers, $response_name, $new_args);
		//send_fault($message->headers, 'Client', '8003', 'Bad message');
