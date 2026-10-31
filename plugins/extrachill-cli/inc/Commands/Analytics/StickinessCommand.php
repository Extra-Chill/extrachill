<?php
/**
 * Analytics Stickiness CLI Command
 *
 * Thin wrapper around the extrachill/get-surface-stickiness ability — the
 * ENGAGEMENT (not revenue) instrument for the platform's pivot surfaces:
 * community, artist platform, and the events calendar.
 *
 * The framing is load-bearing and deliberate: these surfaces earn $0 ad revenue
 * BY DESIGN (community has no ads; the artist platform is not monetized). The
 * all-time revenue x-ray reads them as dead — they are not. Their value is
 * STICKINESS: do real people come back, go deeper, and traverse between
 * surfaces? This command reports that, per surface, as a this-period-vs-prior
 * trend, and NEVER as ad revenue. A $0-ad-revenue surface can be the platform's
 * most valuable asset if it builds returning, dedicated visitors.
 *
 * It is a presentation layer only — all signals come from the ability, which
 * composes first-party ec_vid retention (return rate, session depth,
 * new-vs-returning) with deterministic windowed activity counters (topics /
 * profiles / events created) and a network-wide cross-surface traversal figure.
 * Cells the ability cannot measure show as "not instrumented" coverage gaps,
 * never as zeros — and a measured zero is reported honestly (these surfaces are
 * low-activity right now; the honest low number IS the finding).
 *
 * The command is inherently network-wide (it reads every pivot surface), so it
 * has no --site filter; the ability resolves each surface's blog from the
 * canonical multisite map.
 *
 * @package ExtraChill\CLI\Commands\Analytics
 */

namespace ExtraChill\CLI\Commands\Analytics;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StickinessCommand {

	/**
	 * Sentinel rendered when the ability reports a cell as not instrumented.
	 *
	 * @var string
	 */
	const GAP = 'not instrumented';

	/**
	 * Show per-surface stickiness (engagement, NOT ad revenue) for the pivot surfaces.
	 *
	 * Reports, per pivot surface (community, artist, events), a first-party
	 * ENGAGEMENT trend — return rate, session depth, and new-vs-returning split,
	 * each as this-window-vs-the-prior-equal-window — plus an ACTIVITY trend
	 * (topics / profiles / events created this window vs prior). A network-wide
	 * cross-surface traversal figure (visitors hitting >= 2 blogs) is reported
	 * once. These surfaces earn $0 ad revenue by design; this command exists so
	 * they are judged by stickiness instead. Cells the ability cannot measure
	 * show as "not instrumented" coverage gaps, never as zeros.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Length of the current window in days. The trend compares this window
	 *   against the immediately-preceding window of equal length.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. "table" prints a skimmable per-surface block; "json" /
	 *   "csv" emit one flattened record per surface.
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
	 *     # Default 28-day stickiness trend for the pivot surfaces.
	 *     wp extrachill analytics stickiness
	 *
	 *     # Last 7 days vs the prior 7.
	 *     wp extrachill analytics stickiness --days=7
	 *
	 *     # Machine-readable for piping into jq.
	 *     wp extrachill analytics stickiness --format=json
	 *
	 * @subcommand __default
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (--days, --format).
	 * @return void
	 */
	public function __invoke( $args, $assoc_args ) {
		$ability = wp_get_ability( 'extrachill/get-surface-stickiness' );

		if ( ! $ability ) {
			WP_CLI::error( 'extrachill/get-surface-stickiness ability not found. Is extrachill-analytics active?' );
		}

		$days   = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$format = $assoc_args['format'] ?? 'table';

		$result = $ability->execute( array( 'days' => $days ) );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$rows = $this->surface_rows( $result );

		if ( 'table' !== $format ) {
			Utils\format_items(
				$format,
				$rows,
				array( 'surface', 'return_rate', 'return_delta', 'session_depth', 'depth_delta', 'visitors', 'visitors_delta', 'activity', 'activity_delta' )
			);
			return;
		}

		$this->render_table( $result, $rows, $days );
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
			$engagement = $surface['engagement'] ?? array();
			$activity   = $surface['activity'] ?? array();

			if ( empty( $engagement['measured'] ) ) {
				$rows[] = array(
					'surface'        => $surface['surface'] ?? '',
					'return_rate'    => self::GAP,
					'return_delta'   => self::GAP,
					'session_depth'  => self::GAP,
					'depth_delta'    => self::GAP,
					'visitors'       => self::GAP,
					'visitors_delta' => self::GAP,
					'activity'       => $this->activity_cell( $activity ),
					'activity_delta' => $this->activity_delta_cell( $activity ),
				);
				continue;
			}

			$return = $engagement['return_rate'] ?? array();
			$depth  = $engagement['session_depth'] ?? array();
			$nvr    = $engagement['new_vs_returning'] ?? array();

			$rows[] = array(
				'surface'        => $surface['surface'] ?? '',
				'return_rate'    => $this->pct( $return['current'] ?? null ),
				'return_delta'   => $this->signed_pct( $return['delta'] ?? null ),
				'session_depth'  => $this->num( $depth['current'] ?? null ),
				'depth_delta'    => $this->signed_num( $depth['delta'] ?? null ),
				'visitors'       => $this->visitors_cell( $nvr ),
				'visitors_delta' => $this->signed_int( $nvr['total_delta'] ?? null ),
				'activity'       => $this->activity_cell( $activity ),
				'activity_delta' => $this->activity_delta_cell( $activity ),
			);
		}

