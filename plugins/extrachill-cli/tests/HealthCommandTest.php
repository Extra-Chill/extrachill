<?php
/**
 * Multisite ownership tests for the platform health queue scorecard.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_CLI {
		public static $messages = array();

		public static function log( $message ) {
			self::$messages[] = $message;
		}
	}

	class HealthCommandTestAbility {
		public $result = array();

		public function execute( $input ) {
			return $this->result;
		}
	}

	$health_command_current_blog = 1;
	$health_command_blog_stack   = array();
	$health_command_jobs_ability = new HealthCommandTestAbility();

	function wp_get_ability( $name ) {
		global $health_command_jobs_ability;
		return 'datamachine/get-jobs-summary' === $name ? $health_command_jobs_ability : null;
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
		return false;
	}

	function health_command_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
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

	global $health_command_current_blog, $health_command_formats, $health_command_jobs_ability;

	$command = new HealthCommand();

	// A clean main-site ability result must not be repeated as a false network total.
	$health_command_jobs_ability->result = array(
		'summary' => array(
			'failed_count'           => 0,
			'stuck_processing_count' => 0,
		),
	);
	$command->health( array(), array( 'format' => 'json' ) );
	$main_rows = $health_command_formats[0]['items'];
	health_command_assert_same( 0, $main_rows[0]['queue_failed'], 'The bootstrap site must receive its own failed count.' );
	health_command_assert_same( 0, $main_rows[0]['queue_stuck'], 'The bootstrap site must receive its own stuck count.' );
	health_command_assert_same( HealthCommand::GAP, $main_rows[1]['queue_failed'], 'A subsite must not inherit the main-site failed count.' );
	health_command_assert_same( HealthCommand::GAP, $main_rows[1]['queue_stuck'], 'A subsite must not inherit the main-site stuck count.' );
	health_command_assert_same( false, array_key_exists( 'network_queue_failed', $main_rows[0] ), 'Machine output must not claim the site-owned count is network-wide.' );
	health_command_assert_same( false, array_key_exists( 'network_queue_stuck', $main_rows[0] ), 'Machine output must not claim the site-owned count is network-wide.' );

	// Bootstrapping the Events site exposes its site-owned queue on the Events row.
	$health_command_current_blog = 7;
	$health_command_jobs_ability->result = array(
		'summary' => array(
			'failed_count'           => 5681,
			'stuck_processing_count' => 80,
		),
	);
	$command->health( array(), array( 'format' => 'json' ) );
	$events_rows = $health_command_formats[1]['items'];
	health_command_assert_same( HealthCommand::GAP, $events_rows[0]['queue_failed'], 'The main-site row must remain uninstrumented in an Events bootstrap.' );
	health_command_assert_same( HealthCommand::GAP, $events_rows[0]['queue_stuck'], 'The main-site row must remain uninstrumented in an Events bootstrap.' );
	health_command_assert_same( 5681, $events_rows[1]['queue_failed'], 'The Events row must expose its failed jobs.' );
	health_command_assert_same( 80, $events_rows[1]['queue_stuck'], 'The Events row must expose its stuck processing jobs.' );

	echo "HealthCommandTest passed\n";
}
