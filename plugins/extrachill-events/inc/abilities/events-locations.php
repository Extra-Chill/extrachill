<?php
/**
 * Canonical Event Locations Ability
 *
 * Searches and resolves selectable locations from the Events site's
 * authoritative location taxonomy.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_locations_ability' );

/**
 * Register extrachill/events-locations.
 */
function extrachill_events_register_locations_ability(): void {
	$location_schema         = array(
		'type'       => 'object',
		'properties' => array(
			'term_id'     => array( 'type' => 'integer' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'archive_url' => array( 'type' => 'string' ),
			'coordinates' => array(
				'type'       => array( 'object', 'null' ),
				'properties' => array(
					'lat' => array( 'type' => 'number' ),
					'lon' => array( 'type' => 'number' ),
				),
			),
			'hierarchy'   => array(
				'type'       => 'object',
				'properties' => array(
					'region' => array( 'type' => 'string' ),
					'state'  => array( 'type' => 'string' ),
					'label'  => array( 'type' => 'string' ),
				),
			),
		),
	);
	$resolved_schema         = $location_schema;
	$resolved_schema['type'] = array( 'object', 'null' );

	wp_register_ability(
		'extrachill/events-locations',
		array(
			'label'               => __( 'Canonical Event Locations', 'extrachill-events' ),
			'description'         => __( 'Search or resolve selectable locations from the canonical Events taxonomy.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'mode' ),
				'properties' => array(
					'mode'   => array(
						'type' => 'string',
						'enum' => array( 'search', 'resolve' ),
					),
					'search' => array( 'type' => 'string' ),
					'slug'   => array( 'type' => 'string' ),
					'limit'  => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 20,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'locations' => array(
						'type'  => 'array',
						'items' => $location_schema,
					),
					'location'  => $resolved_schema,
				),
			),
			'execute_callback'    => 'extrachill_events_ability_locations',
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
 * Search or resolve canonical event locations.
 *
 * Search returns an empty locations array for an empty query or no matches.
 * Resolve returns location_not_found for unknown or non-selectable slugs.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Canonical location response or error.
 */
function extrachill_events_ability_locations( array $input ): array|\WP_Error {
	$mode           = sanitize_key( $input['mode'] ?? '' );
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$events_blog_id = (int) apply_filters( 'extrachill_events_canonical_blog_id', $events_blog_id );

	if ( $events_blog_id <= 0 || ( is_multisite() && ! get_site( $events_blog_id ) ) ) {
		return new \WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$switched = get_current_blog_id() !== $events_blog_id;
	if ( $switched && ! switch_to_blog( $events_blog_id ) ) {
		return new \WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	try {
		if ( ! taxonomy_exists( 'location' ) ) {
			return new \WP_Error( 'location_taxonomy_unavailable', __( 'The canonical location taxonomy is unavailable.', 'extrachill-events' ), array( 'status' => 500 ) );
		}

		if ( 'search' === $mode ) {
			$search = trim( sanitize_text_field( $input['search'] ?? '' ) );
			if ( '' === $search ) {
				return array(
					'locations' => array(),
					'location'  => null,
				);
			}

			$terms = get_terms(
				array(
					'taxonomy'   => 'location',
					'hide_empty' => false,
					'search'     => $search,
					'number'     => 100,
				)
			);
			if ( is_wp_error( $terms ) ) {
				return new \WP_Error( 'location_search_failed', $terms->get_error_message(), array( 'status' => 500 ) );
			}

			$locations = array();
			$limit     = min( 20, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
			foreach ( $terms as $term ) {
				$location = extrachill_events_prepare_canonical_location( $term );
				if ( null === $location ) {
					continue;
				}
				$locations[] = $location;
				if ( count( $locations ) >= $limit ) {
					break;
				}
			}

			return array(
				'locations' => $locations,
				'location'  => null,
			);
		}

		if ( 'resolve' !== $mode ) {
			return new \WP_Error( 'invalid_location_mode', __( 'mode must be search or resolve.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_title( $input['slug'] ?? '' );
		if ( '' === $slug ) {
			return new \WP_Error( 'invalid_location_slug', __( 'A location slug is required for resolve mode.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$term     = get_term_by( 'slug', $slug, 'location' );
		$location = $term && ! is_wp_error( $term ) ? extrachill_events_prepare_canonical_location( $term ) : null;
		if ( null === $location ) {
			return new \WP_Error( 'location_not_found', __( 'No selectable canonical event location matched that slug.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		return array(
			'locations' => array(),
			'location'  => $location,
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Format a selectable city term for public consumers.
 *
 * The canonical hierarchy is region root, state/province, then city. Deeper
 * children remain selectable because the taxonomy also models city districts.
 *
 * @param WP_Term $term Location term.
 * @return array|null Formatted location, or null when not selectable.
 */
function extrachill_events_prepare_canonical_location( WP_Term $term ): ?array {
	$ancestor_ids = get_ancestors( $term->term_id, 'location', 'taxonomy' );
	if ( count( $ancestor_ids ) < 2 ) {
		return null;
	}

	$ancestors = array();
	foreach ( array_reverse( $ancestor_ids ) as $ancestor_id ) {
		$ancestor = get_term( $ancestor_id, 'location' );
		if ( $ancestor && ! is_wp_error( $ancestor ) ) {
			$ancestors[] = $ancestor;
		}
	}

	if ( count( $ancestors ) < 2 ) {
		return null;
	}

	$archive_url = get_term_link( $term );
	$state       = $ancestors[ count( $ancestors ) - 1 ]->name;

	return array(
		'term_id'     => (int) $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'archive_url' => is_wp_error( $archive_url ) ? '' : $archive_url,
		'coordinates' => extrachill_events_get_location_coordinates( (int) $term->term_id ),
		'hierarchy'   => array(
			'region' => $ancestors[0]->name,
			'state'  => $state,
			'label'  => sprintf( '%s, %s', $term->name, $state ),
		),
	);
}
