<?php
/**
 * Events Upcoming Counts Ability
 *
 * Returns upcoming-event counts per taxonomy term.  Supports bulk
 * queries (all terms in a taxonomy) and single-term lookups by slug.
 * Results are cached with a 6-hour transient.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_events_register_upcoming_counts_ability' );

/**
 * Register extrachill/events-upcoming-counts ability.
 */
function extrachill_events_register_upcoming_counts_ability(): void {

	wp_register_ability(
		'extrachill/events-upcoming-counts',
		array(
			'label'               => __( 'Events Upcoming Counts', 'extrachill-events' ),
			'description'         => __( 'Returns upcoming-event counts per taxonomy term. Supports venue, location, artist, and festival taxonomies.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'required'   => array( 'taxonomy' ),
				'properties' => array(
					'taxonomy'      => array(
						'type'        => 'string',
						'description' => 'Taxonomy to query.',
						'enum'        => array( 'venue', 'location', 'artist', 'festival' ),
					),
					'slug'          => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Specific term slug for single-term lookup. Omit or pass null/empty for bulk query.',
					),
					'location_slug' => array(
						'type'        => array( 'string', 'null' ),
						'description' => 'Optional location term slug to scope bulk venue counts to a single city. Only applied when taxonomy is "venue".',
					),
					'limit'         => array(
						'type'        => 'integer',
						'description' => 'Maximum number of results (0 = unlimited).',
						'default'     => 0,
						'minimum'     => 0,
					),
					'rollup'        => array(
						'type'        => 'boolean',
						'description' => 'Roll counts up the hierarchy: each non-leaf term reports the distinct upcoming events across its whole subtree. Only meaningful for hierarchical taxonomies (location). Default false.',
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'  => 'array',
				'items' => array(
					'type'       => 'object',
					'properties' => array(
						'term_id' => array( 'type' => 'integer' ),
						'name'    => array( 'type' => 'string' ),
						'slug'    => array( 'type' => 'string' ),
						'count'   => array( 'type' => 'integer' ),
						'url'     => array( 'type' => 'string' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_upcoming_counts',
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
 * Execute the events-upcoming-counts ability.
 *
 * @param array $input Input with taxonomy, optional slug and limit.
 * @return array|WP_Error Array of term counts or error.
 */
function extrachill_events_ability_upcoming_counts( array $input ): array|\WP_Error {
	$taxonomy      = sanitize_text_field( $input['taxonomy'] ?? '' );
	$slug          = sanitize_title( $input['slug'] ?? '' );
	$location_slug = sanitize_title( $input['location_slug'] ?? '' );
	$limit         = (int) ( $input['limit'] ?? 0 );
	$rollup        = ! empty( $input['rollup'] );

	$allowed = array( 'venue', 'location', 'artist', 'festival' );
	if ( empty( $taxonomy ) || ! in_array( $taxonomy, $allowed, true ) ) {
		return new \WP_Error(
			'invalid_taxonomy',
			__( 'taxonomy must be one of: venue, location, artist, festival.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	// location_slug filter only applies to venue bulk queries.
	if ( '' !== $location_slug && 'venue' !== $taxonomy ) {
		$location_slug = '';
	}

	// Single term query.
	if ( ! empty( $slug ) ) {
		$single = extrachill_events_single_upcoming_count( $slug, $taxonomy );
		if ( null === $single ) {
			return array();
		}
		return array( $single );
	}

	// Bulk query — check transient, delegate to the data-machine-events
	// ability on cold cache. Cache key varies by every parameter that
	// changes the result set.
	$cache_key = 'ec_upcoming_counts_' . $taxonomy;
	if ( '' !== $location_slug ) {
		$cache_key .= '_loc_' . $location_slug;
	}
	if ( $rollup ) {
		$cache_key .= '_rollup';
	}
	$cached = get_transient( $cache_key );

	if ( false !== $cached ) {
		$results = $cached;
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}
		return $results;
	}

	// Cold cache — delegate to the owning ability.
	$terms = extrachill_events_query_upcoming_counts( $taxonomy, $location_slug, $rollup );
	if ( is_wp_error( $terms ) ) {
		return $terms;
	}

	set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

	if ( $limit > 0 ) {
		$terms = array_slice( $terms, 0, $limit );
	}

	return $terms;
}

/**
 * Query upcoming event counts for all terms in a taxonomy.
 *
 * Thin wrapper over the data-machine-events/get-upcoming-counts ability — the
 * single source of truth for the count query. This function owns only the
 * EC-specific concerns the generic layer should not: mapping a venue
 * `location_slug` scope onto the ability's co-occurrence filter, and passing
 * through the optional hierarchy `rollup` mode. Caching + limit slicing are
 * handled by the caller.
 *
 * @param string $taxonomy      Taxonomy slug.
 * @param string $location_slug Optional location term slug to scope venue counts to.
 *                              When non-empty (and taxonomy is venue), only events also
 *                              tagged with this location term are counted.
 * @param bool   $rollup        Roll counts up the hierarchy (ancestor subtree totals).
 * @return array|\WP_Error Array of term count data, or WP_Error on failure.
 */
function extrachill_events_query_upcoming_counts( string $taxonomy, string $location_slug = '', bool $rollup = false ): array|\WP_Error {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	$ability = wp_get_ability( 'data-machine-events/get-upcoming-counts' );
	if ( ! $ability ) {
		return new \WP_Error(
			'ability_unavailable',
			__( 'data-machine-events/get-upcoming-counts ability is not available.', 'extrachill-events' ),
			array( 'status' => 500 )
		);
	}

	$args = array( 'taxonomy' => $taxonomy );
	if ( $rollup ) {
		$args['rollup'] = true;
	}

	// Map the venue location scope onto the ability's co-occurrence filter:
	// count distinct venues of upcoming events also tagged with this location.
	if ( '' !== $location_slug ) {
		$location_term = get_term_by( 'slug', $location_slug, 'location' );
		if ( ! $location_term || is_wp_error( $location_term ) ) {
			// Unknown location — return empty rather than unscoped results.
			return array();
		}
		$args['filter_taxonomy'] = 'location';
		$args['filter_term_id']  = (int) $location_term->term_id;
	}

	$result = $ability->execute( $args );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result['terms'] ?? array();
}

/**
 * Get upcoming count for a single taxonomy term by slug.
 *
 * Delegates to the data-machine-events/get-upcoming-counts ability (bulk
 * query for the taxonomy, then pick the requested term) so all counting goes
 * through the single owning primitive.
 *
 * @param string $slug     Term slug.
 * @param string $taxonomy Taxonomy slug.
 * @return array|null Term count data or null if not found / zero.
 */
function extrachill_events_single_upcoming_count( string $slug, string $taxonomy ): ?array {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}

	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}

	$terms = extrachill_events_query_upcoming_counts( $taxonomy );
	if ( is_wp_error( $terms ) ) {
		return null;
	}

	foreach ( $terms as $row ) {
		if ( (int) $row['term_id'] === (int) $term->term_id ) {
			return $row;
		}
	}

	return null;
}
