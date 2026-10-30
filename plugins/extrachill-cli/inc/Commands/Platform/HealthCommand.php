<?php
/**
 * Platform Health CLI Command
 *
 * Composes a per-surface, bot-aware self-health scorecard for the Extra Chill
 * network by CALLING existing abilities and stitching their output together.
 * This is an AGGREGATION / PRESENTATION layer ONLY — it contains no business
 * logic and runs no direct database queries. Every signal in the scorecard is
 * produced by an ability that already owns that logic:
 *
 *   - Traffic (bot-aware)   datamachine/google-analytics  (aggregate_report)
 *   - Return rate           extrachill/get-retention-stats
 *   - Search-gap count      extrachill/get-search-gaps    (may be absent)
 *   - Error rate            extrachill/get-php-error-summary
 *   - Queue health          datamachine/get-jobs-summary
 *   - Content inventory     wp_count_posts (core read, per registered type)
 *
 * Where an ability is not present on the install, the corresponding cell is
 * rendered as an explicit "not instrumented" gap rather than faked or fataled.
 * The visible gaps double as an instrumentation-coverage map.
 *
 * @package ExtraChill\CLI\Commands\Platform
 */

namespace ExtraChill\CLI\Commands\Platform;

use WP_CLI;
use WP_CLI\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements the `wp extrachill platform health` command.
 */
class HealthCommand {

	/**
	 * Sentinel rendered when an ability is not present on the install.
	 *
	 * @var string
	 */
	const GAP = 'not instrumented';

	/**
	 * Traffic fields composed per site, in output order.
	 *
	 * Raw-session fields keep their original meaning; the engaged_* fields are
	 * the human-weighted view the table leads with.
	 *
	 * @var string[]
	 */
	const TRAFFIC_FIELDS = array( 'sessions', 'organic_pct', 'direct_pct', 'engaged_sessions', 'engaged_organic_pct', 'engaged_direct_pct', 'direct_engagement_rate', 'direct_avg_duration', 'direct_likely_non_human' );

	/**
	 * A direct channel below BOTH thresholds is flagged as likely non-human.
	 *
	 * Real direct visitors (bookmarks, typed URLs, app links) engage like other
	 * humans. Direct traffic where fewer than one in five sessions engages and
	 * the average session lasts under ten seconds is crawler/bot shaped (e.g.
	 * events.extrachill.com, 2026-09: 13% engaged, 6.8s average). Surfaces
	 * with fewer direct sessions than the minimum are too small to judge.
	 */
	const NON_HUMAN_MAX_ENGAGEMENT_RATE = 0.20;
	const NON_HUMAN_MAX_AVG_DURATION    = 10.0;
	const NON_HUMAN_MIN_DIRECT_SESSIONS = 100;

