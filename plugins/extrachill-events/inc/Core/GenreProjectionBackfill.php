<?php
/**
 * Backfill the event genre projection across the event catalog.
 *
 * Materializes the derived genre projection (Extra-Chill/extrachill-events#828):
 * every event's genres are the union of its artist terms' genres, capped at
 * GenreSync::EVENT_GENRE_CAP, written with replace semantics. The computation
 * reuses the runtime path ({@see extrachill_events_resolve_artist_genres()}
 * and {@see extrachill_events_sync_event_genres()}) so the backfill and the
 * live triggers cannot drift.
 *
 * Set-based: the artist → genres map is resolved once per artist (memoized in
 * the resolver's per-request cache) and events are walked in 500-row batches.
 * Unchanged events are detected by comparing sorted slug sets and skipped, so
 * the backfill is safely re-runnable.
 *
 * Apply mode additionally materializes any missing events-site genre terms
 * through the network's approved projection (never by inserting directly).
 * Consumed by the `wp extrachill events sync-genres` adapter in extrachill-cli.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Backfills each event's genre projection from its artist terms. */
class GenreProjectionBackfill {

	/** Post type the genre projection is materialized on. */
	public const POST_TYPE = 'data_machine_events';

	/** Number of rows fetched per database batch. */
	private const BATCH_SIZE = 500;

	/**
	 * Walk the catalog and plan (or with $apply, write) genre projections.
	 *
	 * @param array{apply?:bool,limit?:int,offset?:int,post_status?:string,artist_term_id?:int} $options Run scope.
	 * @param callable|null $tick Called once per scanned event (progress).
	 * @return array{totals:array<string,int>,missing_slugs:array<string,bool>,per_genre_counts:array<string,int>,warnings:string[]}
	 */
	public function run( array $options, ?callable $tick = null ): array {
		$apply          = ! empty( $options['apply'] );
		$limit          = max( 0, (int) ( $options['limit'] ?? 0 ) );
		$offset         = max( 0, (int) ( $options['offset'] ?? 0 ) );
		$post_status    = (string) ( $options['post_status'] ?? 'publish' );
		$artist_term_id = (int) ( $options['artist_term_id'] ?? 0 );

		$totals           = array(
			'scanned'       => 0,
			'no_artist'     => 0,
			'no_genre'      => 0,
			'unchanged'     => 0,
			'would_change'  => 0,
			'written'       => 0,
			'errors'        => 0,
			'missing_terms' => 0,
		);
		$missing_slugs    = array();
		$per_genre_counts = array();
		$warnings         = array();

		global $wpdb;
		$last_id = 0;
		$skipped = 0;

		while ( true ) {
			$rows = $this->fetch_event_ids( $wpdb, $post_status, $artist_term_id, $last_id );
			if ( empty( $rows ) ) {
				break;
			}

			foreach ( $rows as $post_id ) {
				$post_id = (int) $post_id;
				$last_id = $post_id;

				if ( $offset > 0 && $skipped < $offset ) {
					++$skipped;
					continue;
				}

				if ( $limit > 0 && $totals['scanned'] >= $limit ) {
					break 2;
				}

				++$totals['scanned'];
				if ( null !== $tick ) {
					$tick();
				}

				$plan = $this->plan_event( $post_id, $apply );
				if ( null === $plan ) {
					++$totals['errors'];
					continue;
				}

				foreach ( $plan['missing'] as $missing_slug ) {
					$missing_slugs[ $missing_slug ] = true;
				}
				$totals['missing_terms'] += count( $plan['missing'] );

				foreach ( $plan['slugs'] as $slug ) {
					$per_genre_counts[ $slug ] = ( $per_genre_counts[ $slug ] ?? 0 ) + 1;
				}

				if ( empty( $plan['artist_ids'] ) ) {
					++$totals['no_artist'];
				} elseif ( empty( $plan['slugs'] ) ) {
					++$totals['no_genre'];
				}

				if ( $plan['changed'] ) {
					if ( $apply ) {
						$result = wp_set_object_terms( $post_id, $plan['term_ids'], 'genre', false );
						if ( is_wp_error( $result ) ) {
							++$totals['errors'];
							$warnings[] = sprintf( 'Post %d: failed to set genre terms: %s', $post_id, $result->get_error_message() );
							continue;
						}
						++$totals['written'];
					} else {
						++$totals['would_change'];
					}
				} else {
					++$totals['unchanged'];
				}
			}

			if ( count( $rows ) < self::BATCH_SIZE ) {
				break;
			}
		}

		if ( $apply ) {
			// One flush after the run instead of per-row cache churn, plus the
			// full-page cache purge (term assignment is not a purge trigger).
			wp_cache_flush();
			do_action( 'extrachill_cache_flush' );
		}

		return array(
			'totals'           => $totals,
			'missing_slugs'    => $missing_slugs,
			'per_genre_counts' => $per_genre_counts,
			'warnings'         => $warnings,
		);
	}

