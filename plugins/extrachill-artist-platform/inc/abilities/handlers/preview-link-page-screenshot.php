<?php
/**
 * Live Link Page screenshots for the preview ability.
 *
 * Captures only the link page's own public URL (resolved server-side by the
 * caller, never taken from ability input) with headless Chromium through
 * playwright-core. Host binaries are configured, never hard-coded:
 *
 * - EXTRACHILL_PREVIEW_CHROME_PATH / filter extrachill_artist_platform_preview_chrome_path
 * - EXTRACHILL_PREVIEW_PLAYWRIGHT_PATH / filter extrachill_artist_platform_preview_playwright_path
 * - EXTRACHILL_PREVIEW_NODE_PATH / filter extrachill_artist_platform_preview_node_path (default: node on PATH)
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_ARTIST_PLATFORM_PREVIEW_TIMEOUT = 30;

/** Resolve a configured host path from its constant, then its filter. */
function extrachill_artist_platform_preview_config_path( $constant, $filter, $fallback = '' ) {
	$value = defined( $constant ) ? (string) constant( $constant ) : $fallback;
	return (string) apply_filters( $filter, $value );
}

/** Capture the public URL; always returns a screenshot status object. */
function extrachill_artist_platform_preview_screenshot( $link_page_id, $public_url, $viewport ) {
	$unavailable = static function ( $reason ) {
		return array(
			'status' => 'unavailable',
			'url'    => null,
			'reason' => $reason,
		);
	};

	$link_page_id = (int) $link_page_id;
	$scheme       = wp_parse_url( (string) $public_url, PHP_URL_SCHEME );
	if ( $link_page_id < 1 || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return $unavailable( 'The link page has no public URL to capture.' );
	}

	$chrome     = extrachill_artist_platform_preview_config_path( 'EXTRACHILL_PREVIEW_CHROME_PATH', 'extrachill_artist_platform_preview_chrome_path' );
	$playwright = extrachill_artist_platform_preview_config_path( 'EXTRACHILL_PREVIEW_PLAYWRIGHT_PATH', 'extrachill_artist_platform_preview_playwright_path' );
	$node       = extrachill_artist_platform_preview_config_path( 'EXTRACHILL_PREVIEW_NODE_PATH', 'extrachill_artist_platform_preview_node_path', 'node' );

	/* Tests and alternate hosts can swap the capture runner. */
	$runner = apply_filters( 'extrachill_artist_platform_preview_screenshot_runner', 'extrachill_artist_platform_preview_run_playwright' );
	if ( 'extrachill_artist_platform_preview_run_playwright' === $runner ) {
		if ( '' === $chrome || ! is_executable( $chrome ) ) {
			return $unavailable( 'Headless Chromium is not configured or not executable by the web server (set EXTRACHILL_PREVIEW_CHROME_PATH).' );
		}
		if ( '' === $playwright || ! is_dir( $playwright ) ) {
			return $unavailable( 'playwright-core is not configured or not readable by the web server (set EXTRACHILL_PREVIEW_PLAYWRIGHT_PATH).' );
		}
		if ( ! function_exists( 'proc_open' ) ) {
			return $unavailable( 'proc_open is disabled on this host.' );
		}
	}
	if ( ! is_callable( $runner ) ) {
		return $unavailable( 'No screenshot runner is available.' );
	}

	$uploads = wp_upload_dir();
	if ( ! empty( $uploads['error'] ) ) {
		return $unavailable( 'The uploads directory is not writable.' );
	}
	$dir = trailingslashit( $uploads['basedir'] ) . 'link-page-previews';
	if ( ! wp_mkdir_p( $dir ) ) {
		return $unavailable( 'Could not create the preview directory.' );
	}

	/* One file per link page, overwritten on each capture. */
	$file = $dir . '/' . $link_page_id . '.png';
	$tmp  = $dir . '/' . $link_page_id . '-' . wp_generate_password( 8, false ) . '.tmp.png';

	$result = call_user_func(
		$runner,
		array(
			'url'        => (string) $public_url,
			'output'     => $tmp,
			'width'      => (int) $viewport['width'],
			'height'     => (int) $viewport['height'],
			'chrome'     => $chrome,
			'playwright' => $playwright,
			'node'       => $node,
			'timeout'    => EXTRACHILL_ARTIST_PLATFORM_PREVIEW_TIMEOUT,
		)
	);

	if ( is_wp_error( $result ) || ! is_file( $tmp ) || filesize( $tmp ) < 1 ) {
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		$reason = is_wp_error( $result ) ? $result->get_error_message() : 'Capture produced no image.';
		return $unavailable( 'Screenshot capture failed: ' . $reason );
	}
	if ( ! rename( $tmp, $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace inside uploads.
		wp_delete_file( $tmp );
		return $unavailable( 'Could not store the captured image.' );
	}

	$size     = function_exists( 'getimagesize' ) ? getimagesize( $file ) : false;
	$captured = time();

	return array(
		'status'      => 'captured',
		'url'         => trailingslashit( $uploads['baseurl'] ) . 'link-page-previews/' . $link_page_id . '.png?v=' . $captured,
		'width'       => $size ? (int) $size[0] : (int) $viewport['width'],
		'height'      => $size ? (int) $size[1] : null,
		'viewport'    => array(
			'width'  => (int) $viewport['width'],
			'height' => (int) $viewport['height'],
		),
		'full_page'   => true,
		'captured_at' => gmdate( 'c', $captured ),
	);
}

/** Default runner: node + playwright-core, killed after the timeout. */
function extrachill_artist_platform_preview_run_playwright( $job ) {
	$script = <<<'JS'
const [ playwrightPath, chrome, url, output, width, height, timeout ] = process.argv.slice( 1 );
const { chromium } = require( playwrightPath );
( async () => {
	const browser = await chromium.launch( { executablePath: chrome, args: [ '--no-sandbox', '--disable-dev-shm-usage' ] } );
	try {
		const page = await browser.newPage( { viewport: { width: Number( width ), height: Number( height ) }, deviceScaleFactor: 1 } );
		await page.goto( url, { waitUntil: 'networkidle', timeout: Number( timeout ) } );
		await page.screenshot( { path: output, fullPage: true } );
	} finally {
		await browser.close();
	}
} )().catch( ( error ) => { console.error( String( error && error.message || error ) ); process.exit( 1 ); } );
JS;

	$timeout = (int) $job['timeout'];
	$command = array(
		$job['node'],
		'-e',
		$script,
		$job['playwright'],
		$job['chrome'],
		$job['url'],
		$job['output'],
		(string) $job['width'],
		(string) $job['height'],
		(string) ( max( 1, $timeout - 5 ) * 1000 ),
	);
	$home    = sys_get_temp_dir();
	$env     = array(
		'HOME' => $home,
		'PATH' => getenv( 'PATH' ) ? getenv( 'PATH' ) : '/usr/local/bin:/usr/bin:/bin',
	);
	$pipes   = array();
	$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, $env );
	if ( ! is_resource( $process ) ) {
		return new WP_Error( 'screenshot_spawn_failed', 'Could not start node.' );
	}
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stderr   = '';
	$deadline = microtime( true ) + $timeout;
	$status   = proc_get_status( $process );
	while ( $status['running'] && microtime( true ) < $deadline ) {
		usleep( 100000 );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		stream_get_contents( $pipes[1] );
		$status = proc_get_status( $process );
	}
	$stderr .= (string) stream_get_contents( $pipes[2] );
	if ( $status['running'] ) {
		proc_terminate( $process, 9 );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
		proc_close( $process );
		return new WP_Error( 'screenshot_timeout', 'Capture timed out after ' . $timeout . 's.' );
	}
	fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
	fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Process pipe.
	proc_close( $process );

	/* proc_get_status() reports the real exit code only once. */
	if ( 0 !== (int) $status['exitcode'] ) {
		$message = trim( substr( $stderr, 0, 300 ) );
		return new WP_Error( 'screenshot_failed', '' !== $message ? $message : 'Chromium exited with code ' . (int) $status['exitcode'] . '.' );
	}

	return true;
}
