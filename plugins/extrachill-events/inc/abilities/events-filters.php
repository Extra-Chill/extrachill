<?php
/**
 * Events Filters Ability
 *
 * Returns available filter options (venues, promoters, locations)
 * for the events calendar UI.
 *
 * Delegates to data-machine-events/get-filter-options and transforms
 * the response into a flat consumer-friendly shape.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_filters_ability' );

/**
 * Register extrachill/events-filters ability.
 */
function extrachill_events_register_filters_ability(): void {

	wp_register_ability(
		'extrachill/events-filters',
		array(
			'label'               => __( 'Events Filters', 'extrachill-events' ),
			'description'         => __( 'Returns available filter options (venues, promoters, locations) for the events calendar.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'venues'    => array( 'type' => 'array' ),
					'promoters' => array( 'type' => 'array' ),
					'locations' => array( 'type' => 'array' ),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_filters',
			'permission_callback' => '__return_true',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'   => true,
					'idempotent' => true,
				),
			),
		)
	);
}

/**
 * Execute the events-filters ability.
 *
 * @param array $input Input (unused — no params).
 * @return array|WP_Error Filter options or error.
 */
function extrachill_events_ability_filters( array $input ): array|\WP_Error {
	$ability = wp_get_ability( 'data-machine-events/get-filter-options' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'Filter options ability is not registered.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	$result = $ability->execute( array() );

	if ( is_wp_error( $result ) ) {
		return new \WP_Error(
			'filters_error',
			$result->get_error_message(),
			array( 'status' => 500 )
		);
	}

	return extrachill_events_transform_filters_response( $result );
}

/**
 * Transform filter-options result into consumer-friendly shape.
 *
 * Flattens hierarchical terms into a single list per taxonomy.
 *
 * @param array $result Raw result from data-machine-events/get-filter-options.
 * @return array Transformed response.
 */
function extrachill_events_transform_filters_response( array $result ): array {
	$taxonomies = $result['taxonomies'] ?? array();

	$transform_terms = static function ( array $taxonomy_data ): array {
		$terms       = $taxonomy_data['terms'] ?? $taxonomy_data;
		$transformed = array();

		foreach ( $terms as $term ) {
			$entry = array(
				'id'    => (int) ( $term['term_id'] ?? $term['id'] ?? 0 ),
				'name'  => $term['name'] ?? '',
				'slug'  => $term['slug'] ?? '',
				'count' => (int) ( $term['event_count'] ?? $term['count'] ?? 0 ),
			);

			// Flatten children into the same list.
			if ( ! empty( $term['children'] ) ) {
				$transformed[] = $entry;
				foreach ( $term['children'] as $child ) {
					$transformed[] = array(
						'id'    => (int) ( $child['term_id'] ?? $child['id'] ?? 0 ),
						'name'  => $child['name'] ?? '',
						'slug'  => $child['slug'] ?? '',
						'count' => (int) ( $child['event_count'] ?? $child['count'] ?? 0 ),
					);
				}
				continue;
			}

			$transformed[] = $entry;
		}

		return $transformed;
	};

	return array(
		'venues'    => $transform_terms( $taxonomies['venue'] ?? array() ),
		'promoters' => $transform_terms( $taxonomies['promoter'] ?? array() ),
		'locations' => $transform_terms( $taxonomies['location'] ?? array() ),
	);
}