		return $rows;
	}

	/**
	 * Render the composed records as a skimmable per-surface block report.
	 *
	 * @param array $result Ability result.
	 * @param array $rows   Flattened display rows.
	 * @param int   $days   Window length in days.
	 * @return void
	 */
	private function render_table( array $result, array $rows, $days ) {
		$window = $result['window'] ?? array();
		$prior  = $result['prior_window'] ?? array();

		WP_CLI::log( sprintf( 'Surface Stickiness — ENGAGEMENT lens (NOT ad revenue) — %d-day window', $days ) );
		WP_CLI::log( str_repeat( '═', 72 ) );
		WP_CLI::log(
			sprintf(
				'This period: %s → %s   vs prior: %s → %s',
				$this->date( $window['start'] ?? '' ),
				$this->date( $window['end'] ?? '' ),
				$this->date( $prior['start'] ?? '' ),
				$this->date( $prior['end'] ?? '' )
			)
		);
		WP_CLI::log( 'These surfaces earn $0 ad revenue by design — they are judged here by whether they build returning, engaged visitors.' );
		WP_CLI::log( 'First-party retention: ' . ( ! empty( $result['retention_available'] ) ? 'instrumented' : 'NOT instrumented (engagement shown as gaps)' ) );
		WP_CLI::log( '' );

		// Network-wide cross-surface traversal (a visitor crossing >= 2 blogs) is
		// reported once — it is inherently a network figure, never per-surface.
		$xsurface = $result['cross_surface'] ?? array();
		WP_CLI::log( 'Network-wide' );
		if ( ! empty( $xsurface['measured'] ) ) {
			WP_CLI::log(
				sprintf(
					'    Cross-surface traversal: %s   (Δ %s vs prior)',
					$this->pct( $xsurface['current'] ?? null ),
					$this->signed_pct( $xsurface['delta'] ?? null )
				)
			);
		} else {
			WP_CLI::log( '    Cross-surface traversal: ' . self::GAP . ' (' . ( $xsurface['reason'] ?? 'no signal' ) . ')' );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'Per-surface' );
		WP_CLI::log( str_repeat( '─', 72 ) );

		foreach ( $rows as $r ) {
			WP_CLI::log( sprintf( '● %s', $r['surface'] ) );
			WP_CLI::log( sprintf( '    Return rate:   %s   (Δ %s)', $r['return_rate'], $r['return_delta'] ) );
			WP_CLI::log( sprintf( '    Session depth: %s   (Δ %s)', $r['session_depth'], $r['depth_delta'] ) );
			WP_CLI::log( sprintf( '    Visitors:      %s   (Δ %s total)', $r['visitors'], $r['visitors_delta'] ) );
			WP_CLI::log( sprintf( '    Activity:      %s   (Δ %s)', $r['activity'], $r['activity_delta'] ) );
			WP_CLI::log( '' );
		}

		WP_CLI::log( str_repeat( '─', 72 ) );
		WP_CLI::log( sprintf( '%d pivot surface(s) read. Cells marked "%s" are coverage gaps, not zeros — a measured 0 means flat (and honest for a low-activity surface).', count( $rows ), self::GAP ) );
	}

	/**
	 * Build the visitors cell: "total (returning / new)".
	 *
	 * @param array $nvr New-vs-returning sub-result.
	 * @return string
	 */
	private function visitors_cell( array $nvr ) {
		$total     = (int) ( $nvr['total_visitors'] ?? 0 );
		$returning = (int) ( $nvr['returning_visitors'] ?? 0 );
		$new       = (int) ( $nvr['new_visitors'] ?? 0 );

		return sprintf( '%d (%d returning / %d new)', $total, $returning, $new );
	}

	/**
	 * Build the activity cell, distinguishing a coverage gap from a measured count.
	 *
	 * @param array $activity Activity sub-result.
	 * @return string
	 */
	private function activity_cell( array $activity ) {
		if ( empty( $activity['measured'] ) ) {
			return self::GAP;
		}

		return sprintf(
			'%d %s (%s/wk)',
			(int) ( $activity['current'] ?? 0 ),
			$activity['unit'] ?? 'items',
			$this->num( $activity['per_week'] ?? null )
		);
	}

	/**
	 * Build the activity-delta cell.
	 *
	 * @param array $activity Activity sub-result.
	 * @return string
	 */
	private function activity_delta_cell( array $activity ) {
		if ( empty( $activity['measured'] ) ) {
			return self::GAP;
		}

		return $this->signed_int( $activity['delta'] ?? null );
	}

	/**
	 * Format a 0..1 rate as a percentage, passing the GAP sentinel through.
	 *
	 * @param mixed $value Rate value (0..1).
	 * @return string
	 */
	private function pct( $value ) {
		if ( self::GAP === $value || null === $value ) {
			return null === $value ? '—' : self::GAP;
		}

		return number_format( (float) $value * 100, 2 ) . '%';
	}

	/**
	 * Format a signed percentage-point delta (a rate delta in 0..1 space).
	 *
	 * @param mixed $value Delta value (0..1 space), or null.
	 * @return string
	 */
	private function signed_pct( $value ) {
		if ( null === $value ) {
			return '—';
		}

		$pts  = (float) $value * 100;
		$sign = $pts >= 0 ? '+' : '';

		return $sign . number_format( $pts, 2 ) . 'pp';
	}

	/**
	 * Format a numeric value, tolerating null.
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function num( $value ) {
		if ( null === $value || '' === $value ) {
			return '—';
		}

		$float = (float) $value;

		if ( floor( $float ) === $float ) {
			return (string) (int) $float;
		}

		return (string) round( $float, 2 );
	}

	/**
	 * Format a signed numeric delta, tolerating null.
	 *
	 * @param mixed $value Delta value, or null.
	 * @return string
	 */
	private function signed_num( $value ) {
		if ( null === $value ) {
			return '—';
		}

		$float = (float) $value;
		$sign  = $float >= 0 ? '+' : '';

		return $sign . $this->num( $float );
	}

	/**
	 * Format a signed integer delta, tolerating null.
	 *
	 * @param mixed $value Delta value, or null.
	 * @return string
	 */
	private function signed_int( $value ) {
		if ( null === $value ) {
			return '—';
		}

		$int  = (int) $value;
		$sign = $int >= 0 ? '+' : '';

		return $sign . $int;
	}

	/**
	 * Reduce a Y-m-d H:i:s timestamp to a bare date for compact display.
	 *
	 * @param string $value Timestamp string.
	 * @return string
	 */
	private function date( $value ) {
		if ( '' === $value ) {
			return '';
		}

		return substr( (string) $value, 0, 10 );
	}
}
