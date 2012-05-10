<?php
declare(encoding='UTF-8');

function out_boolean($name, $arg) {
	$e = htmlspecialchars($name);
	// TODO: Need to test this condition
	if (($arg) and ($arg != 'false')) {
		echo '      <', $e, '>true</', $e, '>
';
	} else {
		echo '      <', $e, '>false</', $e, '>
';
	}
}

function out_string($name, $arg) {
	$e = htmlspecialchars($name);
	echo '      <', $e, '>', htmlspecialchars($arg), '</', $e, '>
';
}

function out_string_array($name, $arg) {
	echo "      <$name enc:arrayType=\"xsd:string[", count($arg), "]\">\n";
	foreach ($arg as $value) {
		echo '        <string>', htmlspecialchars($value), "</string>\n";
	}
	echo "      </$name>\n";
}

/////////////////////////////////////////////////////////////////////////

$arg_out_map = array(
	'InformResponse' => array('MaxEnvelopes' => 'out_string'),
	'GetRPCMethodsResponse' => array('MethodList' => 'out_string_array'),

	'GetParameterNames' => array('ParameterPath' => 'out_string', 'NextLevel' => 'out_boolean')
);

/////////////////////////////////////////////////////////////////////////

function send_soap_header($headers) {
	// Add transaction ID header from original request.
	// Some CPEs use the ID header for matching responses to requests
	if (isset($headers['ID'])) {
		echo '  <soap:Header><cwmp:ID soap:mustUnderstand="1">', htmlspecialchars($headers['ID']), '</cwmp:ID></soap:Header>', "\n";
	}
}

function send_message($headers, $message_name, $args) {
	global $arg_out_map;

	echo '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:enc="http://schemas.xmlsoap.org/soap/encoding/"
    xmlns:xsd="http://www.w3.org/2001/XMLSchema"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xmlns:cwmp="urn:dslforum-org:cwmp-1-0">
';

	send_soap_header($headers);

	echo '  <soap:Body>
    <cwmp:', htmlspecialchars($message_name), '>
';

	foreach ($args as $arg_name => $arg_value) {
		if (isset($arg_out_map[$message_name][$arg_name])) {
			$arg_out_map[$message_name][$arg_name]($arg_name, $arg_value);
		} else {
			error_log('Can not find argument output function for ' . $message_name . '/' . $arg_name);
		}
	}

	echo '    </cwmp:', htmlspecialchars($message_name), '>
  </soap:Body>
</soap:Envelope>
';

	return TRUE;
}

function send_fault($headers, $fault_code1, $fault_code2, $fault_string) {

	echo '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:cwmp="urn:dslforum-org:cwmp-1-0">', "\n";
	send_soap_header($headers);
	echo '  <soap:Body>
		<soap:Fault>
			<faultcode>', htmlspecialchars($fault_code1), '</faultcode>
			<faultstring>CWMP fault</faultstring>
			<detail>
				<cwmp:Fault>
					<FaultCode>', htmlspecialchars($fault_code2), '</FaultCode>
					<FaultString>', htmlspecialchars($fault_string), '</FaultString>
				</cwmp:Fault>
			</detail>
		</soap:Fault>
	</soap:Body>
</soap:Envelope>  
';

	return FALSE;
}

/////////////////////////////////////////////////////////////////////////
