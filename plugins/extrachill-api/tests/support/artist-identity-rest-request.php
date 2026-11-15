<?php
/**
 * WP_REST_Request double for the standalone user artists route smoke.
 *
 * @package ExtraChillAPI
 */

/**
 * Minimal WP_REST_Request double.
 */
class WP_REST_Request {
	/**
	 * Request parameters.
	 *
	 * @var array
	 */
	private $params;

	/**
	 * Build the request.
	 *
	 * @param array $params Request parameters.
	 */
	public function __construct( array $params ) {
		$this->params = $params;
	}

	/**
	 * Read one parameter.
	 *
	 * @param string $key Parameter name.
	 * @return mixed
	 */
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}