	/**
	 * Compose a per-surface, bot-aware platform-health scorecard.
	 *
	 * Walks every live network site and, for each, composes a scorecard row by
	 * calling the abilities that already own each signal. No business logic is
	 * implemented here — the command is a thin aggregator over abilities.
	 *
	 * Signals that depend on an absent ability are shown as an explicit
	 * "not instrumented" gap, never faked and never fatal, so the scorecard
	 * also reads as an instrumentation-coverage map.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Look-back window (in days) for traffic, return rate, and error rate.
	 * ---
	 * default: 28
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format. The "table" format prints a compact per-site block.
	 *   JSON returns separate "network" and "sites" scopes. CSV emits an
	 *   explicit network row followed by site rows.
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
	 *     # Default 28-day scorecard for the whole network.
	 *     wp extrachill platform health
	 *
	 *     # Last 7 days.
	 *     wp extrachill platform health --days=7
	 *
	 *     # Machine-readable for piping into jq.
	 *     wp extrachill platform health --format=json
	 *
	 * @subcommand health
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments (--days, --format).
	 * @return void
	 */
	public function health( $args, $assoc_args ) {
		$days   = max( 1, (int) ( $assoc_args['days'] ?? 28 ) );
		$format = $assoc_args['format'] ?? 'table';

		// Resolve which abilities are present once, up front. Absent abilities
		// become visible gaps in every row rather than per-call failures.
		$abilities = array(
			'ga'         => function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/google-analytics' ) : null,
			'retention'  => function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-retention-stats' ) : null,
			'search_gap' => function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-search-gaps' ) : null,
			'errors'     => function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-php-error-summary' ) : null,
			'jobs'       => function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/get-jobs-summary' ) : null,
		);

		// The error log is host-wide. Queue storage is site-owned, and the jobs
		// ability is bound to the bootstrap site's $wpdb->prefix when WordPress
		// loads. Switching blogs here would not rebind its repositories.
		$network = array(
			'errors_per_day' => $this->compose_errors( $abilities['errors'], $days ),
		);
		$queue   = array(
			'blog_id' => get_current_blog_id(),
			'failed'  => $this->compose_queue( $abilities['jobs'], 'failed' ),
			'stuck'   => $this->compose_queue( $abilities['jobs'], 'stuck' ),
		);

		$records = array();
		foreach ( $this->get_network_sites() as $blog_id => $hostname ) {
			$record                 = $this->compose_site( $blog_id, $hostname, $days, $abilities );
			$record['queue_failed'] = $queue['blog_id'] === $blog_id ? $queue['failed'] : self::GAP;
			$record['queue_stuck']  = $queue['blog_id'] === $blog_id ? $queue['stuck'] : self::GAP;
			$records[]              = $record;
		}

		if ( 'json' === $format ) {
			WP_CLI::print_value(
				array(
					'network' => $network,
					'sites'   => $records,
				),
				array( 'format' => 'json' )
			);
			return;
		}

		if ( 'csv' === $format ) {
			Utils\format_items(
				$format,
				$this->csv_rows( $records, $network ),
				array_merge( array( 'scope', 'site', 'blog_id' ), self::TRAFFIC_FIELDS, array( 'return_rate', 'search_gaps', 'content', 'network_errors_per_day', 'queue_failed', 'queue_stuck' ) )
			);
			return;
		}

		$this->render_table( $records, $network, $days, $abilities );
	}

	/**
	 * Build flat CSV rows without assigning network signals to a site.
	 *
	 * @param array<int, array<string, mixed>> $records Per-site records.
	 * @param array<string, mixed>             $network Network-global signals.
	 * @return array<int, array<string, mixed>>
	 */
	private function csv_rows( array $records, array $network ) {
		$empty_site = array_merge(
			array(
				'site'    => '',
				'blog_id' => '',
			),
			array_fill_keys( self::TRAFFIC_FIELDS, '' ),
			array(
				'return_rate'  => '',
				'search_gaps'  => '',
				'content'      => '',
				'queue_failed' => '',
				'queue_stuck'  => '',
			)
		);
		$rows       = array(
			array_merge(
				array( 'scope' => 'network' ),
				$empty_site,
				array( 'network_errors_per_day' => $network['errors_per_day'] )
			),
		);

		foreach ( $records as $record ) {
			$rows[] = array_merge(
				array( 'scope' => 'site' ),
				$record,
				array( 'network_errors_per_day' => '' )
			);
		}

		return $rows;
	}

	/**
	 * Compose a single site's scorecard record from ability output.
	 *
	 * @param int    $blog_id   Blog ID.
	 * @param string $hostname  Site hostname (used as the GA hostName filter).
	 * @param int    $days      Look-back window in days.
	 * @param array  $abilities Resolved ability map (values may be null).
	 * @return array<string, mixed>
	 */
	private function compose_site( $blog_id, $hostname, $days, array $abilities ) {
		$traffic = $this->compose_traffic( $abilities['ga'], $hostname, $days );

		return array_merge(
			array(
				'site'    => $hostname,
				'blog_id' => $blog_id,
			),
			$traffic,
			array(
				'return_rate' => $this->compose_return_rate( $abilities['retention'], $blog_id, $days ),
				'search_gaps' => $this->compose_search_gaps( $abilities['search_gap'], $blog_id, $days ),
				'content'     => $this->compose_content( $blog_id ),
			)
		);
	}

