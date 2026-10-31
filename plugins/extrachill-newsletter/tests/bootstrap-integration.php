<?php
/**
 * WordPress integration bootstrap for the delegated campaign contract.
 *
 * @package ExtraChillNewsletter\Tests
 */

$tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
$dm_path   = getenv( 'DATAMACHINE_PATH' );
if ( ! is_string( $dm_path ) || ! is_file( $dm_path . '/data-machine.php' ) ) {
	fwrite( STDERR, "DATAMACHINE_PATH must point to a Data Machine checkout.\n" );
	exit( 1 );
}
if ( ! is_file( $tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found at {$tests_dir}.\n" );
	exit( 1 );
}

require_once $tests_dir . '/includes/functions.php';

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $key ) {
		return (int) ( $GLOBALS['newsletter_integration_blogs'][ $key ] ?? 0 );
	}
}
if ( ! defined( 'UPLOADBLOGSDIR' ) ) {
	define( 'UPLOADBLOGSDIR', 'wp-content/blogs.dir' );
}
if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url( $key ) {
		$blog_id = ec_get_blog_id( $key );
		return $blog_id ? get_site_url( $blog_id ) : '';
	}
}

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $dm_path ) {
		require_once $dm_path . '/data-machine.php';
		require_once dirname( __DIR__ ) . '/extrachill-newsletter.php';
	}
);

require $tests_dir . '/includes/bootstrap.php';
