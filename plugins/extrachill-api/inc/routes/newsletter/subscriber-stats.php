<?php
/**
 * REST route: GET /wp-json/extrachill/v1/newsletter/subscribers
 *
 * Public endpoint. Returns total active Sendy subscribers across all lists,
 * plus a per-list breakdown. Wraps the
 * `extrachill/newsletter-subscriber-stats` ability registered by
 * extrachill-newsletter.
 *
 * The ability is cached for 1 hour by transient, so this endpoint is safe
 * to expose publicly — viral pages won't hammer Sendy. Per-list breakdown
 * exposes only list names + active counts, which is non-sensitive.
 *
 * @package ExtraChill\API
 * @since 0.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_newsletter_subscriber_stats_route' );

/**
 * Register the public subscriber stats route.
 */
function extrachill_api_register_newsletter_subscriber_stats_route() {
	register_rest_route(
		'extrachill/v1',
		'/newsletter/subscribers',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_newsletter_subscriber_stats_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'refresh' => array(
					'required'          => false,
					'type'              => 'boolean',
					'sanitize_callback' => 'rest_sanitize_boolean',
					'default'           => false,
					'description'       => __( 'Bypass the 1-hour cache. Admin only — silently ignored for public callers.', 'extrachill-api' ),
				),
				'source'  => array(
					'required'          => false,
					'type'              => 'string',
					'enum'              => array( 'auto', 'db', 'api' ),
					'default'           => 'auto',
					'sanitize_callback' => 'sanitize_text_field',
					'description'       => __( 'Where to read counts from. Admin only — silently ignored for public callers.', 'extrachill-api' ),
				),
			),
		)
	);
}

/**
 * Handle GET /newsletter/subscribers.
 *
 * Calls the ability's execute callback directly so we can serve the public
 * endpoint without tripping the ability's manage_options permission gate.
 *
 * @param WP_REST_Request $request The REST request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_newsletter_subscriber_stats_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_newsletter_ability_subscriber_stats' ) ) {
		return new WP_Error(
			'ability_not_available',
			'Newsletter subscriber stats ability not available. Ensure extrachill-newsletter v0.3.0+ is active.',
			array( 'status' => 500 )
		);
	}

	// Privileged params — only admins can override the defaults. This keeps
	// public callers from forcing a cache bust or expensive API path.
	$is_admin      = current_user_can( 'manage_options' );
	$force_refresh = $is_admin ? (bool) $request->get_param( 'refresh' ) : false;
	$source        = $is_admin ? (string) $request->get_param( 'source' ) : 'auto';

	$result = extrachill_newsletter_ability_subscriber_stats(
		array(
			'force_refresh' => $force_refresh,
			'source'        => $source,
		)
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}
