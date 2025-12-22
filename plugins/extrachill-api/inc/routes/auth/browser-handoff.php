<?php
/**
 * REST route: browser handoff.
 *
 * POST /wp-json/extrachill/v1/auth/browser-handoff
 *
 * Returns a one-time URL that sets WordPress auth cookies in a real browser
 * and then redirects to the requested destination.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_auth_browser_handoff_route' );

function extrachill_api_register_auth_browser_handoff_route() {
	register_rest_route(
		'extrachill/v1',
		'/auth/browser-handoff',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_auth_browser_handoff_handler',
			'permission_callback' => 'is_user_logged_in',
			'args'                => array(
				'redirect_url' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

function extrachill_api_auth_browser_handoff_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_users_create_browser_handoff_token' ) ) {
		return new WP_Error(
			'extrachill_dependency_missing',
			'extrachill-users is required for browser handoff.',
			array( 'status' => 500 )
		);
	}

	$redirect_url = trim( (string) $request->get_param( 'redirect_url' ) );
	if ( '' === $redirect_url ) {
		return new WP_Error( 'missing_redirect_url', 'redirect_url is required.', array( 'status' => 400 ) );
	}

	$redirect_host = wp_parse_url( $redirect_url, PHP_URL_HOST );
	if ( ! is_string( $redirect_host ) || '' === $redirect_host ) {
		return new WP_Error( 'invalid_redirect_url', 'redirect_url must be an absolute URL.', array( 'status' => 400 ) );
	}

	$redirect_host = strtolower( $redirect_host );
	if ( false !== strpos( $redirect_host, 'extrachill.link' ) ) {
		return new WP_Error( 'invalid_redirect_url', 'extrachill.link is not supported for browser handoff.', array( 'status' => 400 ) );
	}

	if ( 'extrachill.com' !== $redirect_host && substr( $redirect_host, -strlen( '.extrachill.com' ) ) !== '.extrachill.com' ) {
		return new WP_Error( 'invalid_redirect_url', 'redirect_url must be on extrachill.com.', array( 'status' => 400 ) );
	}

	$token = extrachill_users_create_browser_handoff_token( get_current_user_id(), $redirect_url );

	$handoff_url = add_query_arg(
		array(
			'action'            => 'extrachill_browser_handoff',
			'ec_browser_handoff' => $token,
		),
		esc_url_raw( "https://{$redirect_host}/wp-admin/admin-post.php" )
	);

	return rest_ensure_response(
		array(
			'handoff_url' => $handoff_url,
		)
	);
}
