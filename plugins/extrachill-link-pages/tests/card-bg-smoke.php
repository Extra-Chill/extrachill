<?php
/* Standalone persistence regression proof for issue #1172. */
require_once __DIR__ . '/bootstrap.php';

function card_bg_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'ec_link_page', 'post_status' => 'publish' );
$key = '--link-page-card-bg-color';
$read = ec_read_link_page_persistence( 40 );
card_bg_check( 'rgba(0, 0, 0, 0.4)' === $read['css_vars'][ $key ], 'Unconfigured pages retain the default' );
foreach ( array( '#abcdef', '#abc', 'rgb(12, 34, 56)', 'rgba(12, 34, 56, 0.25)', 'rgba(12, 34, 56, 0)', 'rgba(12, 34, 56, 1)', 'none', 'transparent' ) as $value ) {
	$expected = 'none' === $value ? 'transparent' : $value;
	$saved = ec_save_link_page_persistence( 40, array( 'css_vars' => array( $key => $value ) ) );
	card_bg_check( ! is_wp_error( $saved ), 'Save failed for ' . $value );
	$read = ec_read_link_page_persistence( 40 );
	card_bg_check( $expected === $read['css_vars'][ $key ], 'Round trip failed for ' . $value );
	card_bg_check( $expected === get_post_meta( 40, '_link_page_custom_css_vars', true )[ $key ], 'Storage dropped card color' );
}
foreach ( array( 'rgba(0, 0, 0, 1.1)', 'rgba(999, 0, 0, 0.5)', 'rgba(0, 0, 0)', 'red; color: blue', 'url(https://example.com)', array() ) as $value ) {
	card_bg_check( is_wp_error( ec_sanitize_link_page_css_vars( array( $key => $value ) ) ), 'Unsafe/invalid color accepted' );
}
$save = ec_save_link_page_persistence( 40, array( 'css_vars' => array( '--link-page-text-color' => '#ffffff' ) ) );
card_bg_check( ! is_wp_error( $save ), 'Unrelated style save failed' );
card_bg_check( 'transparent' === ec_read_link_page_persistence( 40 )['css_vars'][ $key ], 'Unrelated save lost transparency' );
echo "Card background smoke passed\n";
