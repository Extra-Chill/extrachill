<?php
/**
 * Focused contract tests for concert tracking CLI adapters.
 */

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class ConcertTrackingCommandTestError extends \RuntimeException {}

	class WP_CLI {
		public static $messages = array();

		public static function error( $message ) {
			throw new ConcertTrackingCommandTestError( $message );
		}

		public static function success( $message ) {
			self::$messages[] = 'Success: ' . $message;
		}

		public static function warning( $message ) {
			self::$messages[] = 'Warning: ' . $message;
		}

		public static function log( $message ) {
			self::$messages[] = $message;
		}
	}

	class WP_Error {
		private $code;
		private $message;

		public function __construct( $code, $message ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}
	}

	class ConcertTrackingCommandTestAbility {
		public $inputs = array();
		private $callback;

		public function __construct( $callback ) {
			$this->callback = $callback;
		}

		public function execute( $input ) {
			$this->inputs[] = $input;
			return call_user_func( $this->callback, $input );
		}
	}

	$concert_tracking_users = array(
		1 => array( 'ID' => 1, 'user_login' => 'admin', 'user_email' => 'admin@example.com' ),
		7 => array( 'ID' => 7, 'user_login' => 'chubes', 'user_email' => 'chubes@example.com' ),
		8 => array( 'ID' => 8, 'user_login' => 'listener', 'user_email' => 'listener@example.com' ),
	);
	$concert_tracking_posts = array(
		1 => array(
			10 => array( 'ID' => 10, 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Colliding Main Post' ),
			12 => array( 'ID' => 12, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Main-Site Event' ),
		),
		7 => array(
			10 => array( 'ID' => 10, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'First Show' ),
			11 => array( 'ID' => 11, 'post_type' => 'data_machine_events', 'post_status' => 'publish', 'post_title' => 'Second Show' ),
		),
	);
	$concert_tracking_sites           = array( 1, 7 );
	$concert_tracking_current_user_id = 0;
	$concert_tracking_current_blog_id = 1;
	$concert_tracking_events_blog_id  = 7;
	$concert_tracking_blog_stack      = array();
	$concert_tracking_marks           = array();
	$concert_tracking_abilities       = array();
	$concert_tracking_set_error       = null;
	$concert_tracking_check_error     = null;

	function get_user_by( $field, $value ) {
		global $concert_tracking_users;
		foreach ( $concert_tracking_users as $user ) {
			if ( ( 'id' === $field && (int) $value === $user['ID'] ) || ( 'login' === $field && $value === $user['user_login'] ) || ( 'email' === $field && $value === $user['user_email'] ) ) {
				return (object) $user;
			}
		}
		return false;
	}

	function wp_get_current_user() {
		global $concert_tracking_current_user_id;
		return $concert_tracking_current_user_id ? get_user_by( 'id', $concert_tracking_current_user_id ) : (object) array( 'ID' => 0 );
	}

	function wp_set_current_user( $user_id ) {
		global $concert_tracking_current_user_id;
		$concert_tracking_current_user_id = (int) $user_id;
		return wp_get_current_user();
	}

	function current_user_can( $capability ) {
		global $concert_tracking_current_user_id;
		return 'manage_network_options' === $capability && 1 === $concert_tracking_current_user_id;
	}

	function get_post( $post_id ) {
		global $concert_tracking_current_blog_id, $concert_tracking_posts;
		return isset( $concert_tracking_posts[ $concert_tracking_current_blog_id ][ $post_id ] ) ? (object) $concert_tracking_posts[ $concert_tracking_current_blog_id ][ $post_id ] : null;
	}

	function get_current_blog_id() {
		global $concert_tracking_current_blog_id;
		return $concert_tracking_current_blog_id;
	}

	function switch_to_blog( $blog_id ) {
		global $concert_tracking_blog_stack, $concert_tracking_current_blog_id;
		$concert_tracking_blog_stack[] = $concert_tracking_current_blog_id;
		$concert_tracking_current_blog_id = (int) $blog_id;
		return true;
	}

	function get_site( $blog_id ) {
		global $concert_tracking_sites;
		return in_array( (int) $blog_id, $concert_tracking_sites, true ) ? (object) array( 'blog_id' => (int) $blog_id ) : null;
	}

	function restore_current_blog() {
		global $concert_tracking_blog_stack, $concert_tracking_current_blog_id;
		$concert_tracking_current_blog_id = array_pop( $concert_tracking_blog_stack );
		return true;
	}

	function ec_get_blog_id( $site ) {
		global $concert_tracking_events_blog_id;
		return 'events' === $site ? $concert_tracking_events_blog_id : 1;
	}

	function wp_get_ability( $name ) {
		global $concert_tracking_abilities;
		return $concert_tracking_abilities[ $name ] ?? null;
	}

	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}

	function is_email( $value ) {
		return false !== strpos( $value, '@' );
	}

	function wp_json_encode( $value, $flags = 0 ) {
		return json_encode( $value, $flags );
	}

	function concert_tracking_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new \RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function concert_tracking_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new \RuntimeException( $message . '\nMissing: ' . $needle );
		}
	}
}

