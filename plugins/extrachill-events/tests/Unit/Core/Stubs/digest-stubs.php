<?php
/**
 * Minimal WP polyfills for QualifyDigestAbilityTest.
 *
 * Renderers only need esc_html, plus the time constants from
 * tests/bootstrap.php. The ability's execute() path uses additional WP
 * functions (get_option, get_site_option, do_action, wp_get_ability) —
 * those are not exercised by these renderer-only tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {}
}

if ( ! function_exists( 'doing_action' ) ) {
	function doing_action( string $hook ): bool {
		return false;
	}
}

if ( ! function_exists( 'did_action' ) ) {
	function did_action( string $hook ): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_timezone' ) ) {
	/**
	 * Return the timezone configured by the current digest test.
	 */
	function wp_timezone(): \DateTimeZone {
		return new \DateTimeZone( $GLOBALS['ec_digest_timezone'] ?? 'UTC' );
	}
}

if ( ! function_exists( 'wp_date' ) ) {
	/**
	 * Format a timestamp in the supplied WordPress site timezone.
	 *
	 * @param string             $format    Date format.
	 * @param int|null           $timestamp Unix timestamp.
	 * @param \DateTimeZone|null $timezone  Output timezone.
	 * @return string Formatted local date.
	 */
	function wp_date( string $format, ?int $timestamp = null, ?\DateTimeZone $timezone = null ): string {
		$date = new \DateTimeImmutable( '@' . ( $timestamp ?? time() ) );
		return $date->setTimezone( $timezone ?? wp_timezone() )->format( $format );
	}
}
