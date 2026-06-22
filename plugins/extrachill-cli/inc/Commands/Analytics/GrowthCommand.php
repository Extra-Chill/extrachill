<?php
/**
 * Analytics Growth CLI Command
 *
 * Thin wrapper around the extrachill/get-surface-growth ability — the
 * cross-surface growth-RATE instrument. Answers "which Extra Chill surface is
 * growing fastest" as a measured, ranked fact, separating SUPPLY (inventory
 * growth) from DEMAND (organic-sessions slope) so growing inventory is never
 * mistaken for a growing audience.
 *
 * The command is inherently network-wide (it ranks every live surface against
 * every other), so it intentionally has no --site filter; the ability resolves
 * each surface's blog and host from the canonical multisite map.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GrowthCommand {

	/**
	 * Show normalized cross-surface growth rates and the fastest-growing surface.
	 *
	 * Reports, per live surface (events, wire, community, artist, blog), a
	 * SUPPLY figure (new published items per week + percent-of-prior-catalog per
	 * week) and a DEMAND figure (organic-sessions slope, current window vs
	 * previous equal window). Supply and demand are ranked separately, and a
	 * single fastest-growing surface (by the supply axis every surface can
	 * produce) is highlighted. Unmeasurable dimensions show as "not instrumented"
	 * coverage gaps, never as zeros.
	 *
	 * DEMAND BASIS: the demand slope here is GA4 organic SESSIONS (weekly-trend
	 * regression). For per-page/per-query GSC CLICK attribution — a different lens
	 * that can legitimately disagree in sign — see `wp extrachill analytics
	 * demand-drill`. A sign mismatch between the two is not a regression.
	 *
	 * ## OPTIONS
	 *
	 * [--weeks=<weeks>]
	 * : Number of weeks the growth window spans. The demand slope compares this
	 *   window against the immediately-preceding window of equal length.
	 * ---
	 * default: 4
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
	 *     wp extrachill analytics growth
	 *     wp extrachill analytics growth --weeks=8
	 *     wp extrachill analytics growth --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-surface-growth' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-surface-growth ability not found. Is extrachill-analytics active?' );
		}

		$weeks  = max( 1, (int) ( $assoc_args['weeks'] ?? 4 ) );
		$format = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array( 'weeks' => $weeks ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = $this->surface_rows( $result );

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				$rows,
				array( 'surface', 'supply_per_week', 'supply_pct_per_week', 'demand_slope_pct', 'demand_basis' )
			);
			return;
		}

		$window = $result['window'] ?? array();
		WP_CLI::log(
			sprintf(
				'Cross-Surface Growth — %d-week window (%s → %s)',
				(int) ( $result['weeks'] ?? $weeks ),
				$window['start'] ?? '',
				$window['end'] ?? ''
			)
		);
		WP_CLI::log( 'GA: ' . ( ! empty( $result['ga_available'] ) ? 'available' : 'NOT available (demand not instrumented)' ) );
		WP_CLI::log( str_repeat( '─', 72 ) );

		$fastest = $result['fastest_growing'] ?? array();
		if ( ! empty( $fastest['surface'] ) ) {
			WP_CLI::log(
				sprintf(
					'Fastest growing (supply axis): %s — %s%%/wk inventory growth',
					$fastest['label'] ?? $fastest['surface'],
					$this->num( $fastest['pct_per_week'] ?? null )
				)
			);
		} else {
			WP_CLI::log( 'Fastest growing: ' . ( $fastest['reason'] ?? 'no rankable surface' ) );
		}
		WP_CLI::log( '' );

		Utils\format_items(
			'table',
			$rows,
			array( 'surface', 'supply_per_week', 'supply_pct_per_week', 'demand_slope_pct', 'demand_basis' )
		);

		// Surface any coverage gaps explicitly so a blind spot is never read as a
		// zero or silently dropped from the ranking.
		$gaps = $this->coverage_gaps( $result );
		if ( ! empty( $gaps ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Coverage gaps (not instrumented — distinct from a measured zero):' );
			foreach ( $gaps as $gap ) {
				WP_CLI::log( sprintf( '  %s [%s]: %s', $gap['surface'], $gap['dimension'], $gap['reason'] ) );
			}
		}

		WP_CLI::log( '' );
		WP_CLI::log( 'demand_slope_pct = GA4 organic-SESSIONS weekly slope. For per-page/per-query GSC CLICK attribution' );
		WP_CLI::log( '(a different lens that can legitimately disagree in sign), run `wp extrachill analytics demand-drill`.' );
	}

	/**
	 * Flatten the per-surface ability result into displayable rows.
	 *
	 * @param array $result Ability result.
	 * @return array<int, array<string, mixed>>
	 */
	private function surface_rows( array $result ) {
		$rows = array();

		foreach ( (array) ( $result['surfaces'] ?? array() ) as $surface ) {
			$supply = $surface['supply'] ?? array();
			$demand = $surface['demand'] ?? array();

			$rows[] = array(
				'surface'             => $surface['surface'] ?? '',
				'supply_per_week'     => ! empty( $supply['measured'] )
					? $this->num( $supply['per_week'] ?? null ) . ' ' . ( $supply['unit'] ?? '' )
					: 'n/i',
				'supply_pct_per_week' => ! empty( $supply['measured'] ) && null !== ( $supply['pct_per_week'] ?? null )
					? $this->num( $supply['pct_per_week'] ) . '%'
					: ( ! empty( $supply['measured'] ) ? '—' : 'n/i' ),
				'demand_slope_pct'    => $this->demand_slope_label( $demand ),
				'demand_basis'        => ! empty( $demand['measured'] ) ? ( $demand['basis'] ?? '' ) : 'n/i',
			);
		}

		return $rows;
	}

	/**
	 * Build the demand slope cell, distinguishing new-traffic and gaps from zero.
	 *
	 * @param array $demand Demand sub-result.
	 * @return string
	 */
	private function demand_slope_label( array $demand ) {
		if ( empty( $demand['measured'] ) ) {
			return 'n/i';
		}

		if ( ! empty( $demand['is_new_traffic'] ) ) {
			return 'new';
		}

		if ( null === ( $demand['slope_pct'] ?? null ) ) {
			return '—';
		}

		$slope = (float) $demand['slope_pct'];
		$sign  = $slope >= 0 ? '+' : '';

		return $sign . $this->num( $slope ) . '%';
	}

	/**
	 * Collect explicit coverage gaps across both dimensions.
	 *
	 * @param array $result Ability result.
	 * @return array<int, array{surface:string,dimension:string,reason:string}>
	 */
	private function coverage_gaps( array $result ) {
		$gaps = array();

		foreach ( (array) ( $result['surfaces'] ?? array() ) as $surface ) {
			$key = $surface['surface'] ?? '';

			foreach ( array( 'supply', 'demand' ) as $dimension ) {
				$dim = $surface[ $dimension ] ?? array();
				if ( ! empty( $dim['not_instrumented'] ) ) {
					$gaps[] = array(
						'surface'   => $key,
						'dimension' => $dimension,
						'reason'    => $dim['reason'] ?? 'not instrumented',
					);
				}
			}
		}

		return $gaps;
	}

	/**
	 * Format a numeric value for display, tolerating null.
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function num( $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		$float = (float) $value;

		// Render whole numbers without a trailing ".0"; otherwise round to 2dp.
		if ( $float === floor( $float ) ) {
			return (string) (int) $float;
		}

		return (string) round( $float, 2 );
	}
}
