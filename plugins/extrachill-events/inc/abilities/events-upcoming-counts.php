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
					'taxonomy' => array(
						'type'        => 'string',
						'description' => 'Taxonomy to query.',
						'enum'        => array( 'venue', 'location', 'artist', 'festival' ),
					),
					'slug'     => array(
						'type'        => 'string',
						'description' => 'Specific term slug for single-term lookup. Omit for bulk query.',
					),
					'limit'    => array(
						'type'        => 'integer',
						'description' => 'Maximum number of results (0 = unlimited).',
						'default'     => 0,
						'minimum'     => 0,
					),
				),
			),
			'output_schema'       => array(
				'type' => 'array',
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
	$taxonomy = sanitize_text_field( $input['taxonomy'] ?? '' );
	$slug     = sanitize_title( $input['slug'] ?? '' );
	$limit    = (int) ( $input['limit'] ?? 0 );

	$allowed = array( 'venue', 'location', 'artist', 'festival' );
	if ( empty( $taxonomy ) || ! in_array( $taxonomy, $allowed, true ) ) {
		return new \WP_Error(
			'invalid_taxonomy',
			__( 'taxonomy must be one of: venue, location, artist, festival.', 'extrachill-events' ),
			array( 'status' => 400 )
		);
	}

	// Single term query.
	if ( ! empty( $slug ) ) {
		$single = extrachill_events_single_upcoming_count( $slug, $taxonomy );
		if ( null === $single ) {
			return array();
		}
		return array( $single );
	}

	// Bulk query — check transient, run SQL on cold cache.
	$cache_key = 'ec_upcoming_counts_' . $taxonomy;
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		$results = $cached;
		if ( $limit > 0 ) {
			$results = array_slice( $results, 0, $limit );
		}
		return $results;
	}

	// Cold cache — run the query.
	$terms = extrachill_events_query_upcoming_counts( $taxonomy );

	set_transient( $cache_key, $terms, 6 * HOUR_IN_SECONDS );

	if ( $limit > 0 ) {
		$terms = array_slice( $terms, 0, $limit );
	}

	return $terms;
}

/**
 * Query upcoming event counts for all terms in a taxonomy.
 *
 * @param string $taxonomy Taxonomy slug.
 * @return array Array of term count data.
 */
function extrachill_events_query_upcoming_counts( string $taxonomy ): array {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return array();
	}

	global $wpdb;

	$today         = gmdate( 'Y-m-d 00:00:00' );
	$exclude_roots = is_taxonomy_hierarchical( $taxonomy );
	$parent_clause = $exclude_roots ? 'AND tt.parent != 0' : '';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS event_count
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
			INNER JOIN {$wpdb->posts} p ON tr.object_id = p.ID
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed ON p.ID = ed.post_id
			WHERE tt.taxonomy = %s
			AND p.post_type = 'data_machine_events'
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s
			{$parent_clause}
			GROUP BY t.term_id
			ORDER BY event_count DESC",
			$taxonomy,
			$today
		)
	);

	if ( empty( $rows ) ) {
		return array();
	}

	$terms = array();
	foreach ( $rows as $row ) {
		$url = get_term_link( (int) $row->term_id, $taxonomy );
		if ( is_wp_error( $url ) ) {
			continue;
		}

		$terms[] = array(
			'term_id' => (int) $row->term_id,
			'name'    => $row->name,
			'slug'    => $row->slug,
			'count'   => (int) $row->event_count,
			'url'     => $url,
		);
	}

	return $terms;
}

/**
 * Get upcoming count for a single taxonomy term by slug.
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

	global $wpdb;
	$today = gmdate( 'Y-m-d 00:00:00' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			INNER JOIN {$wpdb->prefix}datamachine_event_dates ed ON p.ID = ed.post_id
			WHERE tt.term_id = %d
			AND tt.taxonomy = %s
			AND p.post_type = 'data_machine_events'
			AND p.post_status = 'publish'
			AND ed.start_datetime >= %s",
			$term->term_id,
			$taxonomy,
			$today
		)
	);

	if ( $count < 1 ) {
		return null;
	}

	$url = get_term_link( $term );
	if ( is_wp_error( $url ) ) {
		return null;
	}

	return array(
		'term_id' => $term->term_id,
		'name'    => $term->name,
		'slug'    => $term->slug,
		'count'   => $count,
		'url'     => $url,
	);
}
