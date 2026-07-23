<?php

if ( ! is_multisite() ) {
	throw new RuntimeException( 'Auth fuzz requires multisite.' );
}
$host = wp_parse_url( network_home_url( '/' ), PHP_URL_HOST );
$sites = array( 'main' => get_main_site_id() );
foreach ( array( 'community' => 2, 'shop' => 3, 'artist' => 4, 'placeholder-five' => 5, 'placeholder-six' => 6, 'events' => 7 ) as $key => $expected ) {
	$path = '/' . $key . '/';
	$existing = get_sites( array( 'domain' => $host, 'path' => $path, 'number' => 1 ) );
	$site_id = $existing ? (int) $existing[0]->blog_id : wpmu_create_blog( $host, $path, 'Auth Fuzz ' . ucfirst( $key ), 1 );
	if ( is_wp_error( $site_id ) || (int) $site_id !== $expected ) {
		throw new RuntimeException( sprintf( 'Expected %s blog ID %d.', $key, $expected ) );
	}
	if ( in_array( $key, array( 'community', 'artist', 'events' ), true ) ) {
		$sites[ $key ] = (int) $site_id;
	}
}
update_site_option( 'extrachill_auth_fuzz_sites', $sites );
