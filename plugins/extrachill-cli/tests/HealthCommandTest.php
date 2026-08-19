<?php
/**
 * Multisite ownership tests for the platform health queue scorecard.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

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

	function wp_get_ability( $name ) {
		global $health_command_error_ability, $health_command_jobs_ability;
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

namespace WP_CLI\Utils {
	$health_command_formats = array();

	function format_items( $format, $items, $fields ) {
		global $health_command_formats;
		$health_command_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Platform/HealthCommand.php';

	use ExtraChill\CLI\Commands\Platform\HealthCommand;

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

	echo "HealthCommandTest passed\n";
}
