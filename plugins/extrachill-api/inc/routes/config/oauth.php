<?php
/**
 * REST route: OAuth configuration.
 *
 * GET /wp-json/extrachill/v1/config/oauth
 *
 * Public endpoint returning OAuth provider configuration for mobile apps.
 * Client IDs are safe to expose publicly - they identify the app, not authenticate it.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_config_oauth_route' );

/**
 * Registers the OAuth config route.
 */
function extrachill_api_register_config_oauth_route() {
	register_rest_route(
		'extrachill/v1',
		'/config/oauth',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_config_oauth_handler',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handles the OAuth config request.
 *
 * @return WP_REST_Response
 */
function extrachill_api_config_oauth_handler() {
	$google_enabled = function_exists( 'ec_is_google_oauth_configured' )
		&& ec_is_google_oauth_configured();

	$apple_enabled = function_exists( 'ec_is_apple_oauth_configured' )
		&& ec_is_apple_oauth_configured();

	$response = array(
		'google' => array(
			'enabled'           => $google_enabled,
			'web_client_id'     => $google_enabled ? get_site_option( 'extrachill_google_client_id', '' ) : '',
			'ios_client_id'     => $google_enabled ? get_site_option( 'extrachill_google_ios_client_id', '' ) : '',
			'android_client_id' => $google_enabled ? get_site_option( 'extrachill_google_android_client_id', '' ) : '',
		),
		'apple'  => array(
			'enabled' => $apple_enabled,
		),
	);

	return rest_ensure_response( $response );
}
