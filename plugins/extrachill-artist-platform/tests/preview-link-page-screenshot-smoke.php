<?php
/**
 * Standalone screenshot contract tests for extrachill/preview-link-page.
 *
 * Run: php plugins/extrachill-artist-platform/tests/preview-link-page-screenshot-smoke.php
 *
 * @package ExtraChillArtistPlatform
 */

define( 'ABSPATH', __DIR__ );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}

$GLOBALS['uploads_dir'] = sys_get_temp_dir() . '/ec-preview-smoke-' . getmypid();
$GLOBALS['filters']     = array();
$GLOBALS['runner_jobs'] = array();

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function apply_filters( $name, $value ) {
	return array_key_exists( $name, $GLOBALS['filters'] ) ? $GLOBALS['filters'][ $name ] : $value;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_upload_dir() {
	return array(
		'basedir' => $GLOBALS['uploads_dir'],
		'baseurl' => 'https://artist.extrachill.com/wp-content/uploads/sites/4',
		'error'   => false,
	);
}
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function wp_generate_password( $length, $special ) {
	return substr( md5( (string) mt_rand() ), 0, $length );
}
function wp_delete_file( $file ) {
	unlink( $file );
}
function extrachill_artist_platform_ability_get_link_page_data( $input ) {
	$GLOBALS['data_input'] = $input;
	return array(
		'link_page_id' => 12187,
		'css_vars'     => array( '--link-page-background-color' => '#000000', '--link-page-text-color' => '#ffffff' ),
		'settings'     => array(),
	);
}
function ec_get_link_page_public_url( $id ) {
	$GLOBALS['public_url_for'] = $id;
	return 'https://extrachill.link/qrisg';
}
function check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . "\n" );
		exit( 1 );
	}
}

/* A 1x1 PNG stands in for Chromium output. */
function smoke_runner( $job ) {
	$GLOBALS['runner_jobs'][] = $job;
	file_put_contents( $job['output'], base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=' ) );
	return true;
}
function smoke_failing_runner( $job ) {
	return new WP_Error( 'screenshot_timeout', 'Capture timed out after 30s.' );
}

require_once dirname( __DIR__ ) . '/inc/abilities/handlers/preview-link-page.php';

/* Binaries missing: honest unavailable with a reason; no runner call. */
$GLOBALS['filters']['extrachill_artist_platform_preview_chrome_path'] = '/nonexistent/chrome';
$result = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 12186 ) );
check( 'unavailable' === $result['screenshot']['status'] && null === $result['screenshot']['url'], 'Missing Chromium reports unavailable.' );
check( false !== strpos( $result['screenshot']['reason'], 'EXTRACHILL_PREVIEW_CHROME_PATH' ), 'Reason names the configuration constant.' );
check( array() === $GLOBALS['runner_jobs'], 'Runner is not called without binaries.' );
check( isset( $result['contrast_report']['page_text_on_background'] ), 'Contrast report is unchanged.' );

/* Binaries resolve (runner stubbed): captured, stored per link page. */
$GLOBALS['filters']['extrachill_artist_platform_preview_screenshot_runner'] = 'smoke_runner';
$result = extrachill_artist_platform_ability_preview_link_page(
	array(
		'artist_id'    => 12186,
		'link_page_id' => 999,
		'url'          => 'http://169.254.169.254/latest/meta-data',
	)
);
$job = $GLOBALS['runner_jobs'][0];
check( 'captured' === $result['screenshot']['status'], 'Runner success reports captured.' );
check( 'https://extrachill.link/qrisg' === $job['url'], 'Runner always receives the link page public URL.' );
check( array( 'artist_id' => 12186 ) === $GLOBALS['data_input'], 'Only the artist selects the page; link_page_id/url input is ignored.' );
check( 12187 === $GLOBALS['public_url_for'], 'Public URL comes from the artist-owned link page.' );
check( 390 === $job['width'] && 844 === $job['height'], 'Default mobile viewport.' );
check( 30 === $job['timeout'], 'Capture is capped at 30s.' );
check( 0 === strpos( $result['screenshot']['url'], 'https://artist.extrachill.com/wp-content/uploads/sites/4/link-page-previews/12187.png?v=' ), 'Image URL is the per-page uploads file.' );
check( is_file( $GLOBALS['uploads_dir'] . '/link-page-previews/12187.png' ), 'Image stored under uploads.' );
check( true === $result['screenshot']['full_page'] && 1 === $result['screenshot']['width'], 'Image dimensions come from the capture.' );
check( false !== strtotime( $result['screenshot']['captured_at'] ), 'captured_at is a timestamp.' );

/* Re-capture overwrites rather than accumulating files. */
extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 12186, 'viewport' => array( 'width' => 1280, 'height' => 800 ) ) );
check( 1280 === $GLOBALS['runner_jobs'][1]['width'], 'Requested viewport reaches the runner.' );
check( array( '12187.png' ) === array_values( array_diff( scandir( $GLOBALS['uploads_dir'] . '/link-page-previews' ), array( '.', '..' ) ) ), 'One file per link page.' );

/* Runner failure or timeout: unavailable with the reason, temp file removed. */
$GLOBALS['filters']['extrachill_artist_platform_preview_screenshot_runner'] = 'smoke_failing_runner';
$result = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 12186 ) );
check( 'unavailable' === $result['screenshot']['status'] && false !== strpos( $result['screenshot']['reason'], 'timed out' ), 'Timeouts report unavailable.' );

/* screenshot=false skips capture. */
$GLOBALS['filters']['extrachill_artist_platform_preview_screenshot_runner'] = 'smoke_runner';
$before = count( $GLOBALS['runner_jobs'] );
$result = extrachill_artist_platform_ability_preview_link_page( array( 'artist_id' => 12186, 'screenshot' => false ) );
check( 'skipped' === $result['screenshot']['status'] && count( $GLOBALS['runner_jobs'] ) === $before, 'screenshot=false skips capture.' );

array_map( 'unlink', glob( $GLOBALS['uploads_dir'] . '/link-page-previews/*' ) );
rmdir( $GLOBALS['uploads_dir'] . '/link-page-previews' );
rmdir( $GLOBALS['uploads_dir'] );

echo "preview-link-page screenshot smoke: PASS\n";
