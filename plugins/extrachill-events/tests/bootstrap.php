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

// --- Minimal WP polyfills (only what qualify v2 core code touches). ---

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return -1 === $component ? parse_url( $url ) : parse_url( $url, $component );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		return $value;
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return is_string( $url ) ? trim( $url ) : '';
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
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

// Load the units under test.
require_once dirname( __DIR__ ) . '/inc/Core/QualifyVerdict.php';
require_once dirname( __DIR__ ) . '/inc/Core/QualifyVerdictResolver.php';
require_once dirname( __DIR__ ) . '/inc/Core/PlatformDetector.php';
require_once dirname( __DIR__ ) . '/inc/Core/QualifyFingerprinter.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueQualificationAbilities.php';
