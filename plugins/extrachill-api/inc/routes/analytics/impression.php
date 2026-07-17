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
 * all write logic lives in the ability, not here. Records `dest_site`,
 * `source_site` (and `term` when present) in event_data so the impression
 * denominator carries the same per-destination dimensions as the bridge_click
 * numerator — making CTR = clicks / impressions computable per destination.
 * See extrachill-multisite#62 and extrachill-analytics#75.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/telemetry-validation.php';

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
					'maxLength'         => 2048,
					'sanitize_callback' => 'esc_url_raw',
				),
				'source_post'     => array(
					'required'          => false,
					'type'              => 'integer',
					'default'           => 0,
					'sanitize_callback' => 'absint',
				),
				'source_site'     => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'dest_site'       => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
				),
				'term'            => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'maxLength'         => 200,
					'sanitize_callback' => 'sanitize_text_field',
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
	$source = extrachill_api_validate_telemetry_request( $request );
	if ( is_wp_error( $source ) ) {
		return $source;
	}

	$impression_type = $request->get_param( 'impression_type' );
	$source_url      = $source['path'];
	$source_post     = (int) $request->get_param( 'source_post' );
	$source_site     = $request->get_param( 'source_site' );
	$dest_site       = $request->get_param( 'dest_site' );
	$term            = $request->get_param( 'term' );

	if ( 'bridge' !== $impression_type ) {
		return new WP_Error(
			'invalid_impression_type',
			'Unsupported impression type.',
			array( 'status' => 400 )
		);
	}

	if ( ! extrachill_api_is_safe_telemetry_text( (string) $term ) ) {
		return new WP_Error( 'unsafe_telemetry_payload', 'Telemetry text payload is not accepted.', array( 'status' => 400 ) );
	}

	$current_site = function_exists( 'ec_get_blog_slug_by_id' ) ? ec_get_blog_slug_by_id( get_current_blog_id() ) : '';
	if ( $source_site && $current_site && $source_site !== $current_site ) {
		return new WP_Error( 'invalid_source_site', 'source_site does not match source_url.', array( 'status' => 400 ) );
	}
	if ( $dest_site && function_exists( 'ec_get_blog_id' ) && ( null === ec_get_blog_id( $dest_site ) || $dest_site === $current_site ) ) {
		return new WP_Error( 'invalid_destination_site', 'dest_site is not a valid cross-site destination.', array( 'status' => 400 ) );
	}
	if ( ! $source_site && $current_site ) {
		$source_site = $current_site;
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
				'dest_site'   => $dest_site,
				'source_post' => $source_post,
				'source_site' => $source_site,
				'term'        => $term,
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
