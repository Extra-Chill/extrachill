<?php
/**
 * Analytics Crosslink Targets CLI Command
 *
 * Thin formatter over the extrachill/get-crosslink-targets ability from the
 * extrachill-analytics plugin. That ability owns all the logic — it JOINs the
 * get-conversion-map per-article journey ranking with the Data Machine internal
 * link graph to produce a ranked, dry-run crosslink ops-pass targeting list:
 * blog-1 articles that returning visitors re-enter AND that are orphaned /
 * low-inbound. Each result is a target page that needs contextual inbound links
 * TO it. This command only shapes that result for the terminal.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrosslinkTargetsCommand {

	/**
	 * Show the ranked crosslink ops-pass targeting list.
	 *
	 * JOINs the conversion-map per-article ranking with the Data Machine link
	 * graph: blog-1 editorial articles that returning visitors re-enter AND that
	 * are orphaned / low-inbound (orphan = zero INBOUND links, i.e. nothing links
	 * TO the post — NOT the zero-OUTBOUND "posts_without_links" count from
	 * `wp datamachine links diagnose`). Each result is a target page that needs
	 * contextual inbound links TO it. This is a DRY-RUN list — it inserts no
	 * links; it is the targeting pass the crosslink hook consumes. An empty or
	 * short list IS the finding (no returning article journeys in window, or the
	 * catalog is already well-linked), not a bug.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Look-back window (days) for the conversion-map funnel.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--session-gap-mins=<mins>]
	 * : Inactivity gap (minutes) that ends a session. GA-standard is 30.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--scan-articles=<n>]
	 * : How many top entry articles to pull from the conversion map before the
	 * link-graph join.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--limit=<n>]
	 * : Maximum ranked crosslink targets to return after the join.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--max-inbound=<n>]
	 * : Inbound-link ceiling for a page to count as a target. 0 = orphans only.
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--min-returned=<n>]
	 * : Minimum returned (2nd-session) journeys for an article to qualify.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--force-audit]
	 * : Force a fresh link-graph audit instead of using the cached graph.
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
	 *     wp extrachill analytics crosslink-targets
	 *     wp extrachill analytics crosslink-targets --max-inbound=0 --limit=40
	 *     wp extrachill analytics crosslink-targets --days=90 --min-returned=5
	 *     wp extrachill analytics crosslink-targets --force-audit --format=json
	 *
	 * ## NOTES
	 *
	 * This is a network read and takes NO acting-user context. Do not pass the
	 * global `--user` flag — it is unused here and can emit a noisy "Ambiguous
	 * user match detected" warning on some installs. Omit `--user` entirely.
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-crosslink-targets' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-crosslink-targets ability not found. Is extrachill-analytics active?' );
		}

		$days             = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$session_gap_mins = max( 1, (int) ( $assoc_args['session-gap-mins'] ?? 30 ) );
		$scan_articles    = max( 1, (int) ( $assoc_args['scan-articles'] ?? 100 ) );
		$limit            = max( 1, (int) ( $assoc_args['limit'] ?? 25 ) );
		$max_inbound      = max( 0, (int) ( $assoc_args['max-inbound'] ?? 2 ) );
		$min_returned     = max( 0, (int) ( $assoc_args['min-returned'] ?? 1 ) );
		$force_audit      = isset( $assoc_args['force-audit'] );
		$format           = $assoc_args['format'] ?? 'table';

		$result = $ability->execute(
			array(
				'days'             => $days,
				'session_gap_mins' => $session_gap_mins,
				'scan_articles'    => $scan_articles,
				'limit'            => $limit,
				'max_inbound'      => $max_inbound,
				'min_returned'     => $min_returned,
				'force_audit'      => $force_audit,
			)
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$targets = (array) ( $result['targets'] ?? array() );

		if ( 'table' !== $format ) {
			$rows    = array_map( array( $this, 'target_row' ), $targets );
			$columns = array( 'post_id', 'title', 'category', 'returned', 'inbound_links', 'orphan', 'score' );
			Utils\format_items( $format, $rows, $columns );
			return;
		}

		$period_label = "Last {$days} days";
		WP_CLI::log( sprintf( 'Crosslink Targets — %s (%s)', $period_label, $result['period'] ?? '' ) );

		$graph = (array) ( $result['link_graph'] ?? array() );
		// "orphaned" here = zero-INBOUND (nothing links TO the post), per the link
		// graph. This is NOT the zero-OUTBOUND "posts_without_links" figure from
		// `wp datamachine links diagnose` — the two count opposite edges of the
		// link graph and are not comparable. Label it explicitly so the two
		// numbers are never conflated as movement.
		$inbound_orphans = (int) ( $graph['inbound_orphan_count'] ?? $graph['orphan_count'] ?? 0 );
		WP_CLI::log(
			sprintf(
				'Link graph: %s — %s posts scanned, %s zero-inbound orphans (nothing links to them; NOT the zero-outbound count from `wp datamachine links diagnose`).',
				! empty( $graph['available'] ) ? 'available' : 'UNAVAILABLE',
				number_format( (int) ( $graph['total_scanned'] ?? 0 ) ),
				number_format( $inbound_orphans )
			)
		);
		WP_CLI::log(
			sprintf(
				'Join filter: returned >= %d AND inbound <= %d. Scanned %s articles, %s targets.',
				$min_returned,
				$max_inbound,
				number_format( (int) ( $result['scanned'] ?? 0 ) ),
				number_format( count( $targets ) )
			)
		);
		WP_CLI::log( str_repeat( '─', 72 ) );

		if ( empty( $targets ) ) {
			WP_CLI::log( '  (no crosslink targets in window — no returning article journeys, or the catalog is already well-linked)' );
			if ( ! empty( $result['note'] ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( $result['note'] );
			}
			return;
		}

		$rows = array_map( array( $this, 'target_row' ), $targets );
		Utils\format_items(
			'table',
			$rows,
			array( 'post_id', 'title', 'category', 'returned', 'inbound_links', 'orphan', 'score' )
		);

		if ( ! empty( $result['note'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( $result['note'] );
		}
	}

	/**
	 * Build a display row for a crosslink target.
	 *
	 * @param array $t Target result row.
	 * @return array<string, mixed>
	 */
	private function target_row( $t ) {
		return array(
			'post_id'       => (int) ( $t['post_id'] ?? 0 ),
			'title'         => $this->truncate( (string) ( $t['title'] ?? '(unknown)' ), 50 ),
			'category'      => $this->truncate( (string) ( $t['category'] ?? '' ), 22 ),
			'returned'      => number_format( (int) ( $t['returned'] ?? 0 ) ),
			'inbound_links' => (int) ( $t['inbound_links'] ?? 0 ),
			'orphan'        => ! empty( $t['orphan'] ) ? 'yes' : 'no',
			'score'         => $t['score'] ?? 0,
		);
	}

	/**
	 * Truncate a string for table display.
	 *
	 * @param string $text Text.
	 * @param int    $len  Max length.
	 * @return string
	 */
	private function truncate( $text, $len ) {
		if ( strlen( $text ) <= $len ) {
			return $text;
		}
		return substr( $text, 0, $len - 1 ) . '…';
	}
}
