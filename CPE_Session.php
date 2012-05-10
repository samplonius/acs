<?php
declare(encoding='UTF-8');

define('STATE_REQUESTS', 1);
define('STATE_RESPONSES', 2);

define('SESSION_NAME', 'ID');

define('SESS_STATE', 'cpe_state');
define('SESS_REQUESTS', 'cpe_requests');

class CPE_Session {
	private $state;

	public function __construct() {
		session_name(SESSION_NAME);
		session_start();
		if (! isset($_SESSION[SESS_STATE])) {
			$_SESSION[SESS_STATE] = STATE_REQUESTS;
			$_SESSION[SESS_REQUESTS] = array();
			error_log('Creating new session');
		}
		$this->state = $_SESSION[SESS_STATE];
	}

	public function set_request_state() {
		$this->state = STATE_REQUESTS;
		$_SESSION[SESS_STATE] = $this->state;
	}
	
	public function set_response_state() {
		$this->state = STATE_RESPONSES;
		$_SESSION[SESS_STATE] = $this->state;
	}
	
	public function expecting_request() {
		return ($this->state == STATE_REQUESTS);
	}

	public function expecting_response() {
		return ($this->state == STATE_RESPONSES);
	}
	
	public function get_request() {
		if (count($_SESSION[SESS_REQUESTS]) > 0) {
			return array_shift($_SESSION[SESS_REQUESTS]);
		} else {
			return false;
		}
	}
	
	public function queue_request($request_name, $args) {
		array_push($_SESSION[SESS_REQUESTS], array($request_name, $args));
	}
}
