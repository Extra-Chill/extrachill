<?php
/**
 * Multisite ownership tests for the platform health queue scorecard.
 */

namespace {
	$health_command_wp_bootstrapped = function_exists( 'get_user_by' );

	if ( ! $health_command_wp_bootstrapped ) {
		// Real WordPress (or the WP Codebox managed PHPUnit harness) is
		// already bootstrapped and this file's global function/class doubles
		// would collide with core, so skip the whole file. Run it standalone
		// instead: `php tests/HealthCommandTest.php` (see homeboy.json self_checks).
		define( 'ABSPATH', __DIR__ . '/' );
		define( 'DAY_IN_SECONDS', 86400 );

		class WP_CLI {
			public static $messages = array();
			public static $printed  = array();

			public static function log( $message ) {
				self::$messages[] = $message;
			}

			public static function print_value( $value, $args ) {
				self::$printed[] = compact( 'value', 'args' );
			}
		}

		class WP_Error {}

		class HealthCommandTestAbility {
			public $result = array();
			public $inputs = array();

			public function execute( $input ) {
				$this->inputs[] = $input;
				return $this->result;
			}
		}

		$health_command_current_blog  = 1;
		$health_command_blog_stack    = array();
		$health_command_jobs_ability  = new HealthCommandTestAbility();
		$health_command_error_ability = new HealthCommandTestAbility();
		$health_command_ga_ability    = null;

		function wp_get_ability( $name ) {
			global $health_command_error_ability, $health_command_jobs_ability, $health_command_ga_ability;
			if ( 'datamachine/google-analytics' === $name ) {
				return $health_command_ga_ability;
			}
			if ( 'datamachine/get-jobs-summary' === $name ) {
				return $health_command_jobs_ability;
			}
			if ( 'extrachill/get-php-error-summary' === $name ) {
				return $health_command_error_ability;
			}
			return null;
		}

		function get_current_blog_id() {
			global $health_command_current_blog;
			return $health_command_current_blog;
		}

		function switch_to_blog( $blog_id ) {
			global $health_command_blog_stack, $health_command_current_blog;
			$health_command_blog_stack[] = $health_command_current_blog;
			$health_command_current_blog = $blog_id;
			return true;
		}

		function restore_current_blog() {
			global $health_command_blog_stack, $health_command_current_blog;
			$health_command_current_blog = array_pop( $health_command_blog_stack );
			return true;
		}

		function get_sites( $args ) {
			return array( 1, 7 );
		}

		function get_blog_details( $blog_id ) {
			return (object) array(
				'domain' => 1 === (int) $blog_id ? 'extrachill.com' : 'events.extrachill.com',
			);
		}

		function get_post_types( $args, $output ) {
			return array();
		}

		function wp_count_posts( $type ) {
			return (object) array();
		}

		function is_wp_error( $value ) {
			return $value instanceof WP_Error;
		}

		function health_command_assert_same( $expected, $actual, $message ) {
			if ( $expected !== $actual ) {
				throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
			}
		}

		function health_command_assert_contains( $needle, $haystack, $message ) {
			if ( false === strpos( $haystack, $needle ) ) {
				throw new \RuntimeException( $message . '\nMissing: ' . $needle . '\nActual: ' . $haystack );
			}
		}
	}
}

namespace WP_CLI\Utils {
	if ( ! $health_command_wp_bootstrapped ) {
		$health_command_formats = array();

		function format_items( $format, $items, $fields ) {
			global $health_command_formats;
			$health_command_formats[] = compact( 'format', 'items', 'fields' );
		}
	}
}

namespace {
	use ExtraChill\CLI\Commands\Platform\HealthCommand;

