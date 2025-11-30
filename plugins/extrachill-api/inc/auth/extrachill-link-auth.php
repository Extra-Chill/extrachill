<?php
/**
 * WordPress Cookie SameSite Configuration for Cross-Domain Auth
 *
 * Modifies WordPress authentication cookies to include SameSite=None; Secure
 * attributes, allowing them to be sent in cross-site AJAX requests from
 * extrachill.link to artist.extrachill.com.
 *
 * Without SameSite=None, browsers block auth cookies from being sent in
 * cross-origin requests, breaking the client-side edit button authentication.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register callback to modify cookie headers before they're sent
 */
add_action( 'init', 'ec_register_cookie_samesite_callback', 1 );
function ec_register_cookie_samesite_callback() {
	if ( ! headers_sent() ) {
		header_register_callback( 'ec_add_samesite_none_to_wordpress_cookies' );
	}
}

/**
 * Modify WordPress cookie headers to include SameSite=None; Secure
 *
 * This callback runs just before headers are sent to the browser.
 * It intercepts all Set-Cookie headers and adds SameSite=None to WordPress cookies.
 */
function ec_add_samesite_none_to_wordpress_cookies() {
	$headers = headers_list();

	// Remove all Set-Cookie headers temporarily
	header_remove( 'Set-Cookie' );

	// Re-add them with SameSite modifications
	foreach ( $headers as $header ) {
		if ( stripos( $header, 'Set-Cookie:' ) === 0 ) {
			// Check if this is a WordPress cookie
			if ( stripos( $header, 'wordpress_' ) !== false ) {
				// Only add SameSite if not already present
				if ( stripos( $header, 'SameSite' ) === false ) {
					$header .= '; SameSite=None; Secure';
				}
			}
			// Re-add the header (false = don't replace existing headers with same name)
			header( $header, false );
		}
	}
}