	/**
	 * Compose bot-aware traffic for one host from GA aggregate_report.
	 *
	 * Requests sessions, engagedSessions, and averageSessionDuration per
	 * sessionDefaultChannelGroup for the host. Raw shares (organic_pct,
	 * direct_pct) keep their original meaning; engaged shares weight each
	 * channel by sessions where a human actually engaged, so bot-inflated
	 * direct traffic stops dominating the picture. The direct channel is
	 * flagged when it falls under both non-human thresholds.
	 *
	 * @param \WP_Ability|null $ability  GA ability (or null if absent).
	 * @param string           $hostname Site hostname for the hostName filter.
	 * @param int              $days     Look-back window in days.
	 * @return array<string, mixed> Keyed by self::TRAFFIC_FIELDS.
	 */
	private function compose_traffic( $ability, $hostname, $days ) {
		if ( ! $ability ) {
			return array_fill_keys( self::TRAFFIC_FIELDS, self::GAP );
		}

		$now    = time();
		$result = $ability->execute(
			array(
				'action'     => 'aggregate_report',
				'date_range' => array(
					'start_date' => gmdate( 'Y-m-d', $now - $days * DAY_IN_SECONDS ),
					'end_date'   => gmdate( 'Y-m-d', $now ),
				),
				'dimensions' => array( 'sessionDefaultChannelGroup' ),
				'metrics'    => array( 'sessions', 'engagedSessions', 'averageSessionDuration' ),
				'filters'    => array(
					array(
						'field_name' => 'hostName',
						'match_type' => 'EXACT',
						'value'      => $hostname,
					),
				),
				'limit'      => 50,
			)
		);

		$rows = ( ! is_wp_error( $result ) && ! empty( $result['success'] ) ) ? ( $result['reports'][0]['rows'] ?? array() ) : array();

		return self::summarize_channel_rows( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Reduce aggregate_report channel rows to the traffic scorecard fields.
	 *
	 * Pure function over the ability's normalized row shape:
	 * `{ dimensions: { sessionDefaultChannelGroup }, metrics: { sessions,
	 * engagedSessions, averageSessionDuration } }`, metric values as strings.
	 *
	 * @param array<int, array<string, mixed>> $rows Normalized report rows.
	 * @return array<string, mixed> Keyed by self::TRAFFIC_FIELDS.
	 */
	public static function summarize_channel_rows( array $rows ) {
		$sessions        = array(
			'total'   => 0,
			'organic' => 0,
			'direct'  => 0,
		);
		$engaged         = $sessions;
		$direct_weighted = 0.0;

		foreach ( $rows as $row ) {
			$channel = (string) ( $row['dimensions']['sessionDefaultChannelGroup'] ?? '' );
			$count   = (int) ( $row['metrics']['sessions'] ?? 0 );
			$engage  = (int) ( $row['metrics']['engagedSessions'] ?? 0 );
			$bucket  = 'Organic Search' === $channel ? 'organic' : ( 'Direct' === $channel ? 'direct' : null );

			$sessions['total'] += $count;
			$engaged['total']  += $engage;
			if ( $bucket ) {
				$sessions[ $bucket ] += $count;
				$engaged[ $bucket ]  += $engage;
			}
			if ( 'direct' === $bucket ) {
				$direct_weighted += (float) ( $row['metrics']['averageSessionDuration'] ?? 0 ) * $count;
			}
		}

		$share = static function ( $part, $whole ) {
			return $whole > 0 ? round( $part / $whole * 100, 1 ) : 0.0;
		};

		$direct_rate     = $sessions['direct'] > 0 ? round( $engaged['direct'] / $sessions['direct'], 3 ) : 0.0;
		$direct_duration = $sessions['direct'] > 0 ? round( $direct_weighted / $sessions['direct'], 1 ) : 0.0;

		return array(
			'sessions'                => $sessions['total'],
			'organic_pct'             => $share( $sessions['organic'], $sessions['total'] ),
			'direct_pct'              => $share( $sessions['direct'], $sessions['total'] ),
			'engaged_sessions'        => $engaged['total'],
			'engaged_organic_pct'     => $share( $engaged['organic'], $engaged['total'] ),
			'engaged_direct_pct'      => $share( $engaged['direct'], $engaged['total'] ),
			'direct_engagement_rate'  => $direct_rate,
			'direct_avg_duration'     => $direct_duration,
			'direct_likely_non_human' => $sessions['direct'] >= self::NON_HUMAN_MIN_DIRECT_SESSIONS
				&& $direct_rate < self::NON_HUMAN_MAX_ENGAGEMENT_RATE
				&& $direct_duration < self::NON_HUMAN_MAX_AVG_DURATION,
		);
	}

	/**
	 * Compose the return-rate signal from extrachill/get-retention-stats.
	 *
	 * @param \WP_Ability|null $ability Retention ability (or null if absent).
	 * @param int         $blog_id Blog ID.
	 * @param int         $days    Look-back window in days.
	 * @return mixed Percentage float, or the GAP sentinel.
	 */
	private function compose_return_rate( $ability, $blog_id, $days ) {
		if ( ! $ability ) {
			return self::GAP;
		}

		$result = $ability->execute(
			array(
				'days'    => $days,
				'blog_id' => $blog_id,
			)
		);

		if ( is_wp_error( $result ) || ! isset( $result['return_rate']['rate'] ) ) {
			return 0.0;
		}

		return round( (float) $result['return_rate']['rate'] * 100, 1 );
	}

	/**
	 * Compose the zero-result search-gap count from extrachill/get-search-gaps.
	 *
	 * This ability ships in a sibling PR (#39) and may not be present on the
	 * install. When absent the cell is an explicit gap, never a hard failure.
	 *
	 * @param \WP_Ability|null $ability Search-gaps ability (or null if absent).
	 * @param int         $blog_id Blog ID.
	 * @param int         $days    Look-back window in days.
	 * @return mixed Integer gap count, or the GAP sentinel.
	 */
	private function compose_search_gaps( $ability, $blog_id, $days ) {
		if ( ! $ability ) {
			return self::GAP;
		}

		$result = $ability->execute(
			array(
				'days'    => $days,
				'blog_id' => $blog_id,
			)
		);

		if ( is_wp_error( $result ) ) {
			return 0;
		}

		// Be liberal about the shape the sibling ability returns: prefer the
		// ability's explicit zero-result total, then other explicit totals,
		// and finally fall back to counting a rows/gaps list.
		if ( isset( $result['zero_result_total'] ) ) {
			return (int) $result['zero_result_total'];
		}
		if ( isset( $result['zero_result'] ) ) {
			return (int) $result['zero_result'];
		}
		if ( isset( $result['total'] ) ) {
			return (int) $result['total'];
		}
		if ( isset( $result['count'] ) ) {
			return (int) $result['count'];
		}
		foreach ( array( 'gaps', 'rows', 'results' ) as $key ) {
			if ( isset( $result[ $key ] ) && is_array( $result[ $key ] ) ) {
				return count( $result[ $key ] );
			}
		}

		return 0;
	}

	/**
	 * Compose the per-day user-facing error rate from get-php-error-summary.
	 *
	 * The error log is a single host-wide file, so this is a network-global
	 * signal (computed once, not per-site).
	 *
	 * @param \WP_Ability|null $ability Error-summary ability (or null if absent).
	 * @param int         $days    Look-back window in days.
	 * @return mixed Per-day error rate float, or the GAP sentinel.
	 */
	private function compose_errors( $ability, $days ) {
		if ( ! $ability ) {
			return self::GAP;
		}

		$result = $ability->execute(
			array(
				'days'  => $days,
				'limit' => 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			return 0.0;
		}

		// Prefer the active-window lens: it counts only signatures still
		// firing inside the recent window, so resolved-but-persisted
		// signatures (frozen fatals/warnings) no longer inflate the rate.
		if ( isset( $result['active_per_day'] ) ) {
			return round( (float) $result['active_per_day'], 1 );
		}
		if ( isset( $result['active_total'] ) ) {
			$active_total       = (int) $result['active_total'];
			$active_window_days = max( 1.0, (float) ( $result['active_window_hours'] ?? 24 ) / 24 );

			return round( $active_total / $active_window_days, 1 );
		}

		// Fallback (older ability builds without the active-window lens):
		// the blended persisted+live total over the look-back window.
		$total        = (int) ( $result['total'] ?? 0 );
		$days_covered = max( 1, (int) ( $result['days_covered'] ?? $days ) );

		return round( $total / $days_covered, 1 );
	}

	/**
	 * Compose a queue-health metric from datamachine/get-jobs-summary.
	 *
	 * Data Machine jobs use the current site's table prefix. The resolved
	 * ability reads only the site used to bootstrap this WP-CLI process.
	 *
	 * @param \WP_Ability|null $ability Jobs-summary ability (or null if absent).
	 * @param string      $metric  Either 'failed' or 'stuck'.
	 * @return mixed Integer count, or the GAP sentinel.
	 */
	private function compose_queue( $ability, $metric ) {
		if ( ! $ability ) {
			return self::GAP;
		}

		$result  = $ability->execute( array( 'compact' => true ) );
		$summary = is_array( $result ) && isset( $result['summary'] ) && is_array( $result['summary'] )
			? $result['summary']
			: null;

		if (
			is_wp_error( $result )
			|| ! is_array( $result )
			|| true !== ( $result['success'] ?? false )
			|| ! is_array( $summary )
			|| ! isset( $summary['failed_count'], $summary['stuck_processing_count'] )
			|| ! is_int( $summary['failed_count'] )
			|| ! is_int( $summary['stuck_processing_count'] )
		) {
			return self::GAP;
		}

		if ( 'stuck' === $metric ) {
			return $summary['stuck_processing_count'];
		}

		return $summary['failed_count'];
	}

	/**
	 * Compose a content-inventory summary using core's wp_count_posts.
	 *
	 * Reads published counts for each public, non-built-in post type plus
	 * core posts/pages. This is a core read, not business logic — there is no
	 * Extra Chill ability that owns generic content counts.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string Compact "type:count" summary, e.g. "post:2820 page:12".
	 */
	private function compose_content( $blog_id ) {
		$switched = false;
		if ( get_current_blog_id() !== $blog_id && function_exists( 'switch_to_blog' ) ) {
			switch_to_blog( $blog_id );
			$switched = true;
		}

		$types = get_post_types( array( 'public' => true ), 'names' );

		$parts = array();
		foreach ( $types as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$counts    = wp_count_posts( $type );
			$published = isset( $counts->publish ) ? (int) $counts->publish : 0;
			if ( $published > 0 ) {
				$parts[] = $type . ':' . $published;
			}
		}

		if ( $switched ) {
			restore_current_blog();
		}

		return $parts ? implode( ' ', $parts ) : '(none)';
	}

	/**
	 * Render the composed records as a skimmable per-site block report.
	 *
	 * @param array<int, array<string, mixed>> $records   Per-site composed records.
	 * @param array<string, mixed>             $network   Network-global signals.
	 * @param int                              $days      Look-back window in days.
	 * @param array                            $abilities Resolved ability map.
	 * @return void
	 */
	private function render_table( array $records, array $network, $days, array $abilities ) {
		WP_CLI::log( sprintf( 'Platform Health Scorecard — last %d days — %s', $days, gmdate( 'Y-m-d H:i' ) . ' UTC' ) );
		WP_CLI::log( str_repeat( '═', 72 ) );

		// Surface which signals are instrumented up front (coverage map).
		$coverage = array(
			'Traffic (GA)' => (bool) $abilities['ga'],
			'Return rate'  => (bool) $abilities['retention'],
			'Search gaps'  => (bool) $abilities['search_gap'],
			'Error rate'   => (bool) $abilities['errors'],
			'Queue health' => (bool) $abilities['jobs'],
		);
		$present  = array();
		$missing  = array();
		foreach ( $coverage as $label => $ok ) {
			if ( $ok ) {
				$present[] = $label;
			} else {
				$missing[] = $label;
			}
		}
		WP_CLI::log( 'Instrumented: ' . ( $present ? implode( ', ', $present ) : 'none' ) );
		if ( $missing ) {
			WP_CLI::log( 'GAPS:         ' . implode( ', ', $missing ) . '  (shown as "' . self::GAP . '")' );
		}
		WP_CLI::log( '' );

		// The host-wide error signal is reported once. Queue health is rendered
		// below only for the site whose context bootstrapped the jobs ability.
		WP_CLI::log( 'Network-wide' );
		WP_CLI::log( sprintf( '    Errors/day:  %s', $this->fmt( $network['errors_per_day'] ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Per-surface' );
		WP_CLI::log( str_repeat( '─', 72 ) );

		foreach ( $records as $r ) {
			WP_CLI::log( sprintf( '● %s  (blog %d)', $r['site'], $r['blog_id'] ) );
			WP_CLI::log( sprintf( '    Traffic:     %s engaged sessions   organic %s   direct %s   (of engaged)', $this->fmt( $r['engaged_sessions'] ), $this->pct( $r['engaged_organic_pct'] ), $this->pct( $r['engaged_direct_pct'] ) ) );
			WP_CLI::log( sprintf( '    Raw:         %s sessions   organic %s   direct %s', $this->fmt( $r['sessions'] ), $this->pct( $r['organic_pct'] ), $this->pct( $r['direct_pct'] ) ) );
			if ( true === $r['direct_likely_non_human'] ) {
				WP_CLI::log( sprintf( '    ⚠ Direct looks non-human: %s engaged, %ss avg. Read the engaged line, not raw sessions.', $this->pct( $r['direct_engagement_rate'] * 100 ), number_format( (float) $r['direct_avg_duration'], 1 ) ) );
			}
			WP_CLI::log( sprintf( '    Return rate: %s', $this->pct( $r['return_rate'] ) ) );
			WP_CLI::log( sprintf( '    Search gaps: %s', $this->fmt( $r['search_gaps'] ) ) );
			WP_CLI::log( sprintf( '    Content:     %s', $r['content'] ) );
			WP_CLI::log( sprintf( '    Queue:       %s failed   %s stuck', $this->fmt( $r['queue_failed'] ), $this->fmt( $r['queue_stuck'] ) ) );
			WP_CLI::log( '' );
		}

		WP_CLI::log( str_repeat( '─', 72 ) );
		WP_CLI::log( sprintf( '%d surfaces composed. Cells marked "%s" are coverage gaps, not zeroes.', count( $records ), self::GAP ) );
	}

	/**
	 * Format a numeric value, passing the GAP sentinel through unchanged.
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function fmt( $value ) {
		if ( self::GAP === $value ) {
			return self::GAP;
		}
		if ( is_float( $value ) ) {
			return number_format( $value, 1 );
		}
		return number_format( (int) $value );
	}

	/**
	 * Format a percentage value, passing the GAP sentinel through unchanged.
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function pct( $value ) {
		if ( self::GAP === $value ) {
			return self::GAP;
		}
		return number_format( (float) $value, 1 ) . '%';
	}

	/**
	 * Get all live (non-archived, non-deleted) network sites.
	 *
	 * @return array<int, string> Map of blog_id => hostname.
	 */
	private function get_network_sites() {
		$sites = get_sites(
			array(
				'number'   => 100,
				'archived' => 0,
				'deleted'  => 0,
				'fields'   => 'ids',
			)
		);

		$map = array();
		foreach ( $sites as $blog_id ) {
			$details = get_blog_details( $blog_id );
			if ( $details ) {
				$map[ (int) $blog_id ] = rtrim( $details->domain, '/' );
			}
		}

		return $map;
	}
}
