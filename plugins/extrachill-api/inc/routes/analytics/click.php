<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/click
 *
 * Unified click tracking endpoint. Routes to appropriate storage based on click_type:
 * - 'share': Tracks via extrachill/track-analytics-event ability to ec_events table
 * - 'link_page_link': Fires action hook for artist link page daily tables
 *
 * Future click types (internal_link, taxonomy_badge, cta, etc.) will route through abilities.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_click_route' );

function extrachill_api_register_click_route() {
	register_rest_route(
		'extrachill/v1',
		'/analytics/click',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_click_handler',
			'permission_callback' => '__return_true',
			'args'                => array(
				'click_type'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => function ( $param ) {
						$allowed = array( 'share', 'link_page_link' );
						return in_array( $param, $allowed, true );
					},
				),
				'source_url'        => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'destination_url'   => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'element_text'      => array(
					'required'          => false,
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'share_destination' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				),
				'link_page_id'      => array(
					'required'          => false,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		)
	);
}

/**
 * Normalizes tracked URLs by removing auto-generated analytics parameters.
 *
 * Strips _gl, _ga, and _ga_* query parameters injected by Google Analytics
 * cross-domain linking while preserving affiliate IDs and custom query strings.
 *
 * @param string $url The URL to normalize.
 * @return string The normalized URL with auto-generated params removed.
 */
function extrachill_api_normalize_tracked_url( $url ) {
	if ( empty( $url ) ) {
		return $url;
	}

	$parsed = wp_parse_url( $url );
	if ( ! isset( $parsed['query'] ) || empty( $parsed['query'] ) ) {
		return $url;
	}

	parse_str( $parsed['query'], $query_params );

	$params_to_strip = array( '_gl', '_ga' );
	foreach ( $params_to_strip as $param ) {
		unset( $query_params[ $param ] );
	}

	foreach ( array_keys( $query_params ) as $key ) {
		if ( strpos( $key, '_ga_' ) === 0 ) {
			unset( $query_params[ $key ] );
		}
	}

	$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
	$host     = isset( $parsed['host'] ) ? $parsed['host'] : '';
	$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
	$path     = isset( $parsed['path'] ) ? $parsed['path'] : '';
	$query    = ! empty( $query_params ) ? '?' . http_build_query( $query_params ) : '';
	$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

	return $scheme . $host . $port . $path . $query . $fragment;
}

/**
 * Handle click tracking request.
 *
 * Routes to appropriate storage based on click_type.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_click_handler( WP_REST_Request $request ) {
	$click_type        = $request->get_param( 'click_type' );
	$source_url        = $request->get_param( 'source_url' );
	$destination_url   = $request->get_param( 'destination_url' );
	$element_text      = $request->get_param( 'element_text' );
	$share_destination = $request->get_param( 'share_destination' );
	$link_page_id      = $request->get_param( 'link_page_id' );

	$normalized_destination = extrachill_api_normalize_tracked_url( $destination_url );

	switch ( $click_type ) {
		case 'share':
			if ( empty( $share_destination ) ) {
				return new WP_Error(
					'missing_share_destination',
					'share_destination is required for share click type.',
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
					'event_type' => 'share_click',
					'event_data' => array(
						'destination' => $share_destination,
						'share_url'   => $normalized_destination ?: $source_url,
					),
					'source_url' => $source_url,
				)
			);

			if ( empty( $event_id ) ) {
				return new WP_Error(
					'tracking_failed',
					'Failed to record share event.',
					array( 'status' => 500 )
				);
			}
			break;

		case 'link_page_link':
			if ( empty( $link_page_id ) ) {
				return new WP_Error(
					'missing_link_page_id',
					'link_page_id is required for link_page_link click type.',
					array( 'status' => 400 )
				);
			}

			if ( empty( $destination_url ) ) {
				return new WP_Error(
					'missing_destination_url',
					'destination_url is required for link_page_link click type.',
					array( 'status' => 400 )
				);
			}

			/**
			 * Fires when a link click is recorded on an artist link page.
			 *
			 * @param int    $link_page_id         The link page post ID.
			 * @param string $normalized_destination The clicked URL with GA params stripped.
			 * @param string $element_text         The link text at time of click.
			 */
			do_action( 'extrachill_link_click_recorded', $link_page_id, $normalized_destination, $element_text );
			break;
	}

	return rest_ensure_response( array( 'recorded' => true ) );
}
