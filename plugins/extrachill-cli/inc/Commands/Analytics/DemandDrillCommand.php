<?php
/**
 * Analytics Demand Drill CLI Command
 *
 * Thin wrapper around the extrachill/get-demand-drill ability — the
 * demand-decline ATTRIBUTION instrument. Where `wp extrachill analytics growth`
 * reports a single aggregate demand slope per surface (e.g. events organic
 * demand −29.65%/wk), this command drills that slope down to the specific pages
 * and queries dragging it: it ranks per-page and per-query contributors by NET
 * CLICK CHANGE (current-window clicks minus prior equal-window clicks) from
 * Google Search Console, showing both top decliners and top risers with each
 * row's CURRENT and PRIOR average position so a rank-loss is visible.
 *
 * That current/prior position pair is the whole point: a decliner whose
 * position rose (worsened) lost rank — a rank-recovery / gsc-opportunities
 * lever; a decliner whose position held but clicks fell lost query demand — a
 * content / crosslink lever. All GSC transport and the join/rank logic live in
 * the ability (Data Machine Business owns the GSC primitive it reuses); this
 * command only shapes rows and windows for display.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DemandDrillCommand {

	/**
	 * Attribute a surface's declining demand slope to per-page/per-query click changes.
	 *
	 * Pulls Google Search Console per-page and per-query stats for the current
	 * window and the immediately-preceding equal window, joins them, and prints
	 * the top decliners (pages/queries that lost the most clicks) and risers
	 * (that gained the most), each ranked by net click change and annotated with
	 * its current and prior average position so a rank-loss is obvious. Turns a
	 * declining surface slope from a number into a page-level action list.
	 *
	 * ## OPTIONS
	 *
	 * [--surface=<surface>]
	 * : Surface to drill. Resolves to the surface host and scopes the GSC pull to it.
	 * ---
	 * options:
	 *   - events
	 *   - wire
	 *   - community
	 *   - artist
	 *   - blog
	 * ---
	 *
	 * [--host=<host>]
	 * : Explicit host to scope the drill to (e.g. events.extrachill.com). Overrides --surface.
	 *
	 * [--weeks=<weeks>]
	 * : Number of weeks the current window spans. The prior window is the immediately-preceding window of equal length.
	 * ---
	 * default: 4
	 * ---
	 *
	 * [--dimension=<dimension>]
	 * : Which contributor dimension(s) to show.
	 * ---
	 * default: both
	 * options:
	 *   - page
	 *   - query
	 *   - both
	 * ---
	 *
	 * [--limit=<n>]
	 * : Max decliners and max risers to show per dimension.
	 * ---
	 * default: 25
	 * ---
	 *
	 * [--min-clicks=<n>]
	 * : A page/query must have at least this many clicks in either window to qualify.
	 * ---
	 * default: 1
	 * ---
	 *
	 * [--show=<show>]
	 * : Which side of the change to show in table output.
	 * ---
	 * default: both
	 * options:
	 *   - decliners
	 *   - risers
	 *   - both
	 * ---
	 *
	 * [--site-url=<url>]
	 * : GSC property URL (sc-domain: or https://). Defaults to the configured property.
	 *
	 * [--query-filter=<string>]
	 * : Restrict the drill to queries containing this string.
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
	 *     wp extrachill analytics demand-drill --surface=events
	 *     wp extrachill analytics demand-drill --surface=events --weeks=8 --show=decliners
	 *     wp extrachill analytics demand-drill --host=wire.extrachill.com --dimension=query
	 *     wp extrachill analytics demand-drill --surface=events --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-demand-drill' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-demand-drill ability not found. Is extrachill-analytics active?' );
		}

		$weeks     = max( 1, (int) ( $assoc_args['weeks'] ?? 4 ) );
		$dimension = $assoc_args['dimension'] ?? 'both';
		$show      = $assoc_args['show'] ?? 'both';
		$format    = $assoc_args['format'] ?? 'table';

		$input = array(
			'weeks'      => $weeks,
			'dimension'  => $dimension,
			'limit'      => max( 1, (int) ( $assoc_args['limit'] ?? 25 ) ),
			'min_clicks' => max( 0, (int) ( $assoc_args['min-clicks'] ?? 1 ) ),
		);

		foreach (
			array(
				'surface'      => 'surface',
				'host'         => 'host',
				'site-url'     => 'site_url',
				'query-filter' => 'query_filter',
			) as $flag => $key
		) {
			if ( isset( $assoc_args[ $flag ] ) ) {
				$input[ $key ] = $assoc_args[ $flag ];
			}
		}

		$result = $ability->execute( $input );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = $this->all_rows( $result, $dimension, $show );

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				$rows,
				array( 'dimension', 'change', 'target', 'net_click_change', 'clicks_prior', 'clicks_current', 'position_prior', 'position_current', 'position_change' )
			);
			return;
		}

		$this->print_table( $result, $dimension, $show, $weeks );
	}

	/**
	 * Render the human-readable table output.
	 *
	 * @param array  $result    Ability result.
	 * @param string $dimension Requested dimension (page|query|both).
	 * @param string $show      Which side to show (decliners|risers|both).
	 * @param int    $weeks     Window length in weeks.
	 * @return void
	 */
	private function print_table( array $result, $dimension, $show, $weeks ) {
		$cur   = $result['current_window'] ?? array();
		$prior = $result['prior_window'] ?? array();

		$scope = $result['label'] ?? ( $result['surface'] ?? ( $result['host'] ?? 'whole property' ) );

		WP_CLI::log(
			sprintf(
				'Demand Drill — %s — %d-week windows (current %s→%s vs prior %s→%s)',
				$scope,
				(int) ( $result['weeks'] ?? $weeks ),
				$cur['start'] ?? '',
				$cur['end'] ?? '',
				$prior['start'] ?? '',
				$prior['end'] ?? ''
			)
		);
		WP_CLI::log( 'GSC: ' . ( ! empty( $result['gsc_available'] ) ? 'available' : 'NOT available (demand not instrumented)' ) );
		if ( empty( $result['host_resolved'] ) ) {
			WP_CLI::log( 'Scope: whole GSC property (no surface/host resolved — pass --surface or --host to scope).' );
		}
		WP_CLI::log( str_repeat( '─', 72 ) );

		foreach ( array( 'page', 'query' ) as $dim ) {
			if ( 'both' !== $dimension && $dimension !== $dim ) {
				continue;
			}

			$block = $result[ $dim ] ?? null;
			WP_CLI::log( '' );
			WP_CLI::log( strtoupper( $dim ) . ' CONTRIBUTORS:' );

			if ( ! is_array( $block ) || empty( $block['measured'] ) ) {
				$reason = is_array( $block ) && ! empty( $block['reason'] ) ? $block['reason'] : 'not instrumented';
				WP_CLI::log( '  not instrumented — ' . $reason );
				continue;
			}

			WP_CLI::log(
				sprintf(
					'  %d contributors · net click change across window: %s · %d decliners / %d risers',
					(int) ( $block['contributors'] ?? 0 ),
					$this->signed( (int) ( $block['net_click_total'] ?? 0 ) ),
					(int) ( $block['decliner_count'] ?? 0 ),
					(int) ( $block['riser_count'] ?? 0 )
				)
			);

			if ( 'risers' !== $show ) {
				WP_CLI::log( '' );
				WP_CLI::log( '  Top decliners (lost the most clicks):' );
				$this->print_rows( (array) ( $block['decliners'] ?? array() ) );
			}

			if ( 'decliners' !== $show ) {
				WP_CLI::log( '' );
				WP_CLI::log( '  Top risers (gained the most clicks):' );
				$this->print_rows( (array) ( $block['risers'] ?? array() ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'position_change > 0 means the average position WORSENED (dropped lower on the SERP). A decliner that' );
		WP_CLI::log( 'lost rank → rank-recovery / gsc-opportunities lever; one that held rank but lost clicks → content/crosslink lever.' );
	}

	/**
	 * Print a set of contributor rows as a table, or an empty marker.
	 *
	 * @param array $rows Contributor rows from the ability.
	 * @return void
	 */
	private function print_rows( array $rows ) {
		if ( empty( $rows ) ) {
			WP_CLI::log( '    (none — widen --weeks or lower --min-clicks)' );
			return;
		}

		Utils\format_items(
			'table',
			array_map( array( $this, 'display_row' ), $rows ),
			array( 'target', 'net_click_change', 'clicks_prior', 'clicks_current', 'position_prior', 'position_current', 'position_change' )
		);
	}

	/**
	 * Flatten every requested dimension/side into a single row list (json/csv).
	 *
	 * @param array  $result    Ability result.
	 * @param string $dimension Requested dimension (page|query|both).
	 * @param string $show      Which side to show (decliners|risers|both).
	 * @return array<int, array<string, mixed>>
	 */
	private function all_rows( array $result, $dimension, $show ) {
		$out = array();

		foreach ( array( 'page', 'query' ) as $dim ) {
			if ( 'both' !== $dimension && $dimension !== $dim ) {
				continue;
			}

			$block = $result[ $dim ] ?? null;
			if ( ! is_array( $block ) || empty( $block['measured'] ) ) {
				continue;
			}

			$sides = array();
			if ( 'risers' !== $show ) {
				$sides['decliners'] = (array) ( $block['decliners'] ?? array() );
			}
			if ( 'decliners' !== $show ) {
				$sides['risers'] = (array) ( $block['risers'] ?? array() );
			}

			foreach ( $sides as $side => $rows ) {
				foreach ( $rows as $row ) {
					$out[] = array_merge(
						array(
							'dimension' => $dim,
							'change'    => $side,
						),
						$this->display_row( $row )
					);
				}
			}
		}

		return $out;
	}

	/**
	 * Shape one contributor row for display.
	 *
	 * @param array $row Contributor row from the ability.
	 * @return array<string, mixed>
	 */
	private function display_row( array $row ) {
		return array(
			'target'           => $row['target'] ?? '',
			'net_click_change' => $this->signed( (int) ( $row['net_click_change'] ?? 0 ) ),
			'clicks_prior'     => (int) ( $row['clicks_prior'] ?? 0 ),
			'clicks_current'   => (int) ( $row['clicks_current'] ?? 0 ),
			'position_prior'   => $this->pos( $row['position_prior'] ?? null ),
			'position_current' => $this->pos( $row['position_current'] ?? null ),
			'position_change'  => null === ( $row['position_change'] ?? null )
				? '—'
				: $this->signed_float( (float) $row['position_change'] ),
		);
	}

	/**
	 * Render a position value, marking an absent window as a dash.
	 *
	 * @param mixed $value Position value or null.
	 * @return string
	 */
	private function pos( $value ) {
		if ( null === $value ) {
			return '—';
		}

		return (string) round( (float) $value, 1 );
	}

	/**
	 * Format a signed integer with an explicit leading sign.
	 *
	 * @param int $value Value to format.
	 * @return string
	 */
	private function signed( $value ) {
		$value = (int) $value;

		return ( $value > 0 ? '+' : '' ) . $value;
	}

	/**
	 * Format a signed float (1 decimal) with an explicit leading sign.
	 *
	 * @param float $value Value to format.
	 * @return string
	 */
	private function signed_float( $value ) {
		$value = round( (float) $value, 1 );

		return ( $value > 0 ? '+' : '' ) . $value;
	}
}
