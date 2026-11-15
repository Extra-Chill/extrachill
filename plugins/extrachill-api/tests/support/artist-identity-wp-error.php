<?php
/**
 * WP_Error double for the standalone user artists route smoke.
 *
 * @package ExtraChillAPI
 */

/**
 * Minimal WP_Error double.
 */
class WP_Error {
	/**
	 * Error code.
	 *
	 * @var string
	 */
	public $code;

	/**
	 * Build the error.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message. Unused.
	 * @param mixed  $data    Data. Unused.
	 */
	public function __construct( $code = '', $message = '', $data = array() ) {
		unset( $message, $data );
		$this->code = $code;
	}
}
