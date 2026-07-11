<?php
/**
 * Analytics GSC Opportunities CLI Command
 *
 * Thin wrapper around the datamachine/gsc-opportunity ability — a search-demand
 * opportunity auditor that turns Google Search Console per-query/per-page stats
 * into a ranked content-fix worklist. Surfaces two free-win classes:
 *
 *   1. SNIPPET / CTR GAP — pages ranking well (good position) but with CTR far
 *      below the position-expected baseline → title/meta-description rewrite
 *      candidates (the rank is fine, the listing just isn't earning the click).
 *   2. PAGE-2 DEMAND — high-impression queries/pages stuck at position ~8-15 →
 *      ranking-push candidates with proven latent demand one rank off page 1.
 *
 * Both classes are ranked by estimated recoverable clicks (impressions x
 * ctr-gap). The position->expected-CTR baseline is a documented, deliberately
 * rough curve that lives in the ability — it surfaces outliers, not precise
 * CTR predictions. All GSC logic and transport live in Data Machine Business;
 * this command only shapes flags + windows for display.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GscOpportunitiesCommand {

	/**
	 * Find high-impression / wrong-CTR-for-position search opportunities.
	 *
	 * Pulls per-query and/or per-page Google Search Console stats over a window
	 * and flags two opportunity classes — snippet/CTR gap (good rank, broken
	 * CTR) and page-2 demand (high impressions at position 8-15) — each ranked
	 * by estimated recoverable clicks. The output is a data-driven worklist:
	 * which existing pages to rewrite the title/meta on for immediate click
	 * gains, and which page-2 pages to push.
	 *
	 * ## OPTIONS
	 *
	 * [--dimension=<dimension>]
	 * : Which GSC dimension(s) to audit.
	 * ---
	 * default: both
	 * options:
	 *   - query
	 *   - page
	 *   - both
	 * ---
	 *
	 * [--days=<days>]
	 * : Lookback window in days (window ends 3 days ago for finalized data).
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--start-date=<date>]
	 * : Explicit start date (YYYY-MM-DD). Overrides --days when both are given.
	 *
	 * [--end-date=<date>]
	 * : Explicit end date (YYYY-MM-DD). Defaults to 3 days ago.
	 *
	 * [--site-url=<url>]
	 * : GSC property URL (sc-domain: or https://). Defaults to the configured property.
	 *
	 * [--url-filter=<string>]
	 * : Restrict the audit to URLs containing this string.
	 *
	 * [--query-filter=<string>]
	 * : Restrict the audit to queries containing this string.
	 *
	 * [--min-impressions=<n>]
	 * : Minimum impressions for a row to qualify as an opportunity.
	 * ---
	 * default: 100
	 * ---
	 *
	 * [--good-position=<n>]
	 * : Snippet-gap rank cutoff — rows at or above this position with low CTR are listing problems.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--ctr-gap-factor=<n>]
	 * : Snippet-gap sensitivity — flag only when CTR is below this fraction of the position-expected CTR.
	 * ---
	 * default: 0.5
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max opportunities to display per class.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--class=<class>]
	 * : Which opportunity class to show in table output.
	 * ---
	 * default: both
	 * options:
	 *   - snippet
	 *   - page2
	 *   - both
	 * ---
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
	 *     wp extrachill analytics gsc-opportunities
	 *     wp extrachill analytics gsc-opportunities --dimension=query --class=snippet
	 *     wp extrachill analytics gsc-opportunities --days=90 --min-impressions=500
	 *     wp extrachill analytics gsc-opportunities --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'datamachine/gsc-opportunity' );

		if ( ! $ability ) {
			WP_CLI::error( 'datamachine/gsc-opportunity ability not found. Is Data Machine Business active and GSC configured?' );
		}

		$limit  = max( 1, (int) ( $assoc_args['limit'] ?? 25 ) );
		$class  = $assoc_args['class'] ?? 'both';
		$format = $assoc_args['format'] ?? 'table';

		$input = array(
			'dimension' => $assoc_args['dimension'] ?? 'both',
			'days'      => max( 1, (int) ( $assoc_args['days'] ?? 28 ) ),
			'limit'     => $limit,
		);

		$this->map_optional(
			$input,
			$assoc_args,
			array(
				'start-date'      => 'start_date',
				'end-date'        => 'end_date',
				'site-url'        => 'site_url',
				'url-filter'      => 'url_filter',
				'query-filter'    => 'query_filter',
				'min-impressions' => 'min_impressions',
				'good-position'   => 'good_position',
				'ctr-gap-factor'  => 'ctr_gap_factor',
			)
		);

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		if ( empty( $result['success'] ) ) {
			WP_CLI::error( $result['error'] ?? 'GSC opportunity audit failed.' );
		}

		$snippet = array_map( array( $this, 'snippet_row' ), (array) ( $result['snippet_gap'] ?? array() ) );
		$page2   = array_map( array( $this, 'page2_row' ), (array) ( $result['page2_demand'] ?? array() ) );

		$has_definition_box = false;
		foreach ( $snippet as $row ) {
			if ( ! empty( $row['definition_box'] ) ) {
				$has_definition_box = true;
				break;
			}
		}

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				array_merge(
					'page2' === $class ? array() : $snippet,
					'snippet' === $class ? array() : $page2
				),
				array( 'class', 'type', 'target', 'position', 'impressions', 'current_ctr', 'expected_ctr', 'recoverable_clicks', 'definition_box' )
			);
			return;
		}

		$window = $result['window'] ?? array();
		WP_CLI::log(
			sprintf(
				'GSC Opportunities — %s — %dd window (%s → %s)',
				$result['dimension'] ?? 'both',
				(int) ( $window['days'] ?? 28 ),
				$window['start_date'] ?? '',
				$window['end_date'] ?? ''
			)
		);
		$snippet_count        = (int) ( $result['snippet_gap_count'] ?? count( $snippet ) );
		$definition_box_count = (int) ( $result['definition_box_count'] ?? 0 );
		$page2_count          = (int) ( $result['page2_demand_count'] ?? count( $page2 ) );

		if ( $definition_box_count > 0 ) {
			WP_CLI::log(
				sprintf(
					'%d snippet/CTR-gap (%d SERP-captured) · %d page-2 demand (ranked by estimated recoverable clicks)',
					$snippet_count,
					$definition_box_count,
					$page2_count
				)
			);
		} else {
			WP_CLI::log(
				sprintf(
					'%d snippet/CTR-gap · %d page-2 demand (ranked by estimated recoverable clicks)',
					$snippet_count,
					$page2_count
				)
			);
		}
		WP_CLI::log( str_repeat( '─', 72 ) );

		if ( 'page2' !== $class ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'SNIPPET / CTR GAP — good rank, CTR far below the position baseline → rewrite the title/meta:' );
			if ( empty( $snippet ) ) {
				WP_CLI::log( '  (none in this window — widen --days or lower --min-impressions)' );
			} else {
				Utils\format_items(
					'table',
					$snippet,
					array( 'type', 'target', 'position', 'impressions', 'current_ctr', 'expected_ctr', 'recoverable_clicks' )
				);

				if ( $has_definition_box ) {
					WP_CLI::log( '  [SERP-captured] rows rank well but Google answers inline (definition box / AI Overview) — not a title/meta fix.' );
				}
			}
		}

		if ( 'snippet' !== $class ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'PAGE-2 DEMAND — high impressions stuck at position 8-15 → push onto page 1:' );
			if ( empty( $page2 ) ) {
				WP_CLI::log( '  (none in this window — widen --days or lower --min-impressions)' );
			} else {
				Utils\format_items(
					'table',
					$page2,
					array( 'type', 'target', 'position', 'impressions', 'current_ctr', 'target_position', 'target_ctr', 'recoverable_clicks' )
				);
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'Recoverable clicks are estimates from a deliberately-rough position→expected-CTR baseline — they' );
		WP_CLI::log( 'surface outliers (a #1 result at 1.7% CTR is broken), not precise click counts. Confirm with judgment.' );
	}

	/**
	 * Pull optional flags into the ability input under their canonical keys.
	 *
	 * @param array $input      Ability input (by reference).
	 * @param array $assoc_args CLI associative args.
	 * @param array $mapping    flag => input-key map.
	 * @return void
	 */
	private function map_optional( array &$input, array $assoc_args, array $mapping ) {
		foreach ( $mapping as $flag => $key ) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}
	}

	/**
	 * Shape one snippet/CTR-gap opportunity row for display.
	 *
	 * SERP-captured (definition-box) rows are flagged in the target column so
	 * they read visibly in the table instead of appearing as a silently-zeroed
	 * recoverable-clicks count. The raw `definition_box` flag is preserved on
	 * the shaped row for faithful json/csv output.
	 *
	 * @param array $o Opportunity row from the ability.
	 * @return array<string, mixed>
	 */
	private function snippet_row( array $o ) {
		$definition_box = (bool) ( $o['definition_box'] ?? false );
		$target         = $o['target'] ?? '';

		if ( $definition_box && '' !== $target ) {
			$target = '[SERP-captured] ' . $target;
		}

		return array(
			'class'              => 'snippet',
			'type'               => $o['type'] ?? '',
			'target'             => $target,
			'position'           => $this->num( $o['position'] ?? 0 ),
			'impressions'        => (int) ( $o['impressions'] ?? 0 ),
			'current_ctr'        => $this->pct( $o['current_ctr'] ?? 0 ),
			'expected_ctr'       => $this->pct( $o['expected_ctr'] ?? 0 ),
			'recoverable_clicks' => (int) ( $o['recoverable_clicks'] ?? 0 ),
			'definition_box'     => $definition_box,
		);
	}

	/**
	 * Shape one page-2 demand opportunity row for display.
	 *
	 * @param array $o Opportunity row from the ability.
	 * @return array<string, mixed>
	 */
	private function page2_row( array $o ) {
		return array(
			'class'              => 'page2',
			'type'               => $o['type'] ?? '',
			'target'             => $o['target'] ?? '',
			'position'           => $this->num( $o['position'] ?? 0 ),
			'impressions'        => (int) ( $o['impressions'] ?? 0 ),
			'current_ctr'        => $this->pct( $o['current_ctr'] ?? 0 ),
			'target_position'    => (int) ( $o['target_position'] ?? 0 ),
			'target_ctr'         => $this->pct( $o['target_ctr'] ?? 0 ),
			'recoverable_clicks' => (int) ( $o['recoverable_clicks'] ?? 0 ),
		);
	}

	/**
	 * Render a CTR fraction (0.017) as a percentage string ("1.7%").
	 *
	 * @param mixed $value CTR fraction.
	 * @return string
	 */
	private function pct( $value ) {
		return $this->num( (float) $value * 100 ) . '%';
	}

	/**
	 * Format a numeric value, rendering whole numbers without a trailing ".0".
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function num( $value ) {
		$float = (float) $value;

		if ( floor( $float ) === $float ) {
			return (string) (int) $float;
		}

		return (string) round( $float, 2 );
	}
}
