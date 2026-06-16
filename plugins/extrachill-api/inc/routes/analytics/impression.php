<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/impression
 *
 * Records a cross-site network-bridge impression — fired client-side
 * (sendBeacon) once per pageview when the bridge actually renders with cards.
 * Because it runs only in a real, JS-executing browser, prefetch/prerender/
 * crawler hits (the source of the bridge channel's bot inflation) never fire
 * it. It is the exposure denominator that makes CTR = clicks / impressions
 * computable, and it shares the same humans-with-JS bot filter as the
 * bridge_click event. See extrachill-multisite#58.
 *
 * Thin wrapper: records via the extrachill/track-analytics-event ability —
 * all write logic lives in the ability, not here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_impression_route' );

function extrachill_api_register_impression_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/impression',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_impression_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'impression_type' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function ( $param ) {
						$allowed = array( 'bridge' );
						return in_array( $param, $allowed, true );
					},
				),
				'source_url'      => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'source_post'     => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Handle impression tracking request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_impression_handler( WP_REST_Request $request ) {
	$impression_type = $request->get_param( 'impression_type' );
	$source_url      = $request->get_param( 'source_url' );
	$source_post     = (int) $request->get_param( 'source_post' );

	if ( 'bridge' !== $impression_type ) {
		return new WP_Error(
			'invalid_impression_type',
			'Unsupported impression type.',
			array( 'status' => 400 )
		);
	}

	$ability = wp_get_ability( 'extrachill/track-analytics-event' );
	if ( ! $ability ) {
		return new WP_Error(
			'ability_missing',
			'Analytics tracking ability not available.',
			array( 'status' => 500 )
		);
	}

	$event_id = $ability->execute(
		array(
			'event_type' => 'bridge_impression',
			'event_data' => array(
				'source_post' => $source_post,
			),
			'source_url' => $source_url,
		)
	);

	if ( is_wp_error( $event_id ) ) {
		return $event_id;
	}

	if ( empty( $event_id ) ) {
		return new WP_Error(
			'tracking_failed',
			'Failed to record bridge impression event.',
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response( array( 'recorded' => true ) );
}
