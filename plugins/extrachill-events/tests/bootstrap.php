<?php
/**
 * PHPUnit bootstrap for plain (non-WP-core) unit tests.
 *
 * The repo's existing tests (e.g. EventSubmissionAbilitiesTest) extend
 * WP_UnitTestCase and require the upstream WordPress test framework to be
 * available. That suite is currently blocked by the DM-core bootstrap fallout
 * (see PR description). Pure-unit tests added in qualify v2 do NOT need the WP
 * test framework — they only require a handful of WP helpers stubbed in.
 *
 * Run with:
 *   ./vendor/bin/phpunit --testsuite=qualify-v2-unit
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/' );
}

// WordPress time constants used by qualify v2 verdict configuration.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 7 * DAY_IN_SECONDS );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}

// --- Minimal WP polyfills (only what qualify v2 core code touches). ---

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	$GLOBALS['ec_test_filters'] = array();

	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['ec_test_filters'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		add_filter( $hook, $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		if ( empty( $GLOBALS['ec_test_filters'][ $hook ][ $priority ] ) ) {
			return false;
		}

		foreach ( $GLOBALS['ec_test_filters'][ $hook ][ $priority ] as $index => $registered ) {
			if ( $registered[0] === $callback ) {
				unset( $GLOBALS['ec_test_filters'][ $hook ][ $priority ][ $index ] );
				return true;
			}
		}

		return false;
	}
}

if ( ! function_exists( 'remove_action' ) ) {
	function remove_action( $hook, $callback, $priority = 10 ) {
		return remove_filter( $hook, $callback, $priority );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		if ( empty( $GLOBALS['ec_test_filters'][ $hook ] ) ) {
			return $value;
		}

		ksort( $GLOBALS['ec_test_filters'][ $hook ] );
		foreach ( $GLOBALS['ec_test_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$value = call_user_func_array( $callback[0], array_slice( array_merge( array( $value ), $args ), 0, $callback[1] ) );
			}
		}

		return $value;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	define( 'EC_TEST_DO_ACTION_RECORDS_FIXTURES', true );

	function do_action( $hook, ...$args ) {
		if ( isset( $GLOBALS['ec_artist_test']['fired_actions'] ) && is_array( $GLOBALS['ec_artist_test']['fired_actions'] ) ) {
			$GLOBALS['ec_artist_test']['fired_actions'][ $hook ][] = $args;
		}
		if ( isset( $GLOBALS['venue_membership_test']['fired_actions'] ) && is_array( $GLOBALS['venue_membership_test']['fired_actions'] ) ) {
			$GLOBALS['venue_membership_test']['fired_actions'][ $hook ][] = $args;
		}

		if ( empty( $GLOBALS['ec_test_filters'][ $hook ] ) ) {
			return;
		}

		ksort( $GLOBALS['ec_test_filters'][ $hook ] );
		foreach ( $GLOBALS['ec_test_filters'][ $hook ] as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
			}
		}
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		$resolver = $GLOBALS['ec_test_ability_resolver'] ?? null;
		$ability  = is_callable( $resolver ) ? $resolver( $name ) : null;

		return $ability
			?? ( $GLOBALS['ec_artist_test']['ability_objects'][ $name ] ?? null )
			?? ( $GLOBALS['venue_membership_test']['ability_objects'][ $name ] ?? null );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return is_string( $url ) ? trim( $url ) : '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		if ( ! is_string( $str ) ) {
			return '';
		}

		$str = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $str );
		return trim( strip_tags( $str ) );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}

if ( ! function_exists( 'mb_substr' ) && function_exists( 'substr' ) ) {
	// PHP 8.4 with mbstring should always have mb_substr, but guard anyway.
	function mb_substr( $string, $start, $length = null ) {
		return null === $length ? substr( $string, $start ) : substr( $string, $start, $length );
	}
}

/** Restores mutable plain-test globals after every test. */
abstract class BookingTestCase extends PHPUnit\Framework\TestCase {
	private $booking_test_globals;
	private $booking_test_document_root_exists;
	private $booking_test_document_root;

	public function runBare(): void {
		$keys                       = array( 'wpdb', 'ec_artist_test', 'venue_membership_test', 'ec_test_ability_resolver', 'ec_test_filters', 'extrachill_events_booking_reference_lock_uncertainty', 'extrachill_events_booking_database_connection_quarantined' );
		$this->booking_test_globals = array();
		foreach ( $keys as $key ) {
			$this->booking_test_globals[ $key ] = array(
				'exists' => array_key_exists( $key, $GLOBALS ),
				'value'  => $GLOBALS[ $key ] ?? null,
			);
		}
		$this->booking_test_document_root_exists = array_key_exists( 'DOCUMENT_ROOT', $_SERVER );
		$this->booking_test_document_root        = $_SERVER['DOCUMENT_ROOT'] ?? null;

		try {
			parent::runBare();
		} finally {
			foreach ( $this->booking_test_globals as $key => $snapshot ) {
				if ( $snapshot['exists'] ) {
					$GLOBALS[ $key ] = $snapshot['value'];
				} else {
					unset( $GLOBALS[ $key ] );
				}
			}
			if ( $this->booking_test_document_root_exists ) {
				$_SERVER['DOCUMENT_ROOT'] = $this->booking_test_document_root;
			} else {
				unset( $_SERVER['DOCUMENT_ROOT'] );
			}
		}
	}
}

// Load the units under test.
require_once dirname( __DIR__ ) . '/inc/Core/QualifyVerdict.php';
require_once dirname( __DIR__ ) . '/inc/Core/QualifyCohortDeriver.php';
require_once dirname( __DIR__ ) . '/inc/Core/QualifyVerdictResolver.php';
require_once dirname( __DIR__ ) . '/inc/Core/PlatformDetector.php';
require_once dirname( __DIR__ ) . '/inc/Core/QualifyFingerprinter.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueQualificationAbilities.php';

// Managed multisite tests exercise the production network's Events blog ID.
if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_site' ) && function_exists( 'wpmu_create_blog' ) && ! get_site( 7 ) ) {
	while ( ! get_site( 7 ) ) {
		$next_id = get_sites( array( 'count' => true ) ) + 1;
		$created = wpmu_create_blog( 'site-' . $next_id . '.example.org', '/', 'Test Site ' . $next_id, 1 );
		if ( is_wp_error( $created ) || (int) $created > 7 ) {
			throw new RuntimeException( 'Unable to provision the Events multisite test fixture.' );
		}
	}
}
