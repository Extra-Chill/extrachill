<?php
/**
 * 404 Error Logger REST API Endpoints
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', 'extrachill_api_register_404_logger_routes' );

/**
 * Register 404 logger REST routes.
 */
function extrachill_api_register_404_logger_routes() {
	register_rest_route(
		'extrachill/v1',
		'/admin/404-logger/settings',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'extrachill_api_get_404_logger_settings',
				'permission_callback' => 'extrachill_api_404_logger_permission_check',
			),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'extrachill_api_update_404_logger_settings',
				'permission_callback' => 'extrachill_api_404_logger_permission_check',
				'args'                => array(
					'enabled' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			),
		)
	);

	register_rest_route(
		'extrachill/v1',
		'/admin/404-logger/stats',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_404_logger_stats',
			'permission_callback' => 'extrachill_api_404_logger_permission_check',
		)
	);
}

/**
 * Permission check for 404 logger endpoints.
 *
 * @return bool|WP_Error
 */
function extrachill_api_404_logger_permission_check() {
	if ( ! current_user_can( 'manage_network_options' ) ) {
		return new WP_Error(
			'rest_forbidden',
			'You do not have permission to manage 404 logger settings.',
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Get 404 logger settings.
 *
 * @return WP_REST_Response
 */
function extrachill_api_get_404_logger_settings() {
	$enabled = get_site_option( 'extrachill_404_logger_enabled', 1 );

	return rest_ensure_response(
		array(
			'enabled' => (bool) $enabled,
		)
	);
}

/**
 * Update 404 logger settings.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function extrachill_api_update_404_logger_settings( $request ) {
	$enabled = $request->get_param( 'enabled' );

	update_site_option( 'extrachill_404_logger_enabled', $enabled ? 1 : 0 );

	return rest_ensure_response(
		array(
			'success' => true,
			'enabled' => (bool) $enabled,
		)
	);
}

/**
 * Get 404 logger stats.
 *
 * @return WP_REST_Response
 */
function extrachill_api_get_404_logger_stats() {
	global $wpdb;

	$table_name  = $wpdb->base_prefix . '404_log';
	$today_count = 0;

	// Check if table exists.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$table_exists = $wpdb->get_var(
		$wpdb->prepare(
			'SHOW TABLES LIKE %s',
			$table_name
		)
	);

	if ( $table_exists ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$today_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table_name} WHERE DATE(time) = CURDATE()"
		);
	}

	return rest_ensure_response(
		array(
			'today_count' => $today_count,
		)
	);
}
