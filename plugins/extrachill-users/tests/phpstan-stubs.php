<?php
/**
 * Dev-only PHPStan/Homeboy stubs for cross-plugin functions.
 *
 * These functions are provided by other Extra Chill plugins or the theme at
 * runtime. They are declared here only so isolated static analysis can reason
 * about this plugin without the full network loaded.
 */

if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url( string $site ): string {
		return '';
	}
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( string $site ): int {
		return 0;
	}
}

if ( ! function_exists( 'extrachill_set_notice' ) ) {
	function extrachill_set_notice( string $message, string $type = 'info' ): void {
	}
}

if ( ! function_exists( 'ec_render_turnstile_widget' ) ) {
	function ec_render_turnstile_widget(): string {
		return '';
	}
}

if ( ! function_exists( 'ec_verify_turnstile_response' ) ) {
	function ec_verify_turnstile_response( string $token ): bool {
		return true;
	}
}

if ( ! function_exists( 'ec_icon' ) ) {
	function ec_icon( string $name, string $class = '' ): string {
		return '';
	}
}
