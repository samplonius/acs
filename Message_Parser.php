<?php
declare(encoding='UTF-8');

# Not a valid SOAP message: not XML, or XML without Envelope, Body, or method/response elements
define('CWMP_ERR_BAD_MESSAGE', 1);

define('CWMP_ERR_WRONG_VERSION', 2);
define('CWMP_ERR_BAD_METHOD', 3);
define('CWMP_ERR_BAD_ARG', 4);
define('CWMP_ERR_SOAP_FAULT', 5);


class CWMP_Message {
	
	// Message table
	//
	// Structure is a hash of message names, to a two element array consisting
	// of the handler function for this message, and the input argument names and types:
	//   'message_name' => array('handler_function', array('input_arg1', 'type'));
	//
	private static $messages = array(
		'Inform' => array('cwmp_inform', array(
			'DeviceId' => 'type_struct',
			'RetryCount' => 'type_string',
			'Event' => 'type_struct_array',
			'MaxEnvelopes' => 'type_string',
			'CurrentTime' => 'type_string',
			'ParameterList' => 'type_struct_array'),
		),

		'GetRPCMethods' => array('cwmp_getrpcmethods', array()),
		
		'TransferComplete' => array('cwmp_transfercomplete', array(
			'CommandKey' => 'type_string',
			'FaultStruct' => 'type_struct',
			'StartTime' => 'type_string',
			'CompleteTime' => 'type_string'),
		),

		'GetRPCMethodsResponse' => array('cwmp_getrpcmethodsresponse', array('MethodList' => 'type_string_array')),
		
		'GetParameterNamesResponse' => array('cwmp_getparameternamesresponse', array('ParameterList' => 'type_struct_array'))
	);

	const NS_SOAPENV = 'http://schemas.xmlsoap.org/soap/envelope/';
	const NS_CWMP = 'urn:dslforum-org:cwmp-1-0';

	// Per type parsing methods

	private static function type_string($sxe) {
		return array($sxe->getName() => (string) $sxe);
	}

	private static function type_struct($sxe) {
		$args = array();
		foreach ($sxe as $x) {
			$args[$x->getName()] = (string) $x->{0};
		}
		return $args;
	}

	private static function type_struct_array($sxe) {
		$args = array();
		foreach ($sxe as $array_item) {
			array_push($args, self::type_struct($array_item));
		}
		return $args;
	}

	private static function type_string_array($sxe) {
		$args = array();
		foreach ($sxe as $array_item) {
			array_push($args, (string) $array_item);
		}
		return $args;
	}

	public static function handle($allowed_messages, &$raw_message) {

		# Parse message into an SimpleXML object
		libxml_use_internal_errors(true);
		try {
			$req_element = new SimpleXMLElement($raw_message);
		} catch (Exception $e) {
			if ($error = libxml_get_last_error()) {
				 error_log('Ignoring maformed XML; detail: ' . rtrim($error->message));
			} else {
				 error_log('Ignorning malformed XML');
			}
			return array(CWMP_ERR_BAD_MESSAGE, 'Malformed XML');
		}

		# Find Envelope/Header/ID element
		# The Header and the ID elements are optional
		$soap_element = $req_element->children(self::NS_SOAPENV);
		if (isset($soap_element->Header)) {
			$cwmp_header_element = $soap_element->Header->children(self::NS_CWMP);
			if (isset($cwmp_header_element->ID)) {
				$headers = array('ID' => $cwmp_header_element->ID);
			}
		}

		# Is this a SOAP Fault?
		if (isset($soap_element->Fault)) {
			return array(CWMP_ERR_SOAP_FAULT, 'SOAP Fault');
		}
	
		# Check CWMP version
		# TODO: Detect urn:dslforum-org:cwmp-1-0 or urn:dslforum-org:cwmp-1-1
		$ns_list = $req_element->getNamespaces(TRUE);
		$wrong_version = true;
		while ($ns = each($ns_list)) {
			if ($ns[1] == self::NS_CWMP) {
				$wrong_version = false;
			}
		}
		if ($wrong_version) {
			return array(CWMP_ERR_WRONG_VERSION, 'Not a CWMP message, or wrong CWMP version');
		}

		# Find message name in "Body" element
		if (! isset($soap_element->Body)) {
			return array(CWMP_ERR_BAD_MESSAGE, 'Missing Envelope/Body element');
		}
		$cwmp_body_element = $soap_element->Body->children(self::NS_CWMP);
		if (count($cwmp_body_element) == 0) {
			return array(CWMP_ERR_BAD_MESSAGE, 'No CWMP element in Envelope/Body');
		}
		$message_name = $cwmp_body_element->getName();

		# Validate message
		if (! (isset($allowed_messages[$message_name]) and isset(self::$messages[$message_name]))) {
			return array(CWMP_ERR_BAD_METHOD, 'Unexpected or unknown method or response "' . $message_name . '"');
		}
		
		# Parse arguments
		$input_args_map = self::$messages[$message_name][1];
		$args = array();
		foreach ($cwmp_body_element->children() as $element) {
			$arg = $element->getName();

			if (isset($input_args_map[$arg])) {
				$args[$arg] = self::$input_args_map[$arg]($element);
			} else {
				return array(CWMP_ERR_BAD_ARG, 'Unknown argument "' . $arg . '" in "' . $message_name . '" message');
			}
		}

		# Call handler for this message
		$func = self::$messages[$message_name][0];
		return $func($headers, $args);
	}
}
