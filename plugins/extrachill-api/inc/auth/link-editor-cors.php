<?php
/**
 * Cross-origin write headers for the extrachill.link editor.
 *
 * The Link Page editor runs on extrachill.link and saves to the artist site's
 * REST API with a bearer token (see extrachill-link-token-handoff.php). Its
 * saves go through wp.apiFetch, whose built-in httpV1 middleware sends every
 * PUT, PATCH and DELETE as a POST carrying an X-HTTP-Method-Override header.
 * WordPress core honours that header server-side (WP_REST_Server::serve_request)
 * but does not list it in the default Access-Control-Allow-Headers, so the
 * browser rejects the CORS preflight and no editor save ever reaches the
 * server: links, socials and profile info all fail with "Could not get a valid
 * response from the server."
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
