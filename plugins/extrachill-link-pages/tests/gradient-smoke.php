<?php
/** Standalone regression gate: php tests/gradient-smoke.php */
require_once __DIR__ . '/bootstrap.php';

function ec_gradient_smoke_check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function ec_gradient_smoke_run() {
	$direction = '--link-page-background-gradient-direction';
	$middle    = '--link-page-background-gradient-middle';
	foreach ( array( 'to right', 'to bottom', '135deg', '0deg', '360deg', '27.5deg', '.5deg' ) as $angle ) {
		$result = ec_sanitize_link_page_css_vars( array( $direction => $angle ) );
		ec_gradient_smoke_check( is_array( $result ) && $angle === $result[ $direction ], 'Rejected valid angle: ' . $angle );
	}
	foreach ( array( '-1deg', '360.1deg', '361deg', '90', '90rad', 'NaNdeg', '1e2deg', '90deg; color:red', 'url(x)', '90deg/*x*/', array() ) as $angle ) {
		ec_gradient_smoke_check( is_wp_error( ec_sanitize_link_page_css_vars( array( $direction => $angle ) ) ), 'Accepted invalid angle: ' . json_encode( $angle ) );
	}
	$defaults = ec_link_page_defaults_for( 'styles' );
	ec_gradient_smoke_check( '' === $defaults[ $middle ], 'Middle stop must be disabled by default.' );
	$legacy = '#0b5394, #53940b';
	ec_gradient_smoke_check( $legacy === ec_link_page_gradient_colors( $defaults ), 'Default gradient changed.' );
	unset( $defaults[ $middle ] );
	ec_gradient_smoke_check( $legacy === ec_link_page_gradient_colors( $defaults ), 'Legacy gradient changed.' );
	foreach ( array( '#f0f', '#800080', 'rgb(128, 0, 128)', 'rgba(128, 0, 128, 0.5)', '' ) as $color ) {
		$result = ec_sanitize_link_page_css_vars( array( $middle => $color ) );
		ec_gradient_smoke_check( is_array( $result ) && $color === $result[ $middle ], 'Rejected valid middle color.' );
	}
	foreach ( array( 'purple', 'red, blue', 'url(x)', '#fff; color:red', array() ) as $color ) {
		ec_gradient_smoke_check( is_wp_error( ec_sanitize_link_page_css_vars( array( $middle => $color ) ) ), 'Accepted invalid middle color.' );
	}
	$defaults[ $middle ] = '#800080';
	ec_gradient_smoke_check( '#0b5394, #800080, #53940b' === ec_link_page_gradient_colors( $defaults ), 'Three-stop gradient missing middle.' );
	$css = ec_link_page_css_variables_style_block( $defaults );
	ec_gradient_smoke_check( false !== strpos( $css, '--link-page-background-gradient-colors:#0b5394, #800080, #53940b;' ), 'Public CSS missing color stops.' );
	$GLOBALS['ec_test']['blogs'][4]['posts'][40] = (object) array( 'ID' => 40, 'post_type' => 'ec_link_page', 'post_status' => 'publish' );
	$styles = array_merge( $defaults, array( $direction => '27.5deg' ) );
	$saved = ec_save_link_page_persistence( 40, array( 'css_vars' => $styles ) );
	ec_gradient_smoke_check( ! is_wp_error( $saved ), 'Gradient save failed.' );
	$read = ec_read_link_page_persistence( 40 );
	ec_gradient_smoke_check( '27.5deg' === $read['css_vars'][ $direction ] && '#800080' === $read['css_vars'][ $middle ], 'Gradient did not round-trip.' );
	$saved = ec_save_link_page_persistence( 40, array( 'css_vars' => array( $middle => '' ) ) );
	ec_gradient_smoke_check( ! is_wp_error( $saved ), 'Middle stop reset failed.' );
	$read = ec_read_link_page_persistence( 40 );
	ec_gradient_smoke_check( '' === $read['css_vars'][ $middle ] && '27.5deg' === $read['css_vars'][ $direction ], 'Reset did not preserve angle.' );
	$cleared = ec_sanitize_link_page_css_vars( array( $middle => '' ), $defaults );
	ec_gradient_smoke_check( $legacy === ec_link_page_gradient_colors( array_merge( $defaults, $cleared ) ), 'Cannot disable middle stop.' );
}

if ( realpath( $_SERVER['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	try {
		ec_gradient_smoke_run();
		echo "Gradient smoke passed.\n";
	} catch ( Throwable $error ) {
		fwrite( STDERR, $error->getMessage() . "\n" );
		exit( 1 );
	}
}
