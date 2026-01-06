<?php
/**
 * REST routes: Analytics Meta
 *
 * GET /wp-json/extrachill/v1/analytics/meta - Get filter options (event types, blogs)
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_meta_routes' );

/**
 * Register analytics meta routes.
 */
function extrachill_api_register_analytics_meta_routes() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/meta',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_analytics_meta_handler',
			'permission_callback' => function () {
				return current_user_can( 'manage_network_options' );
			},
		)
	);
}

/**
 * Handle analytics meta request.
 *
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_meta_handler() {
	global $wpdb;

	$table_name = ec_events_get_table_name();

	// Get distinct event types.
	$event_types = $wpdb->get_col(
		"SELECT DISTINCT event_type FROM {$table_name} ORDER BY event_type ASC"
	);

	// Get blogs that have events.
	$blog_ids = $wpdb->get_col(
		"SELECT DISTINCT blog_id FROM {$table_name} ORDER BY blog_id ASC"
	);

	$blogs = array();
	foreach ( $blog_ids as $blog_id ) {
		$blog_id   = absint( $blog_id );
		$blog_name = get_blog_option( $blog_id, 'blogname' );
		$blogs[]   = array(
			'id'   => $blog_id,
			'name' => $blog_name ? $blog_name : "Blog {$blog_id}",
		);
	}

	return rest_ensure_response(
		array(
			'event_types' => $event_types,
			'blogs'       => $blogs,
		)
	);
}
