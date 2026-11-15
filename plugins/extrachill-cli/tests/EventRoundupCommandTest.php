<?php
/**
 * Contract tests for `wp extrachill events roundup` photo backgrounds and
 * share metadata, which moved here from the duplicate extrachill-events
 * command (#1426).
 *
 * Run standalone: php tests/EventRoundupCommandTest.php
 */

namespace WP_CLI\Utils {
	if ( ! function_exists( 'WP_CLI\\Utils\\get_flag_value' ) ) {
		function get_flag_value( $assoc_args, $flag, $default = null ) {
			return $assoc_args[ $flag ] ?? $default;
		}
	}
}

namespace {
	// The doubles would collide with a real WordPress bootstrap.
	$event_roundup_wp_bootstrapped = function_exists( 'wp_get_ability' );

	if ( ! $event_roundup_wp_bootstrapped ) {
		define( 'ABSPATH', __DIR__ . '/' );

		class EventRoundupTestError extends \RuntimeException {}

		class WP_CLI {
			public static $printed = array();
			public static function error( $message ) { throw new EventRoundupTestError( $message ); }
			public static function log( $message ) {}
			public static function success( $message ) {}
			public static function warning( $message ) {}
			public static function print_value( $value, $args = array() ) { self::$printed[] = $value; }
		}

		class EventRoundupTestAbility {
			public $inputs = array();
			public $result = array();
			public function execute( $input ) { $this->inputs[] = $input; return $this->result; }
		}

		$GLOBALS['ert_abilities'] = array();

		function wp_get_ability( $slug ) { return $GLOBALS['ert_abilities'][ $slug ] ?? null; }
		function is_wp_error( $value ) { return false; }
		function ec_get_blog_id( $key ) { return 'events' === $key ? 7 : 0; }
		function ec_get_site_url( $key ) { return 'https://' . $key . '.extrachill.com'; }
		function get_current_blog_id() { return 7; }
		function wp_timezone() { return new DateTimeZone( 'UTC' ); }

		require_once dirname( __DIR__ ) . '/inc/OwnerSiteAbility.php';
		require_once dirname( __DIR__ ) . '/inc/Commands/Events/LocationCommand.php';

		$failures = 0;
		$assert   = static function ( $condition, $message ) use ( &$failures ) {
			if ( ! $condition ) {
				++$failures;
				fwrite( STDERR, "FAIL: {$message}\n" );
			}
		};

		$ability                  = new EventRoundupTestAbility();
		$GLOBALS['ert_abilities'] = array( 'extrachill/event-roundup-build' => $ability );
		$slides                   = array(
			array(
				'index'     => 0,
				'user_tags' => array( array( 'username' => 'venue' ) ),
			),
		);
		$ability->result = array(
			'success'       => true,
			'image_paths'   => array( '/tmp/slide-1.png' ),
			'slide_count'   => 1,
			'slides'        => $slides,
			'date_start'    => '2026-10-30',
			'date_end'      => '2026-11-01',
			'location_name' => 'Charleston',
			'total_events'  => 4,
			'event_summary' => '',
			'message'       => '',
		);

		( new \ExtraChill\CLI\Commands\Events\LocationCommand() )->roundup(
			array(),
			array(
				'date-start' => '2026-10-30',
				'date-end'   => '2026-11-01',
				'location'   => 'charleston',
				'event-type' => 'concert,dj-set',
				'background' => 'auto',
				'cover'      => true,
				'format'     => 'json',
			)
		);

		$input = $ability->inputs[0] ?? array();
		$assert( 'auto' === ( $input['background'] ?? null ), 'roundup forwards --background' );
		$assert( array( 'event_type' => array( 'concert', 'dj-set' ) ) === ( $input['tax_filters'] ?? null ), 'roundup forwards --event-type as a tax filter' );
		$assert( true === ( $input['cover'] ?? null ), 'roundup forwards --cover' );
		$assert( $slides === ( WP_CLI::$printed[0]['slides'] ?? null ), 'JSON output keeps per-slide share metadata' );

		( new \ExtraChill\CLI\Commands\Events\LocationCommand() )->roundup( array(), array( 'format' => 'json' ) );
		$assert( ! array_key_exists( 'background', $ability->inputs[1] ?? array() ), 'background is omitted unless supplied' );

		if ( $failures ) {
			fwrite( STDERR, "{$failures} failure(s)\n" );
			exit( 1 );
		}

		echo "EventRoundupCommandTest: OK\n";
	}
}
