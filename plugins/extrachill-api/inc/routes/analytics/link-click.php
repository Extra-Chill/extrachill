<?php
/**
 * REST route: POST /wp-json/extrachill/v1/analytics/link-click
 *
 * Public endpoint for tracking link clicks on artist link pages.
 * Validates input, normalizes URLs, and fires action hook for data storage.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_link_click_route' );

function extrachill_api_register_link_click_route() {
	register_rest_route( 'extrachill/v1', '/analytics/link-click', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'extrachill_api_link_click_handler',
		'permission_callback' => '__return_true',
		'args'                => array(
			'link_page_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
				'sanitize_callback' => 'absint',
			),
			'link_url'     => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
			),
		),
	) );
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

	// Remove Google Analytics auto-generated parameters
	$params_to_strip = array( '_gl', '_ga' );
	foreach ( $params_to_strip as $param ) {
		unset( $query_params[ $param ] );
	}

	// Remove any _ga_* parameters (e.g., _ga_L362LLL9KM)
	foreach ( array_keys( $query_params ) as $key ) {
		if ( strpos( $key, '_ga_' ) === 0 ) {
			unset( $query_params[ $key ] );
		}
	}

	// Rebuild URL
	$scheme   = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
	$host     = isset( $parsed['host'] ) ? $parsed['host'] : '';
	$port     = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';
	$path     = isset( $parsed['path'] ) ? $parsed['path'] : '';
	$query    = ! empty( $query_params ) ? '?' . http_build_query( $query_params ) : '';
	$fragment = isset( $parsed['fragment'] ) ? '#' . $parsed['fragment'] : '';

	return $scheme . $host . $port . $path . $query . $fragment;
}

/**
 * Handles link click tracking requests
 *
 * @param WP_REST_Request $request The request object.
 * @return WP_REST_Response
 */
function extrachill_api_link_click_handler( $request ) {
	$link_page_id = $request->get_param( 'link_page_id' );
	$link_url     = $request->get_param( 'link_url' );

	// Normalize URL to strip GA parameters
	$normalized_url = extrachill_api_normalize_tracked_url( $link_url );

	/**
	 * Fires when a link click is recorded.
	 *
	 * @param int    $link_page_id The link page post ID.
	 * @param string $normalized_url The clicked URL with GA params stripped.
	 */
	do_action( 'extrachill_link_click_recorded', $link_page_id, $normalized_url );

	return rest_ensure_response( array( 'tracked' => true ) );
}
