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
