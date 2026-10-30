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

// Gate off (default): the pre-cutover behavior keeps Link Page storage on the artist blog.
$assert( ! ec_link_pages_site_cutover_enabled(), 'The Link Pages site cutover must default to off.' );
$assert( 4 === apply_filters( 'ec_link_page_storage_blog_id', 0 ), 'With the gate off, Link Page storage must stay on the artist blog.' );

// Gate on: storage resolves to the dedicated Link Pages blog.
$GLOBALS['ec_test_site_options']['ec_link_pages_site_cutover'] = '1';
$assert( ec_link_pages_site_cutover_enabled(), 'The cutover gate must read the network option as enabled.' );
$assert( 13 === apply_filters( 'ec_link_page_storage_blog_id', 0 ), 'With the gate on, Link Page storage must resolve to the dedicated blog.' );

// Rollback: string zero from `wp site option update` must disable the gate again.
$GLOBALS['ec_test_site_options']['ec_link_pages_site_cutover'] = '0';
$assert( ! ec_link_pages_site_cutover_enabled(), 'A string zero cutover option must read as disabled.' );
$assert( 4 === apply_filters( 'ec_link_page_storage_blog_id', 0 ), 'Rolling the gate back must restore artist-blog storage.' );

fwrite( STDOUT, "Blog ID map checks passed.\n" );
