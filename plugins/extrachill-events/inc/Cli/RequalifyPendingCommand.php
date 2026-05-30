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
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;

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
	 * @param array $args       Positional args.
	 * @param array $assoc_args Named args.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

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

		$candidates = $this->fetch_candidates( $verdict, $platform, $limit );

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

		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/qualify-venue' ) : null;
		if ( ! $ability ) {
			\WP_CLI::error( 'extrachill/qualify-venue ability not available.' );
			return;
		}

		$results  = array();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Requalifying', count( $candidates ) );
		foreach ( $candidates as $c ) {
			$result = $ability->execute( array( 'url' => $c['url'] ) );
			$progress->tick();

			if ( is_wp_error( $result ) ) {
				$results[] = array(
					'url'            => $c['url'],
					'old_verdict'    => $c['verdict'],
					'new_verdict'    => 'error',
					'agent_guidance' => '',
					'event_count'    => 0,
					'flipped'        => 'no',
				);
				continue;
			}

			$new_verdict = (string) ( $result['verdict'] ?? '' );
			$flipped     = ( $new_verdict !== $c['verdict'] ) ? 'YES' : 'no';

			$results[] = array(
				'url'            => $c['url'],
				'old_verdict'    => $c['verdict'],
				'new_verdict'    => $new_verdict,
				'agent_guidance' => (string) ( $result['agent_guidance'] ?? '' ),
				'event_count'    => (int) ( $result['event_count'] ?? 0 ),
				'flipped'        => $flipped,
			);
		}
		$progress->finish();

		// Surface the promotions (verdict flipped to QUALIFIED_STRUCTURED) up
		// top so the operator sees what is now actionable.
		$promotions = array_values(
			array_filter(
				$results,
				static function ( $r ) {
					return QualifyVerdict::QUALIFIED_STRUCTURED === $r['new_verdict'];
				}
			)
		);

		if ( 'json' === $format ) {
			\WP_CLI::log(
				wp_json_encode(
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

	/**
	 * Fetch latest-verdict-per-URL rows matching the filter.
	 *
	 * @param string $verdict  Verdict to filter on, or '' for any.
	 * @param string $platform Platform slug to require in fingerprint, or '' for any.
	 * @param int    $limit    Max rows.
	 * @return array<int,array{url:string,verdict:string,platforms:array<int,string>,qualified_at:string}>
	 */
	private function fetch_candidates( string $verdict, string $platform, int $limit ): array {
		global $wpdb;
		$table = QualifyVerdictsTable::table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.url, v.verdict, v.fingerprint, v.qualified_at
				FROM {$table} v
				INNER JOIN (
					SELECT url_hash, MAX(id) AS max_id
					FROM {$table}
					GROUP BY url_hash
				) latest ON latest.max_id = v.id
				WHERE v.verdict = %s
				ORDER BY v.qualified_at DESC
				LIMIT %d",
				$verdict,
				// Over-fetch when platform filter is set so the post-filter
				// still hits --limit. Cap at a sane upper bound.
				'' === $platform ? $limit : min( $limit * 10, 1000 )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$decoded   = json_decode( (string) ( $row['fingerprint'] ?? '' ), true );
			$platforms = is_array( $decoded ) && isset( $decoded['platforms_detected'] ) && is_array( $decoded['platforms_detected'] )
				? array_values( array_map( 'strval', $decoded['platforms_detected'] ) )
				: array();

			if ( '' !== $platform && ! in_array( $platform, $platforms, true ) ) {
				continue;
			}

			$out[] = array(
				'url'          => (string) $row['url'],
				'verdict'      => (string) $row['verdict'],
				'platforms'    => $platforms,
				'qualified_at' => (string) $row['qualified_at'],
			);

			if ( count( $out ) >= $limit ) {
				break;
			}
		}

		return $out;
	}
}
