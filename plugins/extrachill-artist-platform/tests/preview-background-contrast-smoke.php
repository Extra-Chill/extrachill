<?php
/* Standalone contrast regression fixtures: php tests/preview-background-contrast-smoke.php */
define( 'ABSPATH', __DIR__ );
$GLOBALS['site'] = 1;
$GLOBALS['cache'] = array();
function ec_get_link_page_storage_blog_id() { return 7; }
function get_current_blog_id() { return $GLOBALS['site']; }
function switch_to_blog( $site ) { $GLOBALS['site'] = $site; }
function restore_current_blog() { $GLOBALS['site'] = 1; }
function get_transient( $key ) {
	check( 7 === get_current_blog_id(), 'Image cache must use link storage site.' );
	return $GLOBALS['cache'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl ) { $GLOBALS['cache'][ $key ] = $value; }
function get_attached_file( $id ) { return $GLOBALS['attachment_file'] ?? false; }
function wp_safe_remote_get( $url, $args ) { ++$GLOBALS['requests']; return false; }
function is_wp_error( $value ) { return false; }
function wp_remote_retrieve_response_code( $response ) { return 404; }
function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
require dirname( __DIR__ ) . '/inc/abilities/handlers/preview-link-page.php';
function report( $overrides = array(), $settings = array() ) {
	$styles = array(
		'--link-page-background-type' => 'color',
		'--link-page-background-color' => '#ffffff',
		'--link-page-text-color' => '#000000',
		'--link-page-link-text-color' => '#ffffff',
		'--link-page-button-bg-color' => '#000000',
		'--link-page-card-bg-color' => 'rgba(0, 0, 0, 0.4)',
	);
	return extrachill_artist_platform_preview_contrast( array_merge( $styles, $overrides ), $settings );
}
$solid = report();
check( 21.0 === $solid['page_text_on_background']['ratio'], 'Solid black/white must remain 21:1.' );
check( 21.0 === $solid['button_text_on_button']['ratio'], 'Button text must still use link text.' );
check( $solid['icons_on_background'] === $solid['page_text_on_background'], 'Unset icons must fall back to page text.' );
$gradient = array(
	'--link-page-background-type' => 'gradient',
	'--link-page-background-gradient-start' => '#003A5C',
	'--link-page-background-gradient-end' => '#00726E',
	'--link-page-background-gradient-middle' => '',
	'--link-page-icon-color' => '#FF8C00',
);
$report = report( $gradient, array( 'overlay_enabled' => true ) );
foreach ( $report as $name => $entry ) {
	check( 2 === count( $entry['stops'] ), "$name must include both QRiSG stops." );
	check( null !== $entry['ratio'], "$name must be known." );
	check( min( array_column( $entry['stops'], 'ratio' ) ) === $entry['ratio'], "$name must select worst ratio." );
	foreach ( $entry['stops'] as $stop ) {
		$expected = report( array( '--link-page-background-color' => $stop['color'], '--link-page-icon-color' => '#FF8C00' ), array( 'overlay_enabled' => true ) );
		check( $expected[ $name ]['ratio'] === $stop['ratio'], "$name stop must composite as solid." );
	}
}
check( $report['icons_on_background']['ratio'] !== $report['page_text_on_background']['ratio'], 'Explicit orange icons must not use page text.' );
$other_link_text = report( array_merge( $gradient, array( '--link-page-link-text-color' => '#123456' ) ), array( 'overlay_enabled' => true ) );
check( $other_link_text['icons_on_background'] === $report['icons_on_background'], 'Icons must not use link text.' );
check( $other_link_text['icons_on_card'] === $report['icons_on_card'], 'Card icons must not use link text.' );
$gradient['--link-page-background-gradient-middle'] = '#ffffff';
$three = report( $gradient );
check( 3 === count( $three['page_text_on_background']['stops'] ), 'Middle gradient stop must be included.' );
check( '#ffffff' === $three['page_text_on_background']['stops'][1]['color'], 'Middle stop must retain order.' );
$url = 'https://example.org/background.jpg';
$key = 'ec_preview_average_' . md5( '0|' . $url . '|' );
$GLOBALS['cache'][ $key ] = array( 'color' => array( 255, 255, 255, 1.0 ) );
$image = array(
	'--link-page-background-type' => 'image',
	'--link-page-background-image-url' => 'url("' . $url . '")',
	'--link-page-overlay-color' => 'rgba(0, 0, 0, 0.5)',
);
$sample = report( $image );
check( 21.0 === $sample['page_text_on_background']['ratio'], 'Stubbed white image average must be used.' );
$overlay = report( $image, array( 'overlay_enabled' => true ) );
check( abs( $overlay['page_text_on_background']['ratio'] - 5.2808 ) < 0.0001, 'Image overlay must composite over average.' );
check( $overlay['page_text_on_card']['ratio'] < $overlay['page_text_on_background']['ratio'], 'Card must composite over treated image.' );
check( 1 === get_current_blog_id(), 'Image lookup must restore caller site.' );
$GLOBALS['cache'] = array();
$GLOBALS['requests'] = 0;
$unreadable = report( $image );
check( 'unknown' === $unreadable['page_text_on_background']['status'], 'Unreadable image must be unknown.' );
check( ! empty( $unreadable['page_text_on_background']['reason'] ), 'Unreadable image must explain why.' );
report( $image );
check( 1 === $GLOBALS['requests'], 'Unreadable image must be cached.' );
check( 1 === get_current_blog_id(), 'Failed lookup must restore caller site.' );
if ( function_exists( 'imagecreatetruecolor' ) ) {
	$file = tempnam( sys_get_temp_dir(), 'ec-preview-' );
	try {
		$fixture = imagecreatetruecolor( 8, 8 );
		imagefill( $fixture, 0, 0, imagecolorallocate( $fixture, 255, 255, 255 ) );
		imagepng( $fixture, $file );
		imagedestroy( $fixture );
		$GLOBALS['attachment_file'] = $file;
		$decoded = report( $image, array( 'background_image_id' => 42 ) );
		check( 21.0 === $decoded['page_text_on_background']['ratio'], 'GD must decode and sample attachment pixels.' );
	} finally {
		unlink( $file );
	}
}
echo "Preview background contrast smoke passed.\n";
