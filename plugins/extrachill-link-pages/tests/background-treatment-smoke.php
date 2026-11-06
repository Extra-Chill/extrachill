<?php
/** Standalone background treatment regression gate. */
require_once __DIR__ . '/bootstrap.php';

function background_treatment_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

$valid = array(
	'--link-page-image-blur'       => '12.5px',
	'--link-page-image-brightness' => '75%',
	'--link-page-image-position'   => '23.5% 100%',
	'--link-page-overlay-color'    => 'rgba(12, 34, 56, 0.25)',
	'overlay'                      => '1',
);
background_treatment_check( ec_sanitize_link_page_css_vars( $valid ) === $valid, 'Valid treatment rejected.' );
foreach ( array(
	'--link-page-image-blur'       => array( '-1px', '20.1px', 'blur(2px)', '2px; color:red' ),
	'--link-page-image-brightness' => array( '49%', '151%', '100', 'url(evil)' ),
	'--link-page-image-position'   => array( '-1% 50%', '101% 50%', '50% 100.1%', '50%', 'center; color:red' ),
	'--link-page-overlay-color'    => array( 'red; color:blue', 'url(evil)', 'rgba(0,0,0,2)' ),
) as $key => $values ) {
	foreach ( $values as $value ) {
		background_treatment_check( is_wp_error( ec_sanitize_link_page_css_vars( array( $key => $value ) ) ), "Unsafe/out-of-range treatment accepted: $key=$value" );
	}
}
foreach ( array( '0px', '20px' ) as $value ) {
	background_treatment_check( ! is_wp_error( ec_sanitize_link_page_css_vars( array( '--link-page-image-blur' => $value ) ) ), 'Blur boundary rejected.' );
}
foreach ( array( '50%', '150%' ) as $value ) {
	background_treatment_check( ! is_wp_error( ec_sanitize_link_page_css_vars( array( '--link-page-image-brightness' => $value ) ) ), 'Brightness boundary rejected.' );
}
foreach ( array( 'left top', 'center center', 'right', '0% 0%', '100% 100%', '0.5% 99.5%' ) as $value ) {
	background_treatment_check( ! is_wp_error( ec_sanitize_link_page_css_vars( array( '--link-page-image-position' => $value ) ) ), 'Position rejected.' );
}
$defaults = ec_link_page_defaults_for( 'styles' );
background_treatment_check( '0px' === $defaults['--link-page-image-blur'] && '100%' === $defaults['--link-page-image-brightness'], 'Neutral defaults missing.' );
background_treatment_check( ! is_wp_error( ec_sanitize_link_page_css_vars( $defaults ) ), 'Defaults rejected.' );
$css = file_get_contents( dirname( __DIR__ ) . '/assets/css/extrch-links.css' );
background_treatment_check( 1 === preg_match( '/\[data-bg-type="image"\]::before\s*\{[^}]*filter:\s*blur\(var\(--link-page-image-blur[^}]*brightness\(var\(--link-page-image-brightness/s', $css ), 'Background-only treatment rule missing.' );
background_treatment_check( false !== strpos( $css, 'background-color: var(--link-page-overlay-color,' ), 'Overlay color is not consumed.' );
background_treatment_check( false !== strpos( $css, '.extrch-link-page-content-wrapper.no-overlay' ), 'Legacy overlay toggle missing.' );
$template = file_get_contents( dirname( __DIR__ ) . '/templates/link-page.php' );
background_treatment_check( false !== strpos( $template, '--link-page-background-image-url:url(' ), 'Public image layer URL missing.' );
$single = file_get_contents( dirname( __DIR__ ) . '/templates/single-link-page.php' );
background_treatment_check( false === strpos( $single, "background-image:url(" ), 'Untreated body image remains.' );
echo "Background treatment smoke passed.\n";
