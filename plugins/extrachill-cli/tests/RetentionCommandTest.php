<?php
/**
 * Focused machine and table presenter tests for the retention command.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $messages = array();
		public static $printed  = array();

		public static function error( $message ) {
			throw new RuntimeException( $message );
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function print_value( $value, $args ) {
			self::$printed[] = compact( 'value', 'args' );
		}
	}

	class RetentionCommandTestAbility {
		public $result;

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			return $this->result;
		}
	}

	$retention_command_test_result = array(
		'return_rate'       => array( 'total_visitors' => 1200, 'returning_visitors' => 300, 'rate' => 0.25, 'definition' => 'Returning visitors.' ),
		'cohort_retention'  => array(
			'cohorts'    => array(
				array( 'cohort_week' => '2026-W25', 'cohort_size' => 100, 'retention_w1' => 0.2, 'retention_w2' => 0.1 ),
				array( 'cohort_week' => '2026-W27', 'cohort_size' => 50, 'retention_w1' => null, 'retention_w2' => null ),
			),
			'weeks'      => 8,
			'definition' => 'Incomplete horizons are null.',
		),
		'cross_site_return' => array( 'total_visitors' => 1200, 'cross_site_visitors' => 120, 'rate' => 0.1, 'definition' => 'Cross-site visitors.' ),
		'session_depth'     => array( 'avg_pageviews_per_visitor_day' => 2.4, 'max_pageviews_per_visitor_day' => 12, 'definition' => 'Session depth.' ),
		'by_referrer_host'  => array(
			'hosts'      => array(
				array( 'referrer_host' => 'chatgpt.com', 'landings' => 1234 ),
				array( 'referrer_host' => 'community.extrachill.com', 'landings' => 98 ),
			),
			'definition' => 'Top cross-surface referrer hosts.',
		),
		'days'              => 28,
		'end_days_ago'      => 0,
		'blog_id'           => 0,
		'period'            => '2026-06-18 to 2026-07-16',
		'since'             => '2026-06-18 00:00:00',
		'until'             => '2026-07-16 00:00:00',
		'as_of'             => '2026-07-16 12:00:00',
		'note'              => 'Deterministic and bot-filtered.',
		'future_metric'     => array( 'count' => 7, 'rate' => 0.5 ),
	);
	$retention_command_test_ability = new RetentionCommandTestAbility( $retention_command_test_result );

	function wp_get_ability( $name ) {
		global $retention_command_test_ability;
		return 'extrachill/get-retention-stats' === $name ? $retention_command_test_ability : null;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function get_current_blog_id() {
		return 1;
	}

	function retention_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function retention_command_test_assert_true( $actual, $message ) {
		if ( ! $actual ) {
			throw new RuntimeException( $message );
		}
	}
}

namespace WP_CLI\Utils {
	$retention_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $retention_command_test_formats;
		$retention_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Traits/NetworkAwareTrait.php';
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/RetentionCommand.php';

	use ExtraChill\CLI\Commands\Analytics\RetentionCommand;

	global $retention_command_test_formats, $retention_command_test_result;

	$command = new RetentionCommand();
	$command( array(), array( 'format' => 'json' ) );
	retention_command_test_assert_same( $retention_command_test_result, WP_CLI::$printed[0]['value'], 'JSON must preserve the complete typed ability result.' );
	retention_command_test_assert_same( array( 'format' => 'json' ), WP_CLI::$printed[0]['args'], 'JSON must use WP-CLI structured output.' );
	retention_command_test_assert_same( null, $retention_command_test_result['cohort_retention']['cohorts'][1]['retention_w1'], 'Incomplete cohort horizons must remain null in machine output.' );

	$command( array(), array( 'format' => 'csv' ) );
	$csv = $retention_command_test_formats[0];
	retention_command_test_assert_same( array_keys( $retention_command_test_result ), $csv['fields'], 'CSV must retain every top-level ability field without a presenter allowlist.' );
	retention_command_test_assert_same( 28, $csv['items'][0]['days'], 'CSV scalar values must retain their source type before formatting.' );
	retention_command_test_assert_same( $retention_command_test_result['by_referrer_host'], json_decode( $csv['items'][0]['by_referrer_host'], true ), 'CSV must preserve nested referrer-host detail as JSON.' );
	retention_command_test_assert_same( $retention_command_test_result['future_metric'], json_decode( $csv['items'][0]['future_metric'], true ), 'CSV must preserve newly added ability fields without presenter changes.' );

	$command( array(), array() );
	$cohorts  = $retention_command_test_formats[1];
	$referrers = $retention_command_test_formats[2];
	retention_command_test_assert_same( array( 'cohort_week', 'cohort_size', 'retention_w1', 'retention_w2' ), $cohorts['fields'], 'Existing cohort table columns must remain backward compatible.' );
	retention_command_test_assert_same( 'n/a', $cohorts['items'][1]['retention_w1'], 'Incomplete W1 horizons must not display as zero retention.' );
	retention_command_test_assert_same( 'n/a', $cohorts['items'][1]['retention_w2'], 'Incomplete W2 horizons must not display as zero retention.' );
	retention_command_test_assert_same( array( 'referrer_host', 'landings' ), $referrers['fields'], 'Table output must include the referrer-host section.' );
	retention_command_test_assert_same( 'chatgpt.com', $referrers['items'][0]['referrer_host'], 'Referrer host names must survive table presentation.' );
	retention_command_test_assert_same( '1,234', $referrers['items'][0]['landings'], 'Referrer landings must use human count formatting.' );
	retention_command_test_assert_true( in_array( 'Referrer-host landings:', WP_CLI::$messages, true ), 'Human output must label the referrer-host section.' );

	fwrite( STDOUT, "RetentionCommand tests passed.\n" );
}