	/**
	 * Compute the genre projection plan for one event.
	 *
	 * Shares the runtime resolution and term-materialization path so the
	 * backfill and the live triggers stay in lockstep.
	 *
	 * @param int  $post_id Event post ID.
	 * @param bool $apply   Whether missing approved genre terms may be materialized.
	 * @return array{artist_ids:int[],slugs:string[],term_ids:int[],missing:string[],changed:bool}|null Null on a read error.
	 */
	private function plan_event( int $post_id, bool $apply ): ?array {
		$artist_term_ids = wp_get_object_terms( $post_id, 'artist', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $artist_term_ids ) ) {
			return null;
		}
		$artist_term_ids = array_map( 'intval', (array) $artist_term_ids );

		$artist_genres = extrachill_events_resolve_artist_genres( $artist_term_ids );
		$slugs         = GenreSync::union_genres( $artist_genres, GenreSync::EVENT_GENRE_CAP );

		$terms          = extrachill_events_genre_term_ids_for_slugs( $slugs, $apply );
		$assigned_slugs = array();
		foreach ( $terms['ids'] as $term_id ) {
			$term = get_term( $term_id, 'genre' );
			if ( $term instanceof \WP_Term ) {
				$assigned_slugs[] = $term->slug;
			}
		}

		$current = wp_get_object_terms( $post_id, 'genre', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $current ) ) {
			return null;
		}
		$current = (array) $current;

		sort( $assigned_slugs );
		sort( $current );

		return array(
			'artist_ids' => $artist_term_ids,
			'slugs'      => $slugs,
			'term_ids'   => $terms['ids'],
			'missing'    => $terms['missing'],
			'changed'    => $assigned_slugs !== $current,
		);
	}

	/**
	 * Resolve an `--artist` argument to an events-site artist term ID.
	 *
	 * @param string $artist Term ID or slug.
	 * @return int 0 when unresolvable.
	 */
	public function resolve_artist_term( string $artist ): int {
		$artist = trim( $artist );
		if ( '' === $artist ) {
			return 0;
		}

		if ( ctype_digit( $artist ) ) {
			$term = get_term( (int) $artist, 'artist' );
			return $term instanceof \WP_Term ? (int) $term->term_id : 0;
		}

		$term = get_term_by( 'slug', sanitize_title( $artist ), 'artist' );
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Fetch one batch of event IDs for the walk.
	 *
	 * @param \wpdb  $wpdb           Database handle.
	 * @param string $post_status    Post status.
	 * @param int    $artist_term_id Restrict to this artist term (0 = all events).
	 * @param int    $last_id        Walk cursor.
	 * @return array<int,string>
	 */
	private function fetch_event_ids( \wpdb $wpdb, string $post_status, int $artist_term_id, int $last_id ): array {
		if ( $artist_term_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk catalog walk; per-row WP_Query would be orders of magnitude slower and the backfill runs once.
			return (array) $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted core table properties; every variable value goes through %s/%d placeholders.
					'SELECT p.ID FROM ' . $wpdb->posts . ' AS p' // @phpstan-ignore argument.type (Table names are trusted $wpdb core properties; every variable value is placeholder-bound.)
					. ' INNER JOIN ' . $wpdb->term_relationships . ' AS tr ON tr.object_id = p.ID'
					. ' INNER JOIN ' . $wpdb->term_taxonomy . ' AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id'
					. ' WHERE tt.term_id = %d AND tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s AND p.ID > %d'
					. ' ORDER BY p.ID ASC LIMIT %d',
					$artist_term_id,
					'artist',
					self::POST_TYPE,
					$post_status,
					$last_id,
					self::BATCH_SIZE
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk catalog walk; per-row WP_Query would be orders of magnitude slower and the backfill runs once.
		return (array) $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a trusted core table property; every variable value goes through %s/%d placeholders.
				'SELECT ID FROM ' . $wpdb->posts . ' WHERE post_type = %s AND post_status = %s AND ID > %d ORDER BY ID ASC LIMIT %d', // @phpstan-ignore argument.type ($wpdb->posts is a trusted core table property; every variable value is placeholder-bound.)
				self::POST_TYPE,
				$post_status,
				$last_id,
				self::BATCH_SIZE
			)
		);
	}

	/**
	 * Count events matching the run scope.
	 *
	 * @param string $post_status    Post status.
	 * @param int    $artist_term_id Restrict to this artist term (0 = all events).
	 * @return int
	 */
	public function count_events( string $post_status, int $artist_term_id ): int {
		global $wpdb;

		if ( $artist_term_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot count used for operator progress reporting.
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted core table properties; every variable value goes through %s/%d placeholders.
					'SELECT COUNT(p.ID) FROM ' . $wpdb->posts . ' AS p'
					. ' INNER JOIN ' . $wpdb->term_relationships . ' AS tr ON tr.object_id = p.ID'
					. ' INNER JOIN ' . $wpdb->term_taxonomy . ' AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id'
					. ' WHERE tt.term_id = %d AND tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s',
					$artist_term_id,
					'artist',
					self::POST_TYPE,
					$post_status
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-shot count used for operator progress reporting.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $wpdb->posts is a trusted core table property; every variable value goes through %s/%d placeholders.
				'SELECT COUNT(ID) FROM ' . $wpdb->posts . ' WHERE post_type = %s AND post_status = %s',
				self::POST_TYPE,
				$post_status
			)
		);
	}
}
