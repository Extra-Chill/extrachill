<?php
/**
 * User Event Location Ability
 *
 * Provides canonical market discovery for the network-wide user preference.
 *
 * @package ExtraChill\Users
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_users_register_event_locations_ability' );

/**
 * Register the public user event location discovery Ability.
 */
function extrachill_users_register_event_locations_ability() {
	$location_schema         = array(
		'type'       => 'object',
		'properties' => array(
			'term_id'     => array( 'type' => 'integer' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'url'         => array( 'type' => 'string' ),
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
		'extrachill/user-event-locations',
		array(
			'label'               => __( 'User Event Locations', 'extrachill-users' ),
			'description'         => __( 'Search or resolve canonical event markets for the user default event location preference.', 'extrachill-users' ),
			'category'            => 'extrachill-users',
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
			'execute_callback'    => 'extrachill_users_ability_event_locations',
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
 * Search or resolve selectable cities on the authoritative Events site.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Location response or error.
 */
function extrachill_users_ability_event_locations( array $input ) {
	$mode           = sanitize_key( $input['mode'] ?? '' );
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 0;
	$events_blog_id = (int) apply_filters( 'extrachill_users_events_blog_id', $events_blog_id );

	if ( $events_blog_id <= 0 || ( is_multisite() && ! get_site( $events_blog_id ) ) ) {
		return new WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	$switched = get_current_blog_id() !== $events_blog_id;
	if ( $switched && ! switch_to_blog( $events_blog_id ) ) {
		return new WP_Error( 'events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
	}

	try {
		if ( ! taxonomy_exists( 'location' ) ) {
			return new WP_Error( 'location_taxonomy_unavailable', __( 'The canonical location taxonomy is unavailable.', 'extrachill-users' ), array( 'status' => 500 ) );
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
				return new WP_Error( 'location_search_failed', $terms->get_error_message(), array( 'status' => 500 ) );
			}

			$locations = array();
			$limit     = min( 20, max( 1, (int) ( $input['limit'] ?? 10 ) ) );
			foreach ( $terms as $term ) {
				$location = extrachill_users_prepare_event_location( $term );
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
			return new WP_Error( 'invalid_location_mode', __( 'mode must be search or resolve.', 'extrachill-users' ), array( 'status' => 400 ) );
		}

		$slug = sanitize_title( $input['slug'] ?? '' );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_location_slug', __( 'A location slug is required for resolve mode.', 'extrachill-users' ), array( 'status' => 400 ) );
		}

		$term     = get_term_by( 'slug', $slug, 'location' );
		$location = $term && ! is_wp_error( $term ) ? extrachill_users_prepare_event_location( $term ) : null;
		if ( null === $location ) {
			return new WP_Error( 'location_not_found', __( 'No selectable canonical event location matched that slug.', 'extrachill-users' ), array( 'status' => 404 ) );
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
 * Format a selectable city term for preference consumers.
 *
 * @param WP_Term $term Location term.
 * @return array|null Formatted location, or null when not selectable.
 */
function extrachill_users_prepare_event_location( WP_Term $term ) {
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

	$url         = get_term_link( $term );
	$state       = $ancestors[ count( $ancestors ) - 1 ]->name;
	$coordinates = extrachill_users_get_event_location_coordinates( (int) $term->term_id );

	return array(
		'term_id'     => (int) $term->term_id,
		'name'        => $term->name,
		'slug'        => $term->slug,
		'url'         => is_wp_error( $url ) ? '' : $url,
		'coordinates' => $coordinates,
		'hierarchy'   => array(
			'region' => $ancestors[0]->name,
			'state'  => $state,
			'label'  => sprintf( '%s, %s', $term->name, $state ),
		),
	);
}

/**
 * Parse coordinates stored on an Events location term.
 *
 * @param int $term_id Location term ID.
 * @return array|null Parsed coordinates.
 */
function extrachill_users_get_event_location_coordinates( int $term_id ) {
	$value = get_term_meta( $term_id, '_location_coordinates', true );
	if ( ! is_string( $value ) || false === strpos( $value, ',' ) ) {
		return null;
	}

	$parts = explode( ',', $value, 2 );
	$lat   = (float) trim( $parts[0] );
	$lon   = (float) trim( $parts[1] );
	if ( 0.0 === $lat && 0.0 === $lon ) {
		return null;
	}

	return array(
		'lat' => $lat,
		'lon' => $lon,
	);
}
