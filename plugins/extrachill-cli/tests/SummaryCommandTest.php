<?php
/**
 * Focused machine and table presenter tests for the analytics summary command.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $messages = array();
		public static $printed  = array();

		public static function error( $message ) {
			throw new RuntimeException( $message );
		}

		public static function warning( $message ) {
			self::$messages[] = $message;
		}

		public static function success( $message ) {
			self::$messages[] = $message;
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}

		public static function print_value( $value, $args ) {
			self::$printed[] = compact( 'value', 'args' );
		}
	}

	class SummaryCommandTestAbility {
		public $result;

		public function execute( $input ) {
			return $this->result;
		}

		public function get_input_schema() {
			return array( 'properties' => array( 'blog_id' => array( 'type' => 'integer' ) ) );
		}
	}

	$summary_command_test_ability = new SummaryCommandTestAbility();

	function wp_get_ability( $name ) {
		global $summary_command_test_ability;
		return 'extrachill/get-analytics-summary' === $name ? $summary_command_test_ability : null;
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

	function summary_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}
}

namespace WP_CLI\Utils {
	$summary_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $summary_command_test_formats;
		$summary_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Traits/NetworkAwareTrait.php';
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/SummaryCommand.php';

	use ExtraChill\CLI\Commands\Analytics\SummaryCommand;

	global $summary_command_test_ability, $summary_command_test_formats;

	$command = new SummaryCommand();

	$newsletter_result = array(
		'event_types' => array( array( 'event_type' => 'newsletter_signup', 'count' => 225, 'daily_avg' => 2.5 ) ),
		'total'       => 225,
		'days'        => 90,
		'period'      => '2026-04-18 to 2026-07-17',
		'since'       => '2026-04-18 12:00:00',
		'as_of'       => '2026-07-17 12:00:00',
		'by_date'     => array( array( 'date' => '2026-07-16', 'count' => 7 ) ),
		'by_source'   => array( array( 'source_url' => 'https://extrachill.com/', 'count' => 125 ) ),
		'by_context'  => array( array( 'context' => 'footer', 'count' => 80 ) ),
	);
	$summary_command_test_ability->result = $newsletter_result;
	$command( array(), array( 'type' => 'newsletter_signup', 'format' => 'json' ) );
	summary_command_test_assert_same( $newsletter_result, WP_CLI::$printed[0]['value'], 'Newsletter JSON must preserve source, context, date, and window detail.' );
	summary_command_test_assert_same( array( 'format' => 'json' ), WP_CLI::$printed[0]['args'], 'JSON must use WP-CLI structured output.' );

	$registration_result               = $newsletter_result;
	$registration_result['event_types'] = array( array( 'event_type' => 'user_registration', 'count' => 12, 'daily_avg' => 0.1 ) );
	$registration_result['total']       = 12;
	$registration_result['by_source']   = array( array( 'source_url' => 'https://community.extrachill.com/register/', 'count' => 12 ) );
	$registration_result['by_context']  = array( array( 'context' => 'registration', 'count' => 12 ) );
	$summary_command_test_ability->result = $registration_result;
	$command( array(), array( 'type' => 'user_registration', 'format' => 'json' ) );
	summary_command_test_assert_same( $registration_result, WP_CLI::$printed[1]['value'], 'Registration JSON must preserve its complete typed response.' );

	$all_event_result = array(
		'event_types' => array(
			array( 'event_type' => 'pageview', 'count' => 1000, 'daily_avg' => 35.7 ),
			array( 'event_type' => 'newsletter_signup', 'count' => 10, 'daily_avg' => 0.4 ),
		),
		'total'       => 1010,
		'days'        => 28,
		'period'      => '2026-06-19 to 2026-07-17',
		'since'       => '2026-06-19 12:00:00',
		'as_of'       => '2026-07-17 12:00:00',
	);
	$summary_command_test_ability->result = $all_event_result;
	$command( array(), array( 'format' => 'json' ) );
	summary_command_test_assert_same( $all_event_result, WP_CLI::$printed[2]['value'], 'All-event JSON must preserve the compact response envelope.' );

	$future_result                  = $newsletter_result;
	$future_result['future_metric'] = array( 'count' => 3, 'dimensions' => array( 'mobile', 'desktop' ) );
	$summary_command_test_ability->result = $future_result;
	$command( array(), array( 'type' => 'newsletter_signup', 'format' => 'csv' ) );
	$csv = $summary_command_test_formats[0];
	summary_command_test_assert_same( array_keys( $future_result ), $csv['fields'], 'CSV must retain every top-level ability field without a presenter allowlist.' );
	summary_command_test_assert_same( 225, $csv['items'][0]['total'], 'CSV scalar values must retain their source type before formatting.' );
	summary_command_test_assert_same( $future_result['by_source'], json_decode( $csv['items'][0]['by_source'], true ), 'CSV must preserve source detail as JSON.' );
	summary_command_test_assert_same( $future_result['by_context'], json_decode( $csv['items'][0]['by_context'], true ), 'CSV must preserve context detail as JSON.' );
	summary_command_test_assert_same( $future_result['future_metric'], json_decode( $csv['items'][0]['future_metric'], true ), 'CSV must preserve unknown future fields as JSON.' );

	$summary_command_test_ability->result = $all_event_result;
	$command( array(), array() );
	$table = $summary_command_test_formats[1];
	summary_command_test_assert_same( 'table', $table['format'], 'Summary must continue to default to table output.' );
	summary_command_test_assert_same( array( 'event_type', 'count', 'daily_avg' ), $table['fields'], 'Table columns must remain backward compatible.' );
	summary_command_test_assert_same( '1,000', $table['items'][0]['count'], 'Table counts must retain human formatting.' );

	fwrite( STDOUT, "SummaryCommand tests passed.\n" );
}
