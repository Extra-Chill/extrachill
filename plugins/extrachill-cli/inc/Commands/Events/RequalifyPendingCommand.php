<?php
/**
 * `wp extrachill venues requalify-pending` — re-qualify URLs by filter.
 *
 * Walks the latest-verdict-per-URL set, filters by --platform / --verdict,
 * and re-runs the extrachill/qualify-venue ability against each match. Newly
 * QUALIFIED_STRUCTURED URLs are surfaced with a suggested follow-up command
 * for the operator. Does NOT auto-promote venues; the operator decides.
 *
 * Use case: ship a BandzoogleExtractor → `requalify-pending
 * --platform=bandzoogle` → list of venues now qualifying that were blocked.
 *
 * Thin adapter over VenueRequalifier (extrachill-events).
 *
 * @package ExtraChill\CLI\Commands\Events
 */

namespace ExtraChill\CLI\Commands\Events;

use ExtraChill\CLI\OwnerSiteAbility;
use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;
use ExtraChillEvents\Core\VenueRequalifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RequalifyPendingCommand {

	/**
	 * Re-qualify URLs whose latest verdict matches the filter.
	 *
	 * Use this after shipping a new/improved extractor to automatically
	 * promote venues that the prior fingerprint flagged as fixable.
	 *
	 * ## OPTIONS
	 *
	 * [--platform=<platform>]
	 * : Only requalify URLs whose latest fingerprint detected this platform
	 * (e.g. bandzoogle, squarespace, webflow).
	 *
	 * [--verdict=<verdict>]
	 * : Only requalify URLs whose latest verdict equals this taxonomy value.
	 * Defaults to extraction_gap (the verdict most likely to flip when
	 * an extractor lands).
	 * ---
	 * default: extraction_gap
	 * ---
	 *
	 * [--limit=<n>]
	 * : Maximum URLs to requalify.
	 * ---
	 * default: 50
	 * ---
	 *
	 * [--dry-run]
	 * : List matching URLs but do not actually re-run qualify.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues requalify-pending --platform=bandzoogle
	 *     wp extrachill venues requalify-pending --verdict=bot_blocked --limit=10
	 *     wp extrachill venues requalify-pending --platform=squarespace --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		unset( $args );
		OwnerSiteAbility::require_service( 'events', 'Events', VenueRequalifier::class );

		if ( ! QualifyVerdictsTable::table_exists() ) {
			\WP_CLI::error( 'Qualify verdicts table does not exist on this site. Deactivate/reactivate extrachill-events to install it.' );
			return;
		}

		$platform = (string) ( $assoc_args['platform'] ?? '' );
		$verdict  = (string) ( $assoc_args['verdict'] ?? QualifyVerdict::EXTRACTION_GAP );
		$limit    = max( 1, (int) ( $assoc_args['limit'] ?? 50 ) );
		$dry_run  = ! empty( $assoc_args['dry-run'] );
		$format   = $assoc_args['format'] ?? 'table';

		if ( '' !== $verdict && ! in_array( $verdict, QualifyVerdict::all(), true ) ) {
			\WP_CLI::error( sprintf( 'Unknown verdict: %s. Valid: %s', $verdict, implode( ', ', QualifyVerdict::all() ) ) );
			return;
		}

		$requalifier = new VenueRequalifier();
		$candidates  = $requalifier->pending_candidates( $verdict, $platform, $limit );

		if ( empty( $candidates ) ) {
			\WP_CLI::log( 'No URLs match the requested filter.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Found %d URL(s) to requalify (verdict=%s%s).', count( $candidates ), $verdict, $platform ? ", platform={$platform}" : '' ) );

		if ( $dry_run ) {
			$rows = array();
			foreach ( $candidates as $c ) {
				$rows[] = array(
					'url'          => $c['url'],
					'last_verdict' => $c['verdict'],
					'platforms'    => implode( ',', $c['platforms'] ),
					'qualified_at' => $c['qualified_at'],
				);
			}
			\WP_CLI\Utils\format_items( $format, $rows, array( 'url', 'last_verdict', 'platforms', 'qualified_at' ) );
			\WP_CLI::log( '' );
			\WP_CLI::log( 'Run without --dry-run to actually re-qualify.' );
			return;
		}

		$progress = \WP_CLI\Utils\make_progress_bar( 'Requalifying', count( $candidates ) );
		$outcome  = $requalifier->requalify_pending(
			$candidates,
			static function () use ( $progress ): void {
				$progress->tick(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements tick() at runtime.)
			}
		);
		if ( is_wp_error( $outcome ) ) {
			\WP_CLI::error( $outcome->get_error_message() );
			return;
		}
		$progress->finish(); // @phpstan-ignore method.notFound (make_progress_bar() returns cli\progress\Bar|WP_CLI\NoOp; NoOp forwards via __call() and Bar implements finish() at runtime.)

		// Surface the promotions (verdict flipped to QUALIFIED_STRUCTURED) up
		// top so the operator sees what is now actionable.
		$results    = $outcome['results'];
		$promotions = $outcome['promotions'];

		if ( 'json' === $format ) {
			\WP_CLI::log(
				(string) wp_json_encode(
					array(
						'total'      => count( $results ),
						'promotions' => $promotions,
						'results'    => $results,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		\WP_CLI::log( '' );
		if ( ! empty( $promotions ) ) {
			\WP_CLI::success( sprintf( '%d venue(s) newly qualify as STRUCTURED. Recommend wiring them:', count( $promotions ) ) );
			foreach ( $promotions as $p ) {
				\WP_CLI::log( sprintf( '  ✓ %s (%d events) — wp extrachill venues add --events-url="%s" --pipeline=<id>', $p['url'], $p['event_count'], $p['url'] ) );
			}
			\WP_CLI::log( '' );
		}

		\WP_CLI\Utils\format_items( $format, $results, array( 'url', 'old_verdict', 'new_verdict', 'event_count', 'flipped' ) );
	}
}
