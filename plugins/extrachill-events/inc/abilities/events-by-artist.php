<?php
/**
 * Canonical artist to Events-site artist adapter.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/Core/ArtistMappingLock.php';

const EXTRACHILL_EVENTS_ARTIST_TERM_META = '_extrachill_events_artist_term_id';

add_action( 'wp_abilities_api_init', 'extrachill_events_register_events_by_artist_ability' );

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
					'readonly'   => false,
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

	$events_blog_id = (int) ec_get_blog_id( 'events' );
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
	$main_blog_id   = (int) ec_get_blog_id( 'main' );
	$events_blog_id = (int) ec_get_blog_id( 'events' );
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
			$mapped_term_id = extrachill_events_maybe_map_artist_term( $canonical, $events_blog_id );
			if ( is_wp_error( $mapped_term_id ) ) {
				return $mapped_term_id;
			}
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
 * Lazily map a bound canonical artist by an unambiguous, unclaimed exact slug.
 *
 * Must be called in main-site context. Existing mappings are never replaced.
 *
 * @param WP_Term $canonical Canonical artist term.
 * @param int     $events_blog_id Events site ID.
 * @return int|WP_Error Events term ID or a closed failure.
 */
function extrachill_events_maybe_map_artist_term( $canonical, int $events_blog_id ) {
	$missing      = new WP_Error( 'artist_mapping_missing', __( 'No Events artist mapping exists.', 'extrachill-events' ), array( 'status' => 404 ) );
	$canonical_id = (int) $canonical->term_id;
	if ( absint( get_term_meta( $canonical_id, '_artist_profile_id', true ) ) < 1 ) {
		return $missing;
	}

	$canonical_matches = extrachill_events_read_artist_slug_matches( (string) $canonical->slug );
	if ( is_wp_error( $canonical_matches ) ) {
		return $canonical_matches;
	}
	if ( count( $canonical_matches ) !== 1 ) {
		return $missing;
	}

	switch_to_blog( $events_blog_id );
	try {
		$local_matches = extrachill_events_read_artist_slug_matches( (string) $canonical->slug );
	} finally {
		restore_current_blog();
	}
	if ( is_wp_error( $local_matches ) ) {
		return $local_matches;
	}
	if ( count( $local_matches ) !== 1 ) {
		return $missing;
	}

	$local_id = (int) $local_matches[0]->term_id;
	$lock     = \ExtraChillEvents\Core\ArtistMappingLock::acquire( $local_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		$result = ( static function () use ( $canonical_id, $canonical, $events_blog_id, $local_id, $missing ) {
			// A concurrent request may have mapped or rebound this artist while we waited.
			if ( function_exists( 'wp_cache_delete' ) ) {
				wp_cache_delete( $canonical_id, 'term_meta' );
			}
			$existing = absint( get_term_meta( $canonical_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, true ) );
			if ( $existing > 0 ) {
				return $existing;
			}
			$matches = extrachill_events_read_artist_slug_matches( (string) $canonical->slug );
			if ( is_wp_error( $matches ) ) {
				return $matches;
			}
			// Re-read the binding after the cache flush: it may have been removed while we waited.
			$bound_profile = absint( get_term_meta( $canonical_id, '_artist_profile_id', true ) );
			// @phpstan-ignore smaller.alwaysFalse (PHPStan narrows the pre-lock read; this is a deliberate post-lock re-check.)
			if ( count( $matches ) !== 1 || (int) $matches[0]->term_id !== $canonical_id || $bound_profile < 1 ) {
				return $missing;
			}
			switch_to_blog( $events_blog_id );
			try {
				$matches = extrachill_events_read_artist_slug_matches( (string) $canonical->slug );
			} finally {
				restore_current_blog();
			}
			if ( is_wp_error( $matches ) ) {
				return $matches;
			}
			if ( count( $matches ) !== 1 || (int) $matches[0]->term_id !== $local_id ) {
				return $missing;
			}
			$claims = extrachill_events_read_artist_mapping_claims( $local_id );
			if ( is_wp_error( $claims ) ) {
				return $claims;
			}
			if ( array_diff( $claims, array( $canonical_id ) ) ) {
				return $missing;
			}
			$updated = update_term_meta( $canonical_id, EXTRACHILL_EVENTS_ARTIST_TERM_META, $local_id );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			return false === $updated
				? new WP_Error( 'artist_mapping_write_failed', __( 'The Events artist mapping could not be saved.', 'extrachill-events' ), array( 'status' => 503 ) )
				: $local_id;
		} )();
	} finally {
		$released = \ExtraChillEvents\Core\ArtistMappingLock::release( $lock );
	}
	return is_wp_error( $released ) ? $released : $result;
}

/**
 * Read at most two exact-slug artists, preserving database failures.
 *
 * @param string $slug Exact artist slug on the current site.
 * @return WP_Term[]|WP_Error Matching terms or a read error.
 */
function extrachill_events_read_artist_slug_matches( string $slug ) {
	global $wpdb;

	$wpdb->flush();
	$query = new WP_Term_Query();
	$terms = $query->query(
		array(
			'taxonomy'      => 'artist',
			'hide_empty'    => false,
			'slug'          => $slug,
			'number'        => 2,
			'cache_results' => false,
		)
	);
	if ( '' !== (string) $wpdb->last_error || ! is_array( $terms ) ) {
		return new WP_Error( 'artist_term_query_failed', __( 'Artist taxonomy data could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
	}
	$matches = array();
	foreach ( $terms as $term ) {
		if ( $term instanceof WP_Term && $slug === (string) $term->slug ) {
			$matches[] = $term;
		}
	}
	return $matches;
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