	if ( ! $health_command_wp_bootstrapped ) {
		require_once dirname( __DIR__ ) . '/inc/Commands/Platform/HealthCommand.php';


		global $health_command_current_blog, $health_command_error_ability, $health_command_formats, $health_command_jobs_ability;

		$command = new HealthCommand();
		$health_command_error_ability->result = array( 'active_per_day' => 42.5 );

		// JSON must preserve network and site scopes as distinct typed structures.
		$health_command_jobs_ability->result = array(
			'success' => true,
			'summary' => array(
				'failed_count'           => 0,
				'stuck_processing_count' => 0,
			),
		);
		$command->health( array(), array( 'format' => 'json' ) );
		$main_json = WP_CLI::$printed[0]['value'];
		$main_rows = $main_json['sites'];
		health_command_assert_same( array( 'errors_per_day' => 42.5 ), $main_json['network'], 'JSON must expose the host-wide error rate exactly once in its network scope.' );
		health_command_assert_same( array( 'format' => 'json' ), WP_CLI::$printed[0]['args'], 'JSON must use WP-CLI structured output.' );
		health_command_assert_same( 0, $main_rows[0]['queue_failed'], 'The bootstrap site must receive its own failed count.' );
		health_command_assert_same( 0, $main_rows[0]['queue_stuck'], 'The bootstrap site must receive its own stuck count.' );
		health_command_assert_same( array( 'compact' => true ), $health_command_jobs_ability->inputs[0], 'Queue health must request the owner ability compact response.' );
		health_command_assert_same( HealthCommand::GAP, $main_rows[1]['queue_failed'], 'A subsite must not inherit the main-site failed count.' );
		health_command_assert_same( HealthCommand::GAP, $main_rows[1]['queue_stuck'], 'A subsite must not inherit the main-site stuck count.' );
		health_command_assert_same( false, array_key_exists( 'network_errors_per_day', $main_rows[0] ), 'JSON site rows must not duplicate the network error rate.' );
		health_command_assert_same( false, array_key_exists( 'errors_per_day', $main_rows[0] ), 'JSON site rows must not imply per-site error attribution.' );

		// Bootstrapping the Events site exposes its site-owned queue on the Events row.
		$health_command_current_blog = 7;
		$health_command_jobs_ability->result = array(
			'success' => true,
			'summary' => array(
				'failed_count'           => 1027,
				'stuck_processing_count' => 626,
			),
		);
		$command->health( array(), array( 'format' => 'json' ) );
		$events_rows = WP_CLI::$printed[1]['value']['sites'];
		health_command_assert_same( HealthCommand::GAP, $events_rows[0]['queue_failed'], 'The main-site row must remain uninstrumented in an Events bootstrap.' );
		health_command_assert_same( HealthCommand::GAP, $events_rows[0]['queue_stuck'], 'The main-site row must remain uninstrumented in an Events bootstrap.' );
		health_command_assert_same( 1027, $events_rows[1]['queue_failed'], 'The Events row must expose its failed jobs.' );
		health_command_assert_same( 626, $events_rows[1]['queue_stuck'], 'The Events row must expose its stuck processing jobs.' );

		// A partial or malformed owner payload must remain visibly uninstrumented.
		$health_command_jobs_ability->result = array(
			'success' => true,
			'summary' => array( 'failed_count' => 0 ),
		);
		$command->health( array(), array( 'format' => 'json' ) );
		$malformed_rows = WP_CLI::$printed[2]['value']['sites'];
		health_command_assert_same( HealthCommand::GAP, $malformed_rows[1]['queue_failed'], 'A partial payload must not become a healthy failed count.' );
		health_command_assert_same( HealthCommand::GAP, $malformed_rows[1]['queue_stuck'], 'A partial payload must not become a healthy stuck count.' );

		$health_command_jobs_ability->result = new WP_Error();
		$command->health( array(), array( 'format' => 'json' ) );
		$error_rows = WP_CLI::$printed[3]['value']['sites'];
		health_command_assert_same( HealthCommand::GAP, $error_rows[1]['queue_failed'], 'An owner error must not become a healthy failed count.' );
		health_command_assert_same( HealthCommand::GAP, $error_rows[1]['queue_stuck'], 'An owner error must not become a healthy stuck count.' );

		// CSV must carry the host-wide rate only on an explicitly scoped network row.
		$health_command_current_blog = 1;
		$health_command_jobs_ability->result = array(
			'success' => true,
			'summary' => array(
				'failed_count'           => 3,
				'stuck_processing_count' => 1,
			),
		);
		$command->health( array(), array( 'format' => 'csv' ) );
		$csv = $health_command_formats[0];
		health_command_assert_same( 'csv', $csv['format'], 'CSV must use the requested formatter.' );
		health_command_assert_same( 3, count( $csv['items'] ), 'CSV must contain one network row and one row per site.' );
		health_command_assert_same( 'network', $csv['items'][0]['scope'], 'CSV must identify the network row explicitly.' );
		health_command_assert_same( 42.5, $csv['items'][0]['network_errors_per_day'], 'CSV must report the error rate on the network row.' );
		health_command_assert_same( '', $csv['items'][0]['site'], 'The network row must not claim a site identity.' );
		health_command_assert_same( 'site', $csv['items'][1]['scope'], 'CSV must identify site rows explicitly.' );
		health_command_assert_same( '', $csv['items'][1]['network_errors_per_day'], 'CSV site rows must not duplicate the network error rate.' );
		health_command_assert_same( 3, $csv['items'][1]['queue_failed'], 'CSV must retain context-valid site queue fields.' );
		health_command_assert_same( HealthCommand::GAP, $csv['items'][2]['queue_failed'], 'CSV must retain uninstrumented queue fields for other sites.' );

		// Human output continues to label and print the error rate once network-wide.
		WP_CLI::$messages = array();
		$command->health( array(), array( 'format' => 'table' ) );
		$table = implode( "\n", WP_CLI::$messages );
		health_command_assert_contains( 'Network-wide', $table, 'Table output must retain its network scope label.' );
		health_command_assert_contains( 'Errors/day:  42.5', $table, 'Table output must retain the network error rate.' );
		health_command_assert_same( 1, substr_count( $table, 'Errors/day:' ), 'Table output must print the network error rate once.' );

		// Without GA, every traffic field is an explicit coverage gap.
		foreach ( HealthCommand::TRAFFIC_FIELDS as $field ) {
			health_command_assert_same( HealthCommand::GAP, $main_rows[0][ $field ], "Absent GA must leave {$field} uninstrumented." );
		}

		// Fixture rows mirror the live aggregate_report normalized shape
		// (events.extrachill.com, 2026-09-16..10-14): string metric values
		// keyed by name under one sessionDefaultChannelGroup dimension.
		$channel_row = static function ( $channel, $sessions, $engaged, $duration ) {
			return array(
				'dimensions' => array( 'sessionDefaultChannelGroup' => $channel ),
				'metrics'    => array(
					'sessions'               => (string) $sessions,
					'engagedSessions'        => (string) $engaged,
					'averageSessionDuration' => (string) $duration,
				),
			);
		};
		$events_rows = array(
			$channel_row( 'Direct', 86648, 11414, '6.79216612281876' ),
			$channel_row( 'Organic Search', 30445, 18520, '73.310301704582031' ),
			$channel_row( 'Unassigned', 3982, 32, '3.4244342604218985' ),
			$channel_row( 'AI Assistant', 689, 357, '82.128227625544255' ),
		);

		$events = HealthCommand::summarize_channel_rows( $events_rows );
		health_command_assert_same( 121764, $events['sessions'], 'Raw sessions sum every channel.' );
		health_command_assert_same( 30323, $events['engaged_sessions'], 'Engaged sessions sum every channel.' );
		health_command_assert_same( 71.2, $events['direct_pct'], 'Raw direct share keeps its original meaning.' );
		health_command_assert_same( 25.0, $events['organic_pct'], 'Raw organic share keeps its original meaning.' );
		health_command_assert_same( 61.1, $events['engaged_organic_pct'], 'Engaged organic share is organic engaged / all engaged.' );
		health_command_assert_same( 37.6, $events['engaged_direct_pct'], 'Engaged direct share is direct engaged / all engaged.' );
		health_command_assert_same( 0.132, $events['direct_engagement_rate'], 'Direct engagement rate is engaged / sessions.' );
		health_command_assert_same( 6.8, $events['direct_avg_duration'], 'Direct average duration comes from the direct row.' );
		health_command_assert_same( true, $events['direct_likely_non_human'], 'Low-engagement, sub-10s direct traffic is flagged.' );

		// Threshold edges: failing only one of the two thresholds is not flagged.
		$short_but_engaged = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 1000, 400, '5.0' ) ) );
		health_command_assert_same( false, $short_but_engaged['direct_likely_non_human'], 'Engaged direct traffic is human even when sessions are short.' );
		$long_but_unengaged = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 1000, 100, '45.0' ) ) );
		health_command_assert_same( false, $long_but_unengaged['direct_likely_non_human'], 'Long direct sessions are not flagged on engagement alone.' );
		$at_threshold = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 1000, 200, '10.0' ) ) );
		health_command_assert_same( false, $at_threshold['direct_likely_non_human'], 'Exactly at both thresholds is not flagged.' );
		$just_under = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 1000, 199, '9.9' ) ) );
		health_command_assert_same( true, $just_under['direct_likely_non_human'], 'Just under both thresholds is flagged.' );

		$tiny = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 99, 5, '3.0' ) ) );
		health_command_assert_same( false, $tiny['direct_likely_non_human'], 'Surfaces under the minimum direct sample are too small to flag.' );
		$minimum = HealthCommand::summarize_channel_rows( array( $channel_row( 'Direct', 100, 5, '3.0' ) ) );
		health_command_assert_same( true, $minimum['direct_likely_non_human'], 'The minimum direct sample is enough to flag.' );

		$no_direct = HealthCommand::summarize_channel_rows( array( $channel_row( 'Organic Search', 10, 5, '60' ) ) );
		health_command_assert_same( false, $no_direct['direct_likely_non_human'], 'No direct traffic is never flagged.' );
		health_command_assert_same( 0.0, $no_direct['engaged_direct_pct'], 'No direct traffic yields a zero direct share.' );
		$empty = HealthCommand::summarize_channel_rows( array() );
		health_command_assert_same( 0, $empty['sessions'], 'Empty reports yield zero sessions without dividing by zero.' );
		health_command_assert_same( 0.0, $empty['engaged_organic_pct'], 'Empty reports yield zero shares.' );

		// The command requests explicit metrics through aggregate_report, per host.
		$health_command_ga_ability         = new HealthCommandTestAbility();
		$health_command_ga_ability->result = array(
			'success' => true,
			'action'  => 'aggregate_report',
			'reports' => array( array( 'rows' => $events_rows ) ),
		);
		WP_CLI::$printed = array();
		$command->health( array(), array( 'format' => 'json', 'days' => 28 ) );
		$ga_input = $health_command_ga_ability->inputs[1];
		health_command_assert_same( 'aggregate_report', $ga_input['action'], 'Traffic must come from aggregate_report.' );
		health_command_assert_same( array( 'sessions', 'engagedSessions', 'averageSessionDuration' ), $ga_input['metrics'], 'Engagement metrics must be requested explicitly.' );
		health_command_assert_same( array( 'sessionDefaultChannelGroup' ), $ga_input['dimensions'], 'Traffic must be split by default channel group.' );
		health_command_assert_same(
			array(
				array(
					'field_name' => 'hostName',
					'match_type' => 'EXACT',
					'value'      => 'events.extrachill.com',
				),
			),
			$ga_input['filters'],
			'Each surface must be scoped to its own host.'
		);
		health_command_assert_same( 1, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $ga_input['date_range']['start_date'] ), 'aggregate_report requires exact dates.' );
		health_command_assert_same( 28 * DAY_IN_SECONDS, strtotime( $ga_input['date_range']['end_date'] ) - strtotime( $ga_input['date_range']['start_date'] ), 'The window must match --days.' );
		$ga_site = WP_CLI::$printed[0]['value']['sites'][1];
		health_command_assert_same( 30323, $ga_site['engaged_sessions'], 'JSON must expose engaged sessions.' );
		health_command_assert_same( true, $ga_site['direct_likely_non_human'], 'JSON must expose the non-human flag.' );
		health_command_assert_same( 121764, $ga_site['sessions'], 'JSON must keep raw sessions.' );

		// A failed GA call reports zeroes, not a misleading flag.
		$health_command_ga_ability->result = array(
			'success' => false,
			'error'   => 'quota',
		);
		$command->health( array(), array( 'format' => 'json' ) );
		$failed_site = WP_CLI::$printed[1]['value']['sites'][1];
		health_command_assert_same( 0, $failed_site['engaged_sessions'], 'A failed report yields zero engaged sessions.' );
		health_command_assert_same( false, $failed_site['direct_likely_non_human'], 'A failed report never flags direct traffic.' );

		// The table leads with engaged traffic and warns on non-human direct.
		$health_command_ga_ability->result = array(
			'success' => true,
			'reports' => array( array( 'rows' => $events_rows ) ),
		);
		WP_CLI::$messages = array();
		$command->health( array(), array( 'format' => 'table' ) );
		$table = implode( "\n", WP_CLI::$messages );
		health_command_assert_contains( 'Traffic:     30,323 engaged sessions   organic 61.1%   direct 37.6%', $table, 'Table must lead with engaged traffic.' );
		health_command_assert_contains( 'Raw:         121,764 sessions   organic 25.0%   direct 71.2%', $table, 'Table must keep raw sessions visible.' );
		health_command_assert_contains( 'Direct looks non-human: 13.2% engaged, 6.8s avg', $table, 'Table must flag non-human direct traffic.' );

		// CSV carries every traffic field as a named column.
		$health_command_formats = array();
		$command->health( array(), array( 'format' => 'csv' ) );
		foreach ( HealthCommand::TRAFFIC_FIELDS as $field ) {
			health_command_assert_same( true, in_array( $field, $health_command_formats[0]['fields'], true ), "CSV must include {$field}." );
			health_command_assert_same( '', $health_command_formats[0]['items'][0][ $field ], "The CSV network row must leave {$field} empty." );
		}

		echo "HealthCommandTest passed\n";
	}
}