namespace WP_CLI\Utils {
	$concert_tracking_formats = array();

	function format_items( $format, $items, $fields ) {
		global $concert_tracking_formats;
		$concert_tracking_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Events/ConcertTrackingCommand.php';

	use ExtraChill\CLI\Commands\Events\ConcertTrackingCommand;

	global $concert_tracking_abilities, $concert_tracking_check_error, $concert_tracking_current_blog_id, $concert_tracking_current_user_id, $concert_tracking_events_blog_id, $concert_tracking_formats, $concert_tracking_marks, $concert_tracking_set_error;

	$set_ability = new ConcertTrackingCommandTestAbility(
		static function ( $input ) use ( &$concert_tracking_marks, &$concert_tracking_current_user_id, &$concert_tracking_set_error ) {
			if ( $concert_tracking_set_error ) {
				return $concert_tracking_set_error;
			}
			if ( $input['user_id'] !== $concert_tracking_current_user_id && 1 !== $concert_tracking_current_user_id ) {
				return new WP_Error( 'ability_invalid_permissions', 'Not authorized for target user.' );
			}
			$key      = $input['user_id'] . ':' . $input['event_id'];
			$before   = ! empty( $concert_tracking_marks[ $key ] );
			$marked   = (bool) $input['marked'];
			$changed  = $before !== $marked;
			$concert_tracking_marks[ $key ] = $marked;
			return array( 'changed' => $changed, 'marked' => $marked, 'timing' => 'past', 'count' => $marked ? 1 : 0 );
		}
	);
	$check_ability = new ConcertTrackingCommandTestAbility(
		static function ( $input ) use ( &$concert_tracking_marks, &$concert_tracking_current_user_id, &$concert_tracking_check_error ) {
			if ( $concert_tracking_check_error ) {
				return $concert_tracking_check_error;
			}
			if ( isset( $input['user_id'] ) && $input['user_id'] !== $concert_tracking_current_user_id && 1 !== $concert_tracking_current_user_id ) {
				return new WP_Error( 'ability_invalid_permissions', 'Not authorized for target user.' );
			}
			$key = ( $input['user_id'] ?? $concert_tracking_current_user_id ) . ':' . $input['event_id'];
			return array(
				'user_marked' => ! empty( $concert_tracking_marks[ $key ] ),
				'timing'      => 'past',
				'count'       => ! empty( $concert_tracking_marks[ $key ] ) ? 1 : 0,
				'count_label' => ! empty( $concert_tracking_marks[ $key ] ) ? '1 was there' : '0 were there',
				'attendees'   => array(),
			);
		}
	);
	$concert_tracking_abilities = array(
		'extrachill/set-event-mark'      => $set_ability,
		'extrachill/get-event-attendance' => $check_ability,
	);

	$command = new ConcertTrackingCommand();

	// Bare WP-CLI execution adopts the administrator as actor while retaining the explicit target.
	$command->mark( array( 10 ), array( 'user' => 'chubes' ) );
	$command->mark( array( 10 ), array( 'user' => 'chubes' ) );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Mark must restore the main-site invocation context.' );
	concert_tracking_assert_same( 1, $concert_tracking_current_user_id, 'Bare CLI execution must establish the deterministic administrator actor.' );
	concert_tracking_assert_same( true, $concert_tracking_marks['7:10'], 'Repeated marks must leave the event marked.' );
	concert_tracking_assert_same( array( 'user_id' => 7, 'event_id' => 10, 'marked' => true ), $set_ability->inputs[0], 'Mark must use the canonical desired-state contract.' );
	concert_tracking_assert_contains( 'already marked', implode( "\n", WP_CLI::$messages ), 'Repeated mark must report an unchanged state.' );

	WP_CLI::$messages = array();
	$command->unmark( array( 11 ), array( 'user' => 'chubes' ) );
	$command->unmark( array( 11 ), array( 'user' => 'chubes' ) );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Unmark must restore the main-site invocation context.' );
	concert_tracking_assert_same( false, $concert_tracking_marks['7:11'], 'Repeated unmarks must leave the event unmarked.' );
	concert_tracking_assert_contains( 'was not marked', implode( "\n", WP_CLI::$messages ), 'Repeated unmark must report an unchanged state.' );

	$writes_before = count( $set_ability->inputs );
	$command->check( array( 10 ), array( 'user' => 'chubes' ) );
	$command->check( array( 10 ), array( 'user' => 'listener' ) );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Check must restore the main-site invocation context.' );
	concert_tracking_assert_same( $writes_before, count( $set_ability->inputs ), 'Checks for any user must not execute a mutation ability.' );
	concert_tracking_assert_same( array( 'event_id' => 10, 'user_id' => 8 ), $check_ability->inputs[1], 'Check must pass the selected user explicitly.' );

	$snapshot = $concert_tracking_marks;
	WP_CLI::$messages = array();
	$command->import( array( 'chubes', '10,11' ), array( 'dry-run' => true ) );
	concert_tracking_assert_same( $snapshot, $concert_tracking_marks, 'Import dry-run must not mutate attendance.' );
	concert_tracking_assert_contains( 'Would mark 1 events for chubes. Skipped: 1. Invalid: 0.', implode( "\n", WP_CLI::$messages ), 'Dry-run counts must distinguish changed and skipped events.' );

	WP_CLI::$messages = array();
	$command->import( array( 'chubes', '10,11,11' ), array() );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Import must restore the main-site invocation context.' );
	concert_tracking_assert_same( true, $concert_tracking_marks['7:11'], 'Import must idempotently mark unmarked events.' );
	concert_tracking_assert_contains( 'Marked 1 events for chubes. Skipped: 2. Invalid: 0.', implode( "\n", WP_CLI::$messages ), 'Import counts must reflect actual changes and repeated IDs.' );

	$concert_tracking_formats = array();
	$command->event( array( 10 ), array( 'attendees' => true, 'limit' => 3 ) );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Event detail must restore the main-site invocation context.' );
	$event_input = end( $check_ability->inputs );
	concert_tracking_assert_same( array( 'event_id' => 10, 'include_attendees' => true, 'limit' => 3 ), $event_input, 'Event detail must pass the canonical limit field.' );
	concert_tracking_assert_same( 'Event ID', $concert_tracking_formats[0]['items'][0]['Field'], 'Event detail must present a field backed by the ability contract.' );

	// A colliding event ID that exists only on the invocation site must be rejected.
	$writes_before = count( $set_ability->inputs );
	try {
		$command->mark( array( 12 ), array( 'user' => 'chubes' ) );
		throw new \RuntimeException( 'Wrong-site event IDs should fail.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'canonical Events site', $error->getMessage(), 'Validation must reject invocation-site posts.' );
	}
	concert_tracking_assert_same( $writes_before, count( $set_ability->inputs ), 'Wrong-site IDs must fail before ability execution.' );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Failed validation must restore the invocation context.' );

	// Invalid rows are skippable and preserve context during import.
	WP_CLI::$messages = array();
	$command->import( array( 'chubes', '12' ), array( 'dry-run' => true ) );
	concert_tracking_assert_contains( 'Would mark 0 events for chubes. Skipped: 0. Invalid: 1.', implode( "\n", WP_CLI::$messages ), 'Invalid event rows must be counted and skipped.' );
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Skipped row validation must restore the invocation context.' );

	// Canonical site resolution failures are fatal for direct commands and imports.
	concert_tracking_assert_same( true, switch_to_blog( 99 ), 'The test double must match WordPress core behavior for nonexistent site IDs.' );
	concert_tracking_assert_same( 99, $concert_tracking_current_blog_id, 'Core switch behavior must not validate site existence.' );
	restore_current_blog();
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'The core-behavior probe must restore invocation context.' );

	$concert_tracking_events_blog_id = 99;
	try {
		$command->check( array( 10 ), array( 'user' => 'chubes' ) );
		throw new \RuntimeException( 'Unavailable canonical site should fail direct commands.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'canonical Events site is unavailable', $error->getMessage(), 'Direct commands must fail when the canonical site is unavailable.' );
	}
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Unavailable-site validation must preserve invocation context.' );

	try {
		$command->import( array( 'chubes', '10,11' ), array( 'dry-run' => true ) );
		throw new \RuntimeException( 'Unavailable canonical site should fail imports.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'canonical Events site is unavailable', $error->getMessage(), 'Import must fail instead of skipping rows when the canonical site is unavailable.' );
	}
	concert_tracking_assert_same( 1, $concert_tracking_current_blog_id, 'Failed import site resolution must preserve invocation context.' );
	$concert_tracking_events_blog_id = 7;

	// Unauthorized import failures must exit nonzero instead of reporting success.
	wp_set_current_user( 8 );
	try {
		$command->import( array( 'chubes', '10' ), array( 'dry-run' => true ) );
		throw new \RuntimeException( 'Unauthorized import should fail.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'Not authorized', $error->getMessage(), 'Unauthorized import must fail clearly.' );
	}

	// Missing dependencies and systemic ability errors must also exit nonzero.
	wp_set_current_user( 1 );
	unset( $concert_tracking_abilities['extrachill/set-event-mark'] );
	try {
		$command->import( array( 'chubes', '10' ), array() );
		throw new \RuntimeException( 'Missing dependency should fail.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'not available', $error->getMessage(), 'Missing canonical abilities must fail clearly.' );
	}
	$concert_tracking_abilities['extrachill/set-event-mark'] = $set_ability;

	$concert_tracking_set_error = new WP_Error( 'database_unavailable', 'Attendance storage unavailable.' );
	try {
		$command->import( array( 'chubes', '10' ), array() );
		throw new \RuntimeException( 'Systemic ability failure should fail.' );
	} catch ( ConcertTrackingCommandTestError $error ) {
		concert_tracking_assert_contains( 'Attendance storage unavailable', $error->getMessage(), 'Systemic failures must fail the import.' );
	}
	$concert_tracking_set_error = null;

	fwrite( STDOUT, "ConcertTrackingCommand tests passed.\n" );
}
