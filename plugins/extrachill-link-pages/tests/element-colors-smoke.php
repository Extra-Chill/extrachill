<?php
/** Standalone regression proof for independent element colors. */
require_once __DIR__ . '/bootstrap.php';

function ec_element_color_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

ec_test_reset();
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'ec_link_page', 'post_status' => 'publish' );
switch_to_blog( 4 );
$colors = array(
	'--link-page-link-text-color' => '#123456',
	'--link-page-button-hover-text-color' => '#abcdef',
	'--link-page-icon-color' => '#654321',
);
ec_element_color_check( ec_sanitize_link_page_css_vars( $colors ) === $colors, 'Element colors must sanitize independently.' );
foreach ( array_keys( $colors ) as $key ) {
	ec_element_color_check( is_wp_error( ec_sanitize_link_page_css_vars( array( $key => 'red; display:none' ) ) ), 'Unsafe color accepted: ' . $key );
}
update_post_meta( 40, '_link_page_custom_css_vars', $colors );
$data = ec_read_link_page_persistence( 40 );
ec_element_color_check( ! is_wp_error( $data ), 'Read failed.' );
foreach ( $colors as $key => $value ) {
	ec_element_color_check( $data['css_vars'][ $key ] === $value, 'Saved color was overwritten: ' . $key );
}
update_post_meta( 40, '_link_page_custom_css_vars', array( '--link-page-link-text-color' => '#112233', '--link-page-text-color' => '#445566' ) );
$data = ec_read_link_page_persistence( 40 );
ec_element_color_check( $data['css_vars']['--link-page-button-hover-text-color'] === '#112233', 'Legacy hover fallback changed.' );
ec_element_color_check( $data['css_vars']['--link-page-icon-color'] === '#445566', 'Icon fallback must follow page text.' );
$stored = get_post_meta( 40, '_link_page_custom_css_vars', true );
ec_element_color_check( ! isset( $stored['--link-page-icon-color'] ), 'Read persisted defaults.' );
restore_current_blog();
echo "Element colors smoke passed.\n";
