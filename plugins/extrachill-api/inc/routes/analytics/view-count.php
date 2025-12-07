<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/view
 *
 * Async view counting endpoint. Called via JavaScript after page load
 * to track post views without blocking page render.
 */

if (!defined('ABSPATH')) {
	exit;
}

add_action('extrachill_api_register_routes', 'extrachill_api_register_view_count_route');

function extrachill_api_register_view_count_route() {
	register_rest_route('extrachill/v1', '/analytics/view', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_view_count_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'post_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function($param) {
					return is_numeric($param) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
		),
	));
}

function extrachill_api_view_count_handler($request) {
	$post_id = $request->get_param('post_id');

	if (!function_exists('ec_track_post_views')) {
		return new WP_Error(
			'function_missing',
			'View tracking function not available.',
			array('status' => 500)
		);
	}

	// Track all-time views (theme system)
	ec_track_post_views($post_id);

	// For link pages, also fire action for 90-day daily table tracking
	if (get_post_type($post_id) === 'artist_link_page') {
		do_action('extrachill_link_page_view_recorded', $post_id);
	}

	return rest_ensure_response( array( 'recorded' => true ) );
}
