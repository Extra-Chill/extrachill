<?php
/**
 * Integration fixtures preloaded by the managed WP Codebox PHPUnit bootstrap.
 *
 * WordPress and validation dependencies are loaded by the runner, not here.
 *
 * @package ExtraChillNewsletter\Tests
 */

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
