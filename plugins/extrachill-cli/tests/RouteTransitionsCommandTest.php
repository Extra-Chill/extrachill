<?php
/**
 * Focused contract, machine-output, and presenter tests for route transitions.
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

	class RouteTransitionsCommandTestAbility {
		public $inputs = array();
		public $result;

		public function __construct( $result ) {
			$this->result = $result;
		}

		public function execute( $input ) {
			$this->inputs[] = $input;
			return $this->result;
		}
	}

	$route_transitions_command_test_result = array(
		'counts'      => array(
			'sessions'                  => 1234,
			'first_time_sessions'       => 700,
			'returning_sessions'        => 534,
			'direct_terminal_sessions'  => 400,
			'transitions'               => 2000,
			'same_surface_transitions'  => 1500,
			'cross_surface_transitions' => 500,
			'sequence_windows'          => 900,
		),
		'transitions' => array(
			array(
				'from'         => array( 'blog_id' => 1, 'route_family' => 'singular' ),
				'to'           => array( 'blog_id' => 7, 'route_family' => 'home' ),
				'count'        => 1234,
				'same_surface' => false,
			),
		),
		'sequences'   => array(
			array(
				'path'  => array(
					array( 'blog_id' => 1, 'route_family' => 'singular' ),
					array( 'blog_id' => 7, 'route_family' => 'home' ),
					array( 'blog_id' => 7, 'route_family' => 'singular' ),
				),
				'count' => 321,
			),
		),
		'entries'     => array(
			array( 'route' => array( 'blog_id' => 1, 'route_family' => 'singular' ), 'count' => 1000 ),
		),
		'terminals'   => array(
			array( 'route' => array( 'blog_id' => 7, 'route_family' => 'singular' ), 'count' => 800 ),
		),
		'definitions' => array( 'transition' => 'Every adjacent pair.' ),
		'coverage'    => array(
			'total_pageviews'                   => 5000,
			'identified_pageviews'              => 4000,
			'anonymous_pageviews'               => 1000,
			'identity_coverage_rate'            => 0.8,
			'explicit_route_family_pageviews'   => 3500,
			'inferred_singular_pageviews'       => 1000,
			'historical_unclassified_pageviews' => 500,
			'loaded_identified_pageviews'       => 4000,
			'truncated'                         => false,
			'definition'                        => 'Identity and route classification coverage.',
		),
		'bounds'      => array(
			'days'             => 28,
			'blog_id'          => 0,
			'session_gap_mins' => 30,
			'sequence_length'  => 3,
			'cohort'           => 'all',
			'limit'            => 25,
			'max_pageviews'    => 10000,
		),
		'period'      => array(
			'since'         => '2026-06-19 00:00:00',
			'ranking_since' => '2026-06-19 00:00:00',
			'until'         => '2026-07-17 00:00:00',
			'as_of'         => '2026-07-17 00:00:00',
		),
		'note'          => 'Deterministic and bot-filtered by the existing pageview writer.',
		'future_metric' => array( 'count' => 7, 'rate' => 0.5 ),
	);
	$route_transitions_command_test_ability = new RouteTransitionsCommandTestAbility( $route_transitions_command_test_result );

	function wp_get_ability( $name ) {
		global $route_transitions_command_test_ability;
		return 'extrachill/get-route-transitions' === $name ? $route_transitions_command_test_ability : null;
	}

	function is_wp_error( $value ) {
		return false;
	}

	function wp_json_encode( $value ) {
		return json_encode( $value );
	}

	function route_transitions_command_test_assert_same( $expected, $actual, $message ) {
		if ( $expected !== $actual ) {
			throw new RuntimeException( $message . '\nExpected: ' . var_export( $expected, true ) . '\nActual: ' . var_export( $actual, true ) );
		}
	}

	function route_transitions_command_test_assert_contains( $needle, $haystack, $message ) {
		if ( false === strpos( $haystack, $needle ) ) {
			throw new RuntimeException( $message . '\nMissing: ' . $needle );
		}
	}
}

namespace WP_CLI\Utils {
	$route_transitions_command_test_formats = array();

	function format_items( $format, $items, $fields ) {
		global $route_transitions_command_test_formats;
		$route_transitions_command_test_formats[] = compact( 'format', 'items', 'fields' );
	}
}

namespace {
	require_once dirname( __DIR__ ) . '/inc/Commands/Analytics/RouteTransitionsCommand.php';

	use ExtraChill\CLI\Commands\Analytics\RouteTransitionsCommand;

	global $route_transitions_command_test_ability, $route_transitions_command_test_formats, $route_transitions_command_test_result;

	$command = new RouteTransitionsCommand();
	$command(
		array(),
		array(
			'days'             => '90',
			'blog-id'          => '7',
			'session-gap-mins' => '120',
			'sequence-length'  => '5',
			'cohort'           => 'returning',
			'limit'            => '100',
			'max-pageviews'    => '25000',
			'format'           => 'json',
		)
	);
	route_transitions_command_test_assert_same(
		array(
			'days'             => 90,
			'blog_id'          => 7,
			'session_gap_mins' => 120,
			'sequence_length'  => 5,
			'cohort'           => 'returning',
			'limit'            => 100,
			'max_pageviews'    => 25000,
		),
		$route_transitions_command_test_ability->inputs[0],
		'Every bounded CLI filter must map exactly to the owning ability contract.'
	);
	route_transitions_command_test_assert_same( $route_transitions_command_test_result, WP_CLI::$printed[0]['value'], 'JSON must preserve the complete typed ability envelope.' );
	route_transitions_command_test_assert_same( 1234, WP_CLI::$printed[0]['value']['transitions'][0]['count'], 'JSON counts must remain integers.' );
	route_transitions_command_test_assert_same( false, WP_CLI::$printed[0]['value']['transitions'][0]['same_surface'], 'JSON booleans must remain typed.' );
	route_transitions_command_test_assert_same( $route_transitions_command_test_result['future_metric'], WP_CLI::$printed[0]['value']['future_metric'], 'JSON must retain future ability fields.' );

	$command( array(), array( 'format' => 'csv' ) );
	$csv = $route_transitions_command_test_formats[0];
	route_transitions_command_test_assert_same( array_keys( $route_transitions_command_test_result ), $csv['fields'], 'CSV must retain every top-level ability field.' );
	route_transitions_command_test_assert_same( 1234, json_decode( $csv['items'][0]['transitions'], true )[0]['count'], 'CSV nested counts must remain numeric JSON values.' );
	route_transitions_command_test_assert_same( 0.8, json_decode( $csv['items'][0]['coverage'], true )['identity_coverage_rate'], 'CSV rates must not use display-formatted strings.' );
	route_transitions_command_test_assert_same( $route_transitions_command_test_result['future_metric'], json_decode( $csv['items'][0]['future_metric'], true ), 'CSV must retain future ability fields.' );
	route_transitions_command_test_assert_same(
		array(
			'days'             => 28,
			'blog_id'          => 0,
			'session_gap_mins' => 30,
			'sequence_length'  => 3,
			'cohort'           => 'all',
			'limit'            => 25,
			'max_pageviews'    => 10000,
		),
		$route_transitions_command_test_ability->inputs[1],
		'Default arguments must match the ability contract.'
	);

	$command( array(), array() );
	$coverage  = $route_transitions_command_test_formats[1];
	$transitions = $route_transitions_command_test_formats[2];
	$entries     = $route_transitions_command_test_formats[3];
	$terminals   = $route_transitions_command_test_formats[4];
	$sequences   = $route_transitions_command_test_formats[5];
	route_transitions_command_test_assert_same( array( 'classification', 'pageviews' ), $coverage['fields'], 'Human output must table route collection coverage.' );
	route_transitions_command_test_assert_same( '3,500', $coverage['items'][0]['pageviews'], 'Human coverage counts must be display-formatted.' );
	route_transitions_command_test_assert_same( array( 'from_blog', 'from_route', 'to_blog', 'to_route', 'surface', 'count' ), $transitions['fields'], 'Transition table must expose complete route identity.' );
	route_transitions_command_test_assert_same( 'cross', $transitions['items'][0]['surface'], 'Transition table must distinguish cross-surface movement.' );
	route_transitions_command_test_assert_same( array( 'blog_id', 'route_family', 'count' ), $entries['fields'], 'Entry table must expose route identity.' );
	route_transitions_command_test_assert_same( array( 'blog_id', 'route_family', 'count' ), $terminals['fields'], 'Terminal table must expose route identity.' );
	route_transitions_command_test_assert_same( '1:singular -> 7:home -> 7:singular', $sequences['items'][0]['path'], 'Sequence table must preserve route order and surface identity.' );
	$messages = implode( "\n", WP_CLI::$messages );
	route_transitions_command_test_assert_contains( 'First-party identity and session coverage:', $messages, 'Human output must identify first-party coverage.' );
	route_transitions_command_test_assert_contains( 'identity coverage 80.0%', $messages, 'Human output must disclose identity coverage.' );
	route_transitions_command_test_assert_contains( 'Sessions: 1,234 included; 700 first-time; 534 returning', $messages, 'Human output must disclose session cohort coverage.' );

	$route_transitions_command_test_ability->result['transitions'] = array();
	$route_transitions_command_test_ability->result['entries']     = array();
	$route_transitions_command_test_ability->result['terminals']   = array();
	$route_transitions_command_test_ability->result['sequences']   = array();
	$route_transitions_command_test_ability->result['coverage']['total_pageviews']         = 0;
	$route_transitions_command_test_ability->result['coverage']['identified_pageviews']    = 0;
	$route_transitions_command_test_ability->result['coverage']['anonymous_pageviews']     = 0;
	$route_transitions_command_test_ability->result['coverage']['identity_coverage_rate']  = 0.0;
	$before = count( $route_transitions_command_test_formats );
	$command( array(), array() );
	route_transitions_command_test_assert_same( $before + 1, count( $route_transitions_command_test_formats ), 'Empty rankings must not emit misleading empty tables.' );
	$empty_messages = implode( "\n", WP_CLI::$messages );
	route_transitions_command_test_assert_contains( 'identity coverage n/a (no pageviews in window)', $empty_messages, 'Zero-data identity coverage must be unavailable rather than 0%.' );
	route_transitions_command_test_assert_contains( '(no same-session transitions available for this scope)', $empty_messages, 'Transitions need an honest empty state.' );
	route_transitions_command_test_assert_contains( '(no session entries available for this scope)', $empty_messages, 'Entries need an honest empty state.' );
	route_transitions_command_test_assert_contains( '(no session terminals available for this scope)', $empty_messages, 'Terminals need an honest empty state.' );
	route_transitions_command_test_assert_contains( '(no complete ordered sequences available for this scope and sequence length)', $empty_messages, 'Sequences need an honest empty state.' );

	fwrite( STDOUT, "RouteTransitionsCommand tests passed.\n" );
}
