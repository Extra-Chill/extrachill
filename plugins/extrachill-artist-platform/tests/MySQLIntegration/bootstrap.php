<?php
/** Bootstrap real WordPress multisite for binding integration tests. */

$wordpress_path = getenv( 'WP_INTEGRATION_PATH' );
if ( ! is_string( $wordpress_path ) || '' === $wordpress_path || ! file_exists( $wordpress_path . '/wp-load.php' ) ) {
	throw new RuntimeException( 'WP_INTEGRATION_PATH must reference an installed WordPress multisite.' );
}

require_once $wordpress_path . '/wp-load.php';

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Artist binding integration requires WordPress multisite.' );
}

if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return 'main' === $site ? 1 : ( 'artist' === $site ? 4 : 0 );
	}
}

switch_to_blog( 1 );
register_taxonomy( 'artist', 'post', array( 'public' => true ) );
restore_current_blog();
switch_to_blog( 4 );
register_post_type( 'artist_profile', array( 'public' => true ) );
restore_current_blog();

$binding_source = getenv( 'ARTIST_BINDING_SOURCE' );
if ( ! is_string( $binding_source ) || ! file_exists( $binding_source ) ) {
	throw new RuntimeException( 'ARTIST_BINDING_SOURCE must reference the production binding file.' );
}
require_once $binding_source;
