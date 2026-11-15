<?php

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ec_test_site_options'] = array();

function get_site_option( $key, $default = false ) {
	return $GLOBALS['ec_test_site_options'][ $key ] ?? $default;
}

function add_filter( $hook, $callback ) {
	$GLOBALS['ec_blog_ids_test_filters'][ $hook ] = $callback;
}

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['ec_blog_ids_test_filters'][ $hook ] ) ) {
		return $GLOBALS['ec_blog_ids_test_filters'][ $hook ]( $value, ...$args );
	}
	return $value;
}

$GLOBALS['ec_blog_ids_test_blog_stack'] = array( 1 );
$GLOBALS['ec_blog_ids_test_switches']   = 0;

function get_current_blog_id() {
	return end( $GLOBALS['ec_blog_ids_test_blog_stack'] );
}

function switch_to_blog( $blog_id ) {
	$GLOBALS['ec_blog_ids_test_blog_stack'][] = (int) $blog_id;
	++$GLOBALS['ec_blog_ids_test_switches'];
	return true;
}

function restore_current_blog() {
	array_pop( $GLOBALS['ec_blog_ids_test_blog_stack'] );
	return true;
}

require_once dirname( __DIR__ ) . '/inc/core/blog-ids.php';

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
};

$assert( 13 === ec_get_blog_id( 'link_pages' ), 'Link Pages must resolve to its dedicated blog.' );
$assert( 13 === ec_get_domain_map()['extrachill.link'], 'The apex Link Pages domain must resolve to its dedicated blog.' );
$assert( 'https://extrachill.link' === ec_get_site_url( 'link_pages' ), 'The canonical Link Pages site URL must use the apex domain.' );

// Link Page storage always resolves to the dedicated Link Pages blog.
$assert( 13 === apply_filters( 'ec_link_page_storage_blog_id', 0 ), 'Link Page storage must resolve to the dedicated blog.' );

// ec_with_blog(): runs on the target blog and returns the callback result.
$seen = ec_with_blog(
	7,
	static function () {
		return get_current_blog_id();
	}
);
$assert( 7 === $seen, 'ec_with_blog() must run the callback on the target blog.' );
$assert( 1 === get_current_blog_id(), 'ec_with_blog() must restore the caller blog.' );
$assert( 1 === $GLOBALS['ec_blog_ids_test_switches'], 'ec_with_blog() must switch exactly once for a foreign blog.' );

// No switch when already on the target blog (or for a non-positive ID).
$GLOBALS['ec_blog_ids_test_switches'] = 0;
$assert( 'same' === ec_with_blog( 1, static fn() => 'same' ), 'ec_with_blog() must return the callback result on the current blog.' );
$assert( 'none' === ec_with_blog( 0, static fn() => 'none' ), 'ec_with_blog() must run the callback in place for a non-positive ID.' );
$assert( 0 === $GLOBALS['ec_blog_ids_test_switches'], 'ec_with_blog() must not switch when already on the target blog.' );

// Restores the caller blog even when the callback throws.
$caught = null;
try {
	ec_with_blog(
		4,
		static function () {
			throw new RuntimeException( 'boom' );
		}
	);
} catch ( RuntimeException $e ) {
	$caught = $e->getMessage();
}
$assert( 'boom' === $caught, 'ec_with_blog() must rethrow callback exceptions.' );
$assert( 1 === get_current_blog_id(), 'ec_with_blog() must restore the caller blog when the callback throws.' );
$assert( array( 1 ) === $GLOBALS['ec_blog_ids_test_blog_stack'], 'ec_with_blog() must leave no switch on the stack.' );

fwrite( STDOUT, "Blog ID map checks passed.\n" );
