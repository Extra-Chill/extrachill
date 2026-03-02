<?php
/**
 * Event Filters Endpoint
 *
 * Wraps the data-machine-events/get-filter-options ability behind
 * extrachill/v1/events/filters. Returns available taxonomy terms
 * for venue and promoter filters.
 *
 * @package ExtraChillAPI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_events_filters_route' );

/**
 * Register the filters endpoint.
 */
function extrachill_api_register_events_filters_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/filters',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_events_filters_handler',
			'permission_callback' => '__return_true',
		)
	);
}

/**
 * Handle filters request.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response data or error.
 */
function extrachill_api_events_filters_handler( WP_REST_Request $request ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id ) {
		return new WP_Error(
			'events_site_unavailable',
			__( 'Events site is not configured.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $events_blog_id );
	try {
		$ability = wp_get_ability( 'data-machine-events/get-filter-options' );
		if ( ! $ability ) {
			return new WP_Error(
				'ability_unavailable',
				__( 'Filter options ability is not registered.', 'extrachill-api' ),
				array( 'status' => 500 )
			);
		}

		$result = $ability->execute( array() );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'filters_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return rest_ensure_response( extrachill_api_transform_filters_response( $result ) );
	} finally {
		restore_current_blog();
	}
}

/**
 * Transform ability output into CalendarFilters shape.
 *
 * Ability returns: { success, taxonomies: { location: { terms: [...] }, ... }, ... }
 * Client expects: { venues, promoters, locations, ... } — each an array of { id, name, slug, count }
 *
 * The ability returns whatever taxonomies are registered with the filter system.
 * We transform all of them into a consistent shape and also expose them under
 * the client's expected keys.
 *
 * @param array $result Ability output.
 * @return array Transformed response.
 */
function extrachill_api_transform_filters_response( array $result ): array {
	$taxonomies = $result['taxonomies'] ?? array();

	$transform_terms = function ( array $taxonomy_data ): array {
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

	$response = array(
		'venues'    => $transform_terms( $taxonomies['venue'] ?? array() ),
		'promoters' => $transform_terms( $taxonomies['promoter'] ?? array() ),
		'locations' => $transform_terms( $taxonomies['location'] ?? array() ),
	);

	return $response;
}
