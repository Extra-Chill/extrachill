<?php
/** Standalone regression proof for button styles and featured links. */
require_once __DIR__ . '/bootstrap.php';
ec_test_reset();
function button_styles_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
$defaults = ec_link_page_defaults_for( 'styles' );
button_styles_check( 'solid' === $defaults['--link-page-button-fill'] && 'none' === $defaults['--link-page-button-shadow'], 'Legacy defaults changed' );
foreach ( array( 'solid', 'gradient' ) as $fill ) {
	foreach ( array( 'none', 'soft', 'glow' ) as $shadow ) {
		$vars = array( '--link-page-button-fill' => $fill, '--link-page-button-shadow' => $shadow );
		button_styles_check( $vars === ec_sanitize_link_page_css_vars( $vars ), 'Valid style rejected' );
	}
}
foreach ( array( 'flat', 'url(https://example.com)', 'gradient; color:red' ) as $invalid ) {
	button_styles_check( is_wp_error( ec_sanitize_link_page_css_vars( array( '--link-page-button-fill' => $invalid ) ) ), 'Unsafe/invalid fill accepted' );
}
foreach ( array( 'javascript:alert(1)', 'data:image/png;base64,a', '//example.com/a', 'ftp://example.com/a', array() ) as $url ) {
	button_styles_check( '' === ec_link_page_thumbnail_url( $url ), 'Unsafe thumbnail accepted' );
}
$link = array( 'link_text' => 'Example', 'link_url' => 'https://example.com', 'featured' => true, 'thumbnail_url' => 'https://example.com/thumb.jpg' );
$sections = array( array( 'section_title' => 'One', 'links' => array( $link ) ), array( 'section_title' => 'Two', 'links' => array( $link ) ) );
$clean = ec_sanitize_link_page_links( $sections );
button_styles_check( ! is_wp_error( $clean ), 'Links rejected' );
button_styles_check( true === $clean[0]['links'][0]['featured'] && false === $clean[1]['links'][0]['featured'], 'First featured must win across sections' );
$flat = ec_sanitize_link_page_links( array( array_merge( $link, array( 'featured' => 'false', 'thumbnail_url' => 'javascript:alert(1)' ) ) ) );
button_styles_check( false === $flat[0]['featured'] && '' === $flat[0]['thumbnail_url'], 'Legacy flat link sanitization failed' );
$seen = false;
$html = ec_render_link_page_section_with_featured( $sections[0], 1, false, $seen ) . ec_render_link_page_section_with_featured( $sections[1], 1, false, $seen );
button_styles_check( 1 === substr_count( $html, ' is-featured' ), 'Renderer must enforce first featured even for unsanitized storage' );
button_styles_check( 1 === substr_count( $html, 'class="extrch-link-thumbnail"' ), 'Only featured link gets thumbnail' );
$seen = false;
$unsafe = ec_render_link_page_section_with_featured( array( 'links' => array( array_merge( $link, array( 'thumbnail_url' => 'javascript:alert(1)' ) ) ) ), 1, false, $seen );
button_styles_check( false === strpos( $unsafe, '<img' ), 'Renderer accepted unsafe thumbnail' );
$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'ec_link_page', 'post_status' => 'publish', 'post_title' => 'Styles', 'post_name' => 'styles' );
$vars = array( '--link-page-button-fill' => 'gradient', '--link-page-button-shadow' => 'glow' );
$saved = ec_save_link_page_persistence( 40, array( 'links' => $sections, 'css_vars' => $vars ) );
button_styles_check( ! is_wp_error( $saved ), 'Save path rejected styles/links' );
$read = ec_read_link_page_persistence( 40 );
button_styles_check( 'gradient' === $read['css_vars']['--link-page-button-fill'] && 'glow' === $read['css_vars']['--link-page-button-shadow'], 'Styles did not round trip' );
button_styles_check( true === $read['links'][0]['links'][0]['featured'] && false === $read['links'][1]['links'][0]['featured'], 'Save path lost featured state' );
button_styles_check( $link['thumbnail_url'] === $read['links'][0]['links'][0]['thumbnail_url'], 'Save path lost thumbnail' );
echo "Button styles smoke passed.\n";
