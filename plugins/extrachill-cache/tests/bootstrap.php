<?php
/**
 * Minimal WordPress stubs for filesystem-only PHPUnit tests.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone harness stubs only the APIs used by GC.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EXTRACHILL_CACHE_TTL', 3600 );
define( 'EXTRACHILL_CACHE_MAX_BYTES', 1024 );
define( 'EXTRACHILL_CACHE_DIR', sys_get_temp_dir() . '/extrachill-cache-phpunit-' . bin2hex( random_bytes( 8 ) ) );

function add_action( $hook, $callback ) {}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

function apply_filters( $hook, $default, $blog_id ) {
	return $GLOBALS['ec_test_budgets'][ $blog_id ] ?? $default;
}

function get_option( $name, $default = false ) {
	return $GLOBALS['ec_test_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['ec_test_options'][ $name ] = $value;
}

function delete_option( $name ) {
	unset( $GLOBALS['ec_test_options'][ $name ] );
}

require dirname( __DIR__ ) . '/inc/cache-store.php';
require dirname( __DIR__ ) . '/inc/gc.php';
