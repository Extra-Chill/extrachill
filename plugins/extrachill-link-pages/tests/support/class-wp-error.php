<?php
/**
 * WP_Error stand-in for the standalone fake-WordPress test harness.
 *
 * @package ExtraChillLinkPages
 */

/**
 * Minimal WP_Error carrying a code, message, and optional data.
 */
class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code; }
	public function get_error_message() {
		return $this->message; }
	public function get_error_data() {
		return $this->data; }
}
