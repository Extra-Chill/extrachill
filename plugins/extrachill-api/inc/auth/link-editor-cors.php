<?php
/**
 * Compatibility for cross-origin REST clients using wp.apiFetch.
 *
 * Its httpV1 middleware sends PUT, PATCH and DELETE as POST with the
 * X-HTTP-Method-Override header. Same-origin Link Page editing no longer
 * needs this allowance.
 *
 * Allowing the header only widens which request headers an already-allowed
 * origin may send; which origins are allowed is unchanged.
 *
 * @package ExtraChillAPI
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow the method-override header wp.apiFetch sends on cross-origin writes.
 *
 * @param mixed $headers Allowed CORS request headers (filter value; other callbacks may return a non-array).
 * @return string[]
 */
function ec_api_allow_method_override_cors_header( $headers ) {
	$headers = is_array( $headers ) ? $headers : array();
	foreach ( $headers as $header ) {
		if ( 0 === strcasecmp( (string) $header, 'X-HTTP-Method-Override' ) ) {
			return $headers;
		}
	}
	$headers[] = 'X-HTTP-Method-Override';
	return $headers;
}
add_filter( 'rest_allowed_cors_headers', 'ec_api_allow_method_override_cors_header' );
