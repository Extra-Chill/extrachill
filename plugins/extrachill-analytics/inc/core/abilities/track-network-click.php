<?php
/**
 * REST-visible first-party cross-network link tracking.
 *
 * @package ExtraChill\Analytics
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/** Register the bounded browser adapter. */
function extrachill_analytics_register_track_network_click_ability(): void {
	wp_register_ability(
		'extrachill/track-network-click',
		array(
			'label'               => __( 'Track Network Click', 'extrachill-analytics' ),
			'description'         => __( 'Record a link click to another network site.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'dest_host'  => array( 'type' => 'string', 'maxLength' => 253 ),
					'dest_path'  => array( 'type' => 'string', 'maxLength' => 512 ),
					'label'      => array( 'type' => 'string', 'maxLength' => 80 ),
					'placement'  => array( 'type' => 'string', 'maxLength' => 40 ),
					'route'      => array( 'type' => 'string', 'enum' => extrachill_analytics_route_families() ),
					'source_url' => array( 'type' => 'string', 'maxLength' => 2048 ),
				),
				'required'   => array( 'dest_host', 'dest_path', 'label', 'placement', 'route', 'source_url' ),
			),
			'output_schema'       => array( 'type' => 'integer' ),
			'execute_callback'    => 'extrachill_analytics_ability_track_network_click',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array( 'readonly' => false, 'idempotent' => false, 'destructive' => false ),
			),
		)
	);
}

/**
 * Normalize dimensions; shared public admission owns identity and bot stamps.
 *
 * @param array $input Browser dimensions.
 * @return int|WP_Error Event ID or admission error.
 */
function extrachill_analytics_ability_track_network_click( array $input ) {
	$route = sanitize_key( (string) ( $input['route'] ?? '' ) );
	if ( ! in_array( $route, extrachill_analytics_route_families(), true ) ) {
		$route = 'other';
	}

	$path = (string) ( $input['dest_path'] ?? '' );
	// Accept path input only, never a URL or protocol-relative authority.
	if ( '/' !== substr( $path, 0, 1 ) || '//' === substr( $path, 0, 2 ) ) {
		return new WP_Error( 'invalid_network_click', __( 'A destination path is required.', 'extrachill-analytics' ), array( 'status' => 400 ) );
	}
	$path = substr( (string) preg_split( '/[?#]/', $path, 2 )[0], 0, 512 );

	return extrachill_analytics_ability_track_event(
		array(
			'event_type' => EC_ANALYTICS_EVENT_NETWORK_CLICK,
			'event_data' => array(
				'dest_host' => strtolower( trim( (string) ( $input['dest_host'] ?? '' ) ) ),
				'dest_path' => $path,
				'label'     => substr( trim( (string) preg_replace( '/\s+/', ' ', (string) ( $input['label'] ?? '' ) ) ), 0, 80 ),
				'placement' => substr( trim( (string) ( $input['placement'] ?? '' ) ), 0, 40 ),
				'route'     => $route,
			),
			'source_url' => (string) ( $input['source_url'] ?? '' ),
		)
	);
}
