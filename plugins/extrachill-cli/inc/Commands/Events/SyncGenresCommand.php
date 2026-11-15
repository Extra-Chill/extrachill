<?php
/**
 * Backfill the event genre projection across the event catalog.
 *
 * Command: `wp extrachill events sync-genres`.
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
 * the command is safely re-runnable.
 *
 * Dry-run by default; --apply commits. --apply additionally materializes any
 * missing events-site genre terms through the network's approved projection
 * (never by inserting directly), and aborts when the genre taxonomy or the
 * extrachill-network resolver is unavailable — with either missing, every
 * event would resolve to "no genre" and the run would be meaningless.
 *
 * Always invoke with `--url=events.extrachill.com`.
 *
 * Thin adapter over GenreProjectionBackfill (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\GenreProjectionBackfill;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backfills each event's genre projection from its artist terms.
 *
 * Dry-run by default; --apply commits. See the file docblock for the
 * resolution order and the set-based walk.
 */
class SyncGenresCommand {

	/**
	 * Backfill genre projections across the event catalog.
	 *
	 * Dry-run by default; pass --apply to commit writes.
	 *
	 * ## OPTIONS
	 *
	 * [--apply]
	 * : Commit term assignments (materializing missing approved genre terms).
	 * Default is dry-run.
	 *
	 * [--limit=<n>]
	 * : Process at most n events (after --offset). 0 means no limit.
	 *
	 * [--offset=<n>]
	 * : Skip the first n matching events before processing. Useful to resume
	 * a partial run; events skipped by the offset still count as unprocessed.
	 *
	 * [--artist=<term_id|slug>]
	 * : Restrict the run to one artist term (events-site term ID or slug) and
	 * its events.
	 *
	 * [--post-status=<status>]
	 * : Post status to backfill.
	 * ---
	 * default: publish
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill events sync-genres --url=events.extrachill.com
	 *     wp extrachill events sync-genres --url=events.extrachill.com --limit=500
	 *     wp extrachill events sync-genres --url=events.extrachill.com --artist=hip-hop-artist --apply
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );

		OwnerSiteAbility::require_service( 'events', 'Events', GenreProjectionBackfill::class );

		if ( ! extrachill_events_genre_sync_supported() ) {
			\WP_CLI::error(
				'The genre projection cannot run: the genre taxonomy (extrachill-network) or the network term resolver is missing on this site. Run with --url=events.extrachill.com after extrachill-network ships the genre module.'
			);
			return;
		}

		$apply       = ! empty( $assoc_args['apply'] );
		$limit       = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;
		$offset      = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$post_status = sanitize_key( (string) ( $assoc_args['post-status'] ?? 'publish' ) );

		if ( '' === $post_status || ! in_array( $post_status, get_post_stati(), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown post status "%s".', $post_status ) );
			return;
		}

		$backfill       = new GenreProjectionBackfill();
		$artist_term_id = 0;
		if ( ! empty( $assoc_args['artist'] ) ) {
			$artist_term_id = $backfill->resolve_artist_term( (string) $assoc_args['artist'] );
			if ( $artist_term_id <= 0 ) {
				\WP_CLI::error( sprintf( 'Artist term "%s" not found on this site.', (string) $assoc_args['artist'] ) );
				return;
			}
		}

		$total = $backfill->count_events( $post_status, $artist_term_id );

		\WP_CLI::log(
			sprintf(
				'%s genre sync — status=%s artist=%s limit=%s offset=%d — %d matching event(s).',
				$apply ? 'APPLY' : 'DRY RUN',
				$post_status,
				$artist_term_id > 0 ? (string) $artist_term_id : 'all',
				$limit > 0 ? (string) $limit : 'none',
				$offset,
				$total
			)
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning events', $total );
		$result   = $backfill->run(
			array(
				'apply'          => $apply,
				'limit'          => $limit,
				'offset'         => $offset,
				'post_status'    => $post_status,
				'artist_term_id' => $artist_term_id,
			),
			static function () use ( $progress ): void {
				$progress->tick(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements tick() at runtime.)
			}
		);
		$progress->finish(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements finish() at runtime.)

		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}

		self::print_report( $apply, $result['totals'], $result['missing_slugs'], $result['per_genre_counts'] );
	}

	/**
	 * Print the run report: outcome counts, missing terms, per-genre counts.
	 *
	 * @param bool               $apply            Whether this was an apply run.
	 * @param array<string,int>  $totals           Outcome counters.
	 * @param array<string,bool> $missing_slugs    Genre slugs with no events-site term.
	 * @param array<string,int>  $per_genre_counts Events per computed genre slug.
	 */
	private static function print_report( bool $apply, array $totals, array $missing_slugs, array $per_genre_counts ): void {
		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( '=== Genre sync report (%s) ===', $apply ? 'APPLY' : 'DRY RUN' ) );
		\WP_CLI::log(
			sprintf(
				'Scanned: %1$d | no artist: %2$d | no genre: %3$d | unchanged: %4$d | %5$s: %6$d | errors: %7$d',
				$totals['scanned'],
				$totals['no_artist'],
				$totals['no_genre'],
				$totals['unchanged'],
				$apply ? 'written' : 'would change',
				$apply ? $totals['written'] : $totals['would_change'],
				$totals['errors']
			)
		);

		if ( ! empty( $missing_slugs ) ) {
			\WP_CLI::log(
				sprintf(
					'%1$d genre assignment(s) skipped for %2$d term(s) not on this site: %3$s',
					$totals['missing_terms'],
					count( $missing_slugs ),
					implode( ', ', array_keys( $missing_slugs ) )
				)
			);
			if ( ! $apply ) {
				\WP_CLI::log( 'These terms would be materialized through the network projection on --apply.' );
			}
		}

		if ( empty( $per_genre_counts ) ) {
			\WP_CLI::log( 'No genre assignments computed.' );
			return;
		}

		ksort( $per_genre_counts );
		$genre_rows = array();
		foreach ( $per_genre_counts as $slug => $count ) {
			$genre_rows[] = array(
				'genre' => $slug,
				'count' => $count,
			);
		}
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Per-genre event counts (computed):' );
		\WP_CLI\Utils\format_items( 'table', $genre_rows, array( 'genre', 'count' ) );
	}
}
