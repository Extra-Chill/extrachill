<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/share
 *
 * Tracks share button clicks across the platform.
 * Public endpoint - no authentication required.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_analytics_share_route' );

/**
 * Register analytics share route.
 */
function extrachill_api_register_analytics_share_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/share',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_analytics_share_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'destination' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function ( $param ) {
						$allowed = array(
							'facebook',
							'twitter',
							'reddit',
							'bluesky',
							'linkedin',
							'email',
							'copy_link',
							'copy_markdown',
							'native',
						);
						return in_array( $param, $allowed, true );
					},
				),
				'source_url'  => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'share_url'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}

/**
 * Handle share tracking request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_analytics_share_handler( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_track_event' ) ) {
		return new WP_Error(
			'function_missing',
			'Analytics tracking function not available.',
			array( 'status' => 500 )
		);
	}

	$destination = $request->get_param( 'destination' );
	$source_url  = $request->get_param( 'source_url' );
	$share_url   = $request->get_param( 'share_url' ) ?: $source_url;

	$event_id = ec_track_event(
		'share_click',
		array(
			'destination' => $destination,
			'share_url'   => $share_url,
		),
		$source_url
	);

	if ( false === $event_id ) {
		return new WP_Error(
			'tracking_failed',
			'Failed to record share event.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array( 'recorded' => true ) );
}
