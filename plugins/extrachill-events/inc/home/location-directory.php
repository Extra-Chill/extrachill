<?php
/**
 * Events Homepage Location Directory
 *
 * Builds a grouped directory of cities organized by state/country.
 * Replaces the flat badge list with a hierarchical directory that
 * scales as coverage grows.
 *
 * @package ExtraChillEvents
 * @since 0.16.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get location directory data grouped by state.
 *
 * Fetches upcoming event counts from the REST endpoint and organizes
 * cities under their parent state terms using the existing location
 * taxonomy hierarchy (Country → State → City).
 *
 * @return array{states: array, totals: array{cities: int, states: int, events: int}}
 */
function extrachill_events_get_location_directory(): array {
	$cache_key = 'ec_location_directory';
	$cached    = get_transient( $cache_key );

	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$request = new WP_REST_Request( 'GET', '/extrachill/v1/events/upcoming-counts' );
	$request->set_query_params( array( 'taxonomy' => 'location' ) );

	$response = rest_do_request( $request );

	if ( $response->is_error() ) {
		return array(
			'states' => array(),
			'totals' => array(
				'cities' => 0,
				'states' => 0,
				'events' => 0,
			),
		);
	}

	$location_counts = $response->get_data();

	if ( empty( $location_counts ) || ! is_array( $location_counts ) ) {
		return array(
			'states' => array(),
			'totals' => array(
				'cities' => 0,
				'states' => 0,
				'events' => 0,
			),
		);
	}

	// Build lookup of all location terms to resolve hierarchy.
	$all_terms = get_terms(
		array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		)
	);

	if ( is_wp_error( $all_terms ) ) {
		return array(
			'states' => array(),
			'totals' => array(
				'cities' => 0,
				'states' => 0,
				'events' => 0,
			),
		);
	}

	// Index terms by ID for parent lookups.
	$terms_by_id   = array();
	$terms_by_slug = array();
	foreach ( $all_terms as $term ) {
		$terms_by_id[ $term->term_id ]  = $term;
		$terms_by_slug[ $term->slug ]   = $term;
	}

	// Minimum events to show a city badge.
	$min_events = apply_filters( 'extrachill_events_directory_min_count', 5 );

	// Group cities under their parent state.
	$states       = array();
	$total_events = 0;
	$total_cities = 0;

	foreach ( $location_counts as $location ) {
		if ( $location['count'] < $min_events ) {
			continue;
		}

		$term = $terms_by_slug[ $location['slug'] ] ?? null;
		if ( ! $term || ! $term->parent ) {
			continue;
		}

		// Walk up the hierarchy to find the state (child of a country).
		$state_term = extrachill_events_resolve_state_term( $term, $terms_by_id );
		if ( ! $state_term ) {
			continue;
		}

		$state_slug = $state_term->slug;

		if ( ! isset( $states[ $state_slug ] ) ) {
			$state_url = get_term_link( $state_term );
			$states[ $state_slug ] = array(
				'name'   => $state_term->name,
				'slug'   => $state_slug,
				'url'    => is_wp_error( $state_url ) ? '' : $state_url,
				'cities' => array(),
				'total'  => 0,
			);
		}

		$states[ $state_slug ]['cities'][] = array(
			'name'  => $location['name'],
			'slug'  => $location['slug'],
			'count' => $location['count'],
			'url'   => $location['url'],
		);

		$states[ $state_slug ]['total'] += $location['count'];
		$total_events                   += $location['count'];
		++$total_cities;
	}

	// Sort cities within each state by count descending.
	foreach ( $states as &$state ) {
		usort( $state['cities'], function ( $a, $b ) {
			return $b['count'] - $a['count'];
		} );
	}
	unset( $state );

	// Sort states alphabetically.
	uksort( $states, function ( $a, $b ) use ( $states ) {
		return strcmp( $states[ $a ]['name'], $states[ $b ]['name'] );
	} );

	$result = array(
		'states' => $states,
		'totals' => array(
			'cities' => $total_cities,
			'states' => count( $states ),
			'events' => $total_events,
		),
	);

	set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );

	return $result;
}

/**
 * Resolve the state-level term for a city.
 *
 * Walks up the location taxonomy hierarchy to find the state term
 * (a term whose parent is a country like "United States").
 *
 * @param WP_Term $term        The city term.
 * @param array   $terms_by_id All terms indexed by ID.
 * @return WP_Term|null The state term, or null if not found.
 */
function extrachill_events_resolve_state_term( WP_Term $term, array $terms_by_id ): ?WP_Term {
	// Countries are top-level terms (parent = 0).
	// States are children of countries.
	// Cities are children of states.

	$current = $term;
	$depth   = 0;

	while ( $current->parent && $depth < 5 ) {
		$parent = $terms_by_id[ $current->parent ] ?? null;
		if ( ! $parent ) {
			return null;
		}

		// If parent's parent is 0 (top-level), parent is a country and current is a state.
		if ( 0 === $parent->parent ) {
			return $current;
		}

		$current = $parent;
		++$depth;
	}

	return null;
}
