<?php
/**
 * Canonical artist to Events-site artist adapter.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_EVENTS_ARTIST_TERM_META       = '_extrachill_events_artist_term_id';
const EXTRACHILL_EVENTS_ARTIST_BACKFILL_OPTION = 'extrachill_events_artist_identity_backfill_1';

add_action( 'wp_abilities_api_init', 'extrachill_events_register_events_by_artist_ability' );
add_action( 'admin_init', 'extrachill_events_maybe_backfill_artist_identity' );

/**
 * Register the Extra Chill artist-specific event lookup adapter.
 */
function extrachill_events_register_events_by_artist_ability(): void {
	wp_register_ability(
		'extrachill-events/events-by-artist',
		array(
			'label'               => __( 'Events By Canonical Artist', 'extrachill-events' ),
			'description'         => __( 'Resolve a canonical Extra Chill artist to its Events-site artist term and return the artist events.', 'extrachill-events' ),
			'category'            => 'extrachill-events',
			'input_schema'        => array(
				'type'       => 'object',
				'anyOf'      => array(
					array( 'required' => array( 'artist_term_id' ) ),
					array( 'required' => array( 'term_slug' ) ),
				),
				'properties' => array(
					'artist_term_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Canonical main-site artist term ID.', 'extrachill-events' ),
					),
					'term_slug'      => array(
						'type'        => 'string',
						'description' => __( 'Legacy Events-site artist slug used during migration.', 'extrachill-events' ),
					),
					'scope'          => array(
						'type' => 'string',
						'enum' => array( 'upcoming', 'past', 'all' ),
					),
					'limit'          => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'taxonomy'  => array( 'type' => 'string' ),
					'term_id'   => array( 'type' => 'integer' ),
					'term_slug' => array( 'type' => 'string' ),
					'found'     => array( 'type' => 'boolean' ),
					'upcoming'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'past'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_events_ability_events_by_artist',
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
 * Resolve a canonical artist and delegate its event lookup to Data Machine Events.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_events_ability_events_by_artist( array $input ) {
	$artist_term_id = absint( $input['artist_term_id'] ?? 0 );
	$legacy_slug    = sanitize_title( $input['term_slug'] ?? '' );
	if ( $artist_term_id < 1 && '' === $legacy_slug ) {
		return new WP_Error( 'missing_artist_identity', __( 'A canonical artist_term_id or legacy term_slug is required.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$resolved = extrachill_events_resolve_artist_term( $artist_term_id, $legacy_slug );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$ability = wp_get_ability( 'data-machine-events/events-by-term' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_unavailable', __( 'The events-by-term ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$delegate_input = array(
		'taxonomy'  => 'artist',
		'term_id'   => $resolved['term_id'],
		'term_slug' => $resolved['term_slug'],
	);
	if ( isset( $input['scope'] ) ) {
		$delegate_input['scope'] = sanitize_key( $input['scope'] );
	}
	if ( isset( $input['limit'] ) ) {
		$delegate_input['limit'] = absint( $input['limit'] );
	}

	$events_blog_id = extrachill_events_artist_blog_id( 'events' );
	if ( $events_blog_id < 1 ) {
		return new WP_Error( 'events_site_unresolved', __( 'Could not resolve the Events site.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	switch_to_blog( $events_blog_id );
	try {
		return $ability->execute( $delegate_input );
	} finally {
		restore_current_blog();
	}
}

/**
 * Resolve a canonical main-site artist term to a validated local Events term.
 *
 * @param int    $artist_term_id Canonical main-site artist term ID, or zero for legacy lookup.
 * @param string $legacy_slug    Optional legacy local slug.
 * @return array{term_id:int,term_slug:string}|WP_Error
 */
function extrachill_events_resolve_artist_term( int $artist_term_id, string $legacy_slug = '' ) {
	$main_blog_id   = extrachill_events_artist_blog_id( 'main' );
	$events_blog_id = extrachill_events_artist_blog_id( 'events' );
	if ( $main_blog_id < 1 || $events_blog_id < 1 ) {
		return new WP_Error( 'artist_sites_unresolved', __( 'Could not resolve the main and Events sites.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$mapped_term_id = 0;
	$canonical_slug = '';
	if ( $artist_term_id > 0 ) {
		switch_to_blog( $main_blog_id );
		try {
			$canonical = get_term( $artist_term_id, 'artist' );
			if ( ! $canonical || is_wp_error( $canonical ) || 'artist' !== $canonical->taxonomy ) {
				return new WP_Error( 'invalid_canonical_artist', __( 'The canonical artist term is missing or invalid.', 'extrachill-events' ), array( 'status' => 404 ) );
			}

			$canonical_slug = (string) $canonical->slug;
			$mapped_term_id = absint( get_term_meta( $artist_term_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, true ) );
			if ( $mapped_term_id > 0 ) {
				$claims = extrachill_events_find_artist_mapping_claims( $mapped_term_id );
				if ( count( $claims ) > 1 ) {
					return new WP_Error( 'duplicate_artist_mapping', __( 'Multiple canonical artists claim the same Events artist term.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	$lookup_slug = $artist_term_id > 0 ? $canonical_slug : $legacy_slug;
	switch_to_blog( $events_blog_id );
	try {
		if ( $mapped_term_id > 0 ) {
			$local = get_term( $mapped_term_id, 'artist' );
			if ( ! $local || is_wp_error( $local ) || 'artist' !== $local->taxonomy ) {
				return new WP_Error( 'stale_artist_mapping', __( 'The mapped Events artist term is missing or has the wrong taxonomy.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
		} else {
			$local = '' !== $lookup_slug ? get_term_by( 'slug', $lookup_slug, 'artist' ) : false;
			if ( ! $local || is_wp_error( $local ) ) {
				return new WP_Error( 'artist_mapping_missing', __( 'No Events artist mapping or legacy slug match exists.', 'extrachill-events' ), array( 'status' => 404 ) );
			}

			if ( $artist_term_id > 0 ) {
				switch_to_blog( $main_blog_id );
				try {
					$claims = extrachill_events_find_artist_mapping_claims( (int) $local->term_id );
					if ( ! empty( $claims ) ) {
						return new WP_Error( 'duplicate_artist_mapping', __( 'The matched Events artist term is already claimed by another canonical artist.', 'extrachill-events' ), array( 'status' => 409 ) );
					}
				} finally {
					restore_current_blog();
				}
			}
		}

		return array(
			'term_id'   => (int) $local->term_id,
			'term_slug' => (string) $local->slug,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Find canonical main-site artists that claim a local Events artist term.
 *
 * Must be called in main-site context.
 *
 * @param int $events_term_id Events-site artist term ID.
 * @return int[] Canonical artist term IDs.
 */
function extrachill_events_find_artist_mapping_claims( int $events_term_id ): array {
	// A single indexed metadata value bounds this duplicate-claim check.
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	$claims = get_terms(
		array(
			'taxonomy'   => 'artist',
			'hide_empty' => false,
			'fields'     => 'ids',
			'meta_key'   => EXTRACHILL_EVENTS_ARTIST_TERM_META,
			'meta_value' => $events_term_id,
		)
	);
	// phpcs:enable

	return is_wp_error( $claims ) ? array() : array_map( 'intval', (array) $claims );
}

/**
 * Resolve one of the network's known blog IDs.
 *
 * @param string $site Site key.
 * @return int Blog ID, or zero.
 */
function extrachill_events_artist_blog_id( string $site ): int {
	return function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( $site ) : 0;
}

/**
 * Run the bounded one-time exact-slug artist mapping backfill.
 */
function extrachill_events_maybe_backfill_artist_identity(): void {
	$events_blog_id = extrachill_events_artist_blog_id( 'events' );
	if ( $events_blog_id < 1 ) {
		return;
	}

	switch_to_blog( $events_blog_id );
	try {
		$existing_report = get_option( EXTRACHILL_EVENTS_ARTIST_BACKFILL_OPTION, false );
		if ( is_array( $existing_report ) && ! empty( $existing_report['complete'] ) ) {
			return;
		}
	} finally {
		restore_current_blog();
	}

	$report = extrachill_events_backfill_artist_identity();
	if ( is_wp_error( $report ) ) {
		return;
	}

	switch_to_blog( $events_blog_id );
	try {
		update_option( EXTRACHILL_EVENTS_ARTIST_BACKFILL_OPTION, $report, false );
	} finally {
		restore_current_blog();
	}
}

/**
 * Map bound canonical artists to unclaimed local artist terms by exact slug.
 *
 * @return array|WP_Error Audit report.
 */
function extrachill_events_backfill_artist_identity() {
	$main_blog_id   = extrachill_events_artist_blog_id( 'main' );
	$events_blog_id = extrachill_events_artist_blog_id( 'events' );
	if ( $main_blog_id < 1 || $events_blog_id < 1 ) {
		return new WP_Error( 'artist_sites_unresolved', __( 'Could not resolve the main and Events sites.', 'extrachill-events' ) );
	}

	$report = array(
		'version'         => 1,
		'complete'        => true,
		'mapped'          => array(),
		'existing'        => array(),
		'missing'         => array(),
		'unmatched_local' => array(),
		'stale'           => array(),
		'ambiguous'       => array(),
		'collisions'      => array(),
		'write_failures'  => array(),
	);

	switch_to_blog( $main_blog_id );
	try {
		$canonical_terms = get_terms(
			array(
				'taxonomy'   => 'artist',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $canonical_terms ) ) {
			return $canonical_terms;
		}

		$all_canonical_terms = $canonical_terms;
		$canonical_terms     = array_values(
			array_filter(
				$all_canonical_terms,
				static function ( $term ): bool {
					return absint( get_term_meta( $term->term_id, '_artist_profile_id', true ) ) > 0;
				}
			)
		);
		$mappings            = array();
		foreach ( $all_canonical_terms as $term ) {
			$mappings[ (int) $term->term_id ] = absint( get_term_meta( $term->term_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, true ) );
		}
	} finally {
		restore_current_blog();
	}

	switch_to_blog( $events_blog_id );
	try {
		$local_terms = get_terms(
			array(
				'taxonomy'   => 'artist',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $local_terms ) ) {
			return $local_terms;
		}
	} finally {
		restore_current_blog();
	}

	$local_by_id   = array();
	$local_by_slug = array();
	foreach ( $local_terms as $term ) {
		$local_by_id[ (int) $term->term_id ]     = $term;
		$local_by_slug[ (string) $term->slug ][] = $term;
	}

	$claims = array();
	foreach ( $mappings as $canonical_id => $local_id ) {
		if ( $local_id < 1 ) {
			continue;
		}
		if ( ! isset( $local_by_id[ $local_id ] ) ) {
			$report['stale'][] = array(
				'artist_term_id' => $canonical_id,
				'events_term_id' => $local_id,
			);
			continue;
		}
		$claims[ $local_id ][] = $canonical_id;
	}

	foreach ( $claims as $local_id => $canonical_ids ) {
		if ( count( $canonical_ids ) > 1 ) {
			$report['collisions'][] = array(
				'events_term_id'  => $local_id,
				'artist_term_ids' => $canonical_ids,
			);
			continue;
		}
		$report['existing'][] = array(
			'artist_term_id' => $canonical_ids[0],
			'events_term_id' => $local_id,
		);
	}

	$canonical_by_slug = array();
	foreach ( $all_canonical_terms as $term ) {
		$canonical_by_slug[ (string) $term->slug ][] = $term;
	}

	foreach ( $canonical_terms as $term ) {
		$canonical_id = (int) $term->term_id;
		if ( $mappings[ $canonical_id ] > 0 ) {
			continue;
		}
		if ( count( $canonical_by_slug[ (string) $term->slug ] ) > 1 ) {
			$report['ambiguous'][] = array(
				'artist_term_id' => $canonical_id,
				'slug'           => (string) $term->slug,
			);
			continue;
		}

		$candidates = $local_by_slug[ (string) $term->slug ] ?? array();
		if ( empty( $candidates ) ) {
			$report['missing'][] = array(
				'artist_term_id' => $canonical_id,
				'slug'           => (string) $term->slug,
			);
			continue;
		}
		if ( count( $candidates ) > 1 ) {
			$report['ambiguous'][] = array(
				'artist_term_id' => $canonical_id,
				'slug'           => (string) $term->slug,
			);
			continue;
		}

		$local_id = (int) $candidates[0]->term_id;
		if ( ! empty( $claims[ $local_id ] ) ) {
			$report['collisions'][] = array(
				'events_term_id'  => $local_id,
				'artist_term_ids' => array_merge( $claims[ $local_id ], array( $canonical_id ) ),
			);
			continue;
		}

		switch_to_blog( $main_blog_id );
		try {
			$updated = update_term_meta( $canonical_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, $local_id );
		} finally {
			restore_current_blog();
		}
		if ( false === $updated || is_wp_error( $updated ) ) {
			$report['complete']         = false;
			$report['write_failures'][] = array(
				'artist_term_id' => $canonical_id,
				'events_term_id' => $local_id,
			);
			continue;
		}
		$claims[ $local_id ] = array( $canonical_id );
		$report['mapped'][]  = array(
			'artist_term_id' => $canonical_id,
			'events_term_id' => $local_id,
		);
	}

	foreach ( $local_terms as $term ) {
		$slug = (string) $term->slug;
		if ( empty( $canonical_by_slug[ $slug ] ) ) {
			$report['unmatched_local'][] = array(
				'events_term_id' => (int) $term->term_id,
				'slug'           => $slug,
			);
		} elseif ( count( $canonical_by_slug[ $slug ] ) > 1 ) {
			$report['ambiguous'][] = array(
				'events_term_id' => (int) $term->term_id,
				'slug'           => $slug,
			);
		}
	}

	return $report;
}
