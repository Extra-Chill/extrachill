<?php
/** Standalone main-site page ad rule contract: php tests/ads-page-optin.php */
// phpcs:disable -- Test doubles and WordPress stubs.
define( 'ABSPATH', __DIR__ );
$GLOBALS['optin'] = null;
function add_filter( ...$args ) {}
function apply_filters( $hook, $value, ...$args ) {
	return ( 'extrachill_page_allows_ads' === $hook && $GLOBALS['optin'] ) ? ( $GLOBALS['optin'] )( $value, ...$args ) : $value;
}
function check( $condition, $message ) {
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { exit( 1 ); }
}
require dirname( __DIR__ ) . '/inc/core/ads-filter.php';
$page = array( 'blog_id' => 1, 'is_page' => true, 'post_id' => 10 );
check( true === extrachill_blog_should_block_ads( false, $page ), 'pages are blocked by default' );
check( true === extrachill_blog_should_block_ads( false, array( 'blog_id' => 1, 'is_page' => true ) ), 'pages without post_id stay blocked' );
$GLOBALS['optin'] = static fn( $allows, $post_id ) => 10 === $post_id;
check( false === extrachill_blog_should_block_ads( false, $page ), 'opted-in page serves ads' );
check( true === extrachill_blog_should_block_ads( false, array( 'blog_id' => 1, 'is_page' => true, 'post_id' => 11 ) ), 'other pages stay blocked' );
check( true === extrachill_blog_should_block_ads( false, array( 'blog_id' => 1, 'is_front_page' => true, 'is_page' => true, 'post_id' => 10 ) ), 'front page stays blocked' );
check( true === extrachill_blog_should_block_ads( true, $page ), 'upstream blocks are preserved' );
check( false === extrachill_blog_should_block_ads( false, array( 'blog_id' => 1, 'is_singular' => true, 'post_id' => 5 ) ), 'posts are unchanged' );
echo "PASS: page ad opt-in smoke complete\n";
