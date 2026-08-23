<?php
/**
 * Canonical artist to Events-site artist adapter.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/Core/ArtistMappingLock.php';

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
				'required'   => array( 'artist_term_id' ),
				'properties' => array(
					'artist_term_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => __( 'Canonical main-site artist term ID.', 'extrachill-events' ),
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
	if ( $artist_term_id < 1 ) {
		return new WP_Error( 'missing_artist_identity', __( 'A canonical artist_term_id is required.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	$resolved = extrachill_events_resolve_artist_term( $artist_term_id );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}

	$ability = wp_get_ability( 'data-machine-events/events-by-term' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_unavailable', __( 'The events-by-term ability is not available.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	$delegate_input = array(
		'taxonomy' => 'artist',
		'term_id'  => $resolved['term_id'],
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
 * @param int $artist_term_id Canonical main-site artist term ID.
 * @return array{term_id:int,term_slug:string}|WP_Error
 */
function extrachill_events_resolve_artist_term( int $artist_term_id ) {
	$main_blog_id   = extrachill_events_artist_blog_id( 'main' );
	$events_blog_id = extrachill_events_artist_blog_id( 'events' );
	if ( $main_blog_id < 1 || $events_blog_id < 1 ) {
		return new WP_Error( 'artist_sites_unresolved', __( 'Could not resolve the main and Events sites.', 'extrachill-events' ), array( 'status' => 500 ) );
	}

	switch_to_blog( $main_blog_id );
	try {
		$canonical = extrachill_events_read_artist_term( $artist_term_id );
		if ( is_wp_error( $canonical ) ) {
			return $canonical;
		}
		if ( ! $canonical || 'artist' !== $canonical->taxonomy ) {
			return new WP_Error( 'invalid_canonical_artist', __( 'The canonical artist term is missing or invalid.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$mapped_term_id = absint( get_term_meta( $artist_term_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, true ) );
		if ( $mapped_term_id < 1 ) {
			return new WP_Error( 'artist_mapping_missing', __( 'No Events artist mapping exists.', 'extrachill-events' ), array( 'status' => 404 ) );
		}

		$claims = extrachill_events_read_artist_mapping_claims( $mapped_term_id );
		if ( is_wp_error( $claims ) ) {
			return $claims;
		}
		if ( count( $claims ) > 1 ) {
			return new WP_Error( 'duplicate_artist_mapping', __( 'Multiple canonical artists claim the same Events artist term.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
	} finally {
		restore_current_blog();
	}

	switch_to_blog( $events_blog_id );
	try {
		$local = extrachill_events_read_artist_term( $mapped_term_id );
		if ( is_wp_error( $local ) ) {
			return $local;
		}
		if ( ! $local || 'artist' !== $local->taxonomy ) {
			return new WP_Error( 'stale_artist_mapping', __( 'The mapped Events artist term is missing or has the wrong taxonomy.', 'extrachill-events' ), array( 'status' => 409 ) );
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
	$claims = extrachill_events_read_artist_mapping_claims( $events_term_id );
	return is_wp_error( $claims ) ? array() : $claims;
}

/**
 * Read reverse mapping claims without hiding taxonomy or database errors.
 *
 * Must be called in main-site context. Lock-owning mutation callers use this
 * after acquiring ArtistMappingLock so a concurrent canonical claim cannot pass.
 *
 * @param int $events_term_id Events artist term ID.
 * @return int[]|WP_Error Canonical claimant IDs or a read error.
 */
function extrachill_events_read_artist_mapping_claims( int $events_term_id ) {
	global $wpdb;

	$wpdb->flush();
	// A single indexed metadata value bounds this duplicate-claim check.
	// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
	$query  = new WP_Term_Query();
	$claims = $query->query(
		array(
			'taxonomy'      => 'artist',
			'hide_empty'    => false,
			'fields'        => 'ids',
			'meta_key'      => EXTRACHILL_EVENTS_ARTIST_TERM_META,
			'meta_value'    => $events_term_id,
			'cache_results' => false,
		)
	);
	// phpcs:enable
	$database_error = (string) $wpdb->last_error;
	if ( '' !== $database_error ) {
		return new WP_Error( 'artist_mapping_claims_query_failed', __( 'Canonical artist mapping claims could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	return is_wp_error( $claims ) ? $claims : array_map( 'intval', (array) $claims );
}

/**
 * Read one uncached artist term while preserving database failures.
 *
 * @param int $artist_term_id Artist term ID on the current site.
 * @return WP_Term|null|WP_Error Term, absence, or read error.
 */
function extrachill_events_read_artist_term( int $artist_term_id ) {
	global $wpdb;

	$wpdb->flush();
	$query          = new WP_Term_Query();
	$terms          = $query->query(
		array(
			'taxonomy'      => 'artist',
			'hide_empty'    => false,
			'include'       => array( $artist_term_id ),
			'number'        => 1,
			'cache_results' => false,
		)
	);
	$database_error = (string) $wpdb->last_error;
	if ( '' !== $database_error || is_wp_error( $terms ) ) {
		return new WP_Error( 'artist_term_query_failed', __( 'Artist taxonomy data could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
	}
	return empty( $terms ) ? null : reset( $terms );
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

		$local_id     = (int) $candidates[0]->term_id;
		$mapping_lock = \ExtraChillEvents\Core\ArtistMappingLock::acquire( $local_id );
		if ( is_wp_error( $mapping_lock ) ) {
			$report['complete']         = false;
			$report['write_failures'][] = array(
				'artist_term_id' => $canonical_id,
				'events_term_id' => $local_id,
			);
			continue;
		}

		$release_failed = false;
		$already_mapped = false;
		try {
			switch_to_blog( $main_blog_id );
			try {
				$current_claims = extrachill_events_read_artist_mapping_claims( $local_id );
				if ( is_wp_error( $current_claims ) ) {
					$updated = $current_claims;
				} elseif ( array_diff( $current_claims, array( $canonical_id ) ) ) {
					$updated = null;
				} elseif ( in_array( $canonical_id, $current_claims, true ) ) {
					$already_mapped = true;
					$updated        = true;
				} else {
					$updated = update_term_meta( $canonical_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, $local_id );
				}
			} finally {
				restore_current_blog();
			}
		} finally {
			$released       = \ExtraChillEvents\Core\ArtistMappingLock::release( $mapping_lock );
			$release_failed = is_wp_error( $released );
		}
		if ( null === $updated ) {
			$report['collisions'][] = array(
				'events_term_id'  => $local_id,
				'artist_term_ids' => array_values( array_unique( array_merge( $current_claims, array( $canonical_id ) ) ) ),
			);
			if ( $release_failed ) {
				$report['complete']         = false;
				$report['write_failures'][] = array(
					'artist_term_id' => $canonical_id,
					'events_term_id' => $local_id,
				);
			}
			continue;
		}
		if ( $already_mapped && ! $release_failed ) {
			$claims[ $local_id ]  = array( $canonical_id );
			$report['existing'][] = array(
				'artist_term_id' => $canonical_id,
				'events_term_id' => $local_id,
			);
			continue;
		}
		if ( false === $updated || is_wp_error( $updated ) || $release_failed ) {
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
