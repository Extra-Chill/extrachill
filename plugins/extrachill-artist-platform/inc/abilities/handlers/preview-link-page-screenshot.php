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
 * - EXTRACHILL_PREVIEW_RESOLVE_IP / filter extrachill_artist_platform_preview_resolve_ip (optional origin IP)
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_ARTIST_PLATFORM_PREVIEW_TIMEOUT = 30;

/**
 * Resolve a configured host path from its constant, then its filter.
 *
 * @param string $constant Constant name.
 * @param string $filter   Filter name.
 * @param string $fallback Value when the constant is undefined.
 * @return string
 */
function extrachill_artist_platform_preview_config_path( $constant, $filter, $fallback = '' ) {
	$value = defined( $constant ) ? (string) constant( $constant ) : $fallback;
	if ( '' === $filter ) {
		return $value;
	}
	return (string) apply_filters( $filter, $value );
}

/**
 * Capture the public URL; always returns a screenshot status object.
 *
 * @param int    $link_page_id Link page ID (names the stored file).
 * @param string $public_url   The link page's own public URL.
 * @param array  $viewport     Width and height.
 * @return array
 */
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
	/* Optional origin pin: serve the page's own host from this IP, skipping the CDN. TLS still verifies. */
	$resolve_ip = extrachill_artist_platform_preview_config_path( 'EXTRACHILL_PREVIEW_RESOLVE_IP', 'extrachill_artist_platform_preview_resolve_ip' );
	$resolve_ip = false !== filter_var( $resolve_ip, FILTER_VALIDATE_IP ) ? $resolve_ip : '';

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
			'resolve_ip' => $resolve_ip,
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

/**
 * Default runner: node + playwright-core, killed after the timeout.
 *
 * @param array $job Capture job (url, output, width, height, chrome, playwright, node, timeout).
 * @return true|WP_Error
 */
function extrachill_artist_platform_preview_run_playwright( $job ) {
	$script = <<<'JS'
const [ playwrightPath, chrome, url, output, width, height, timeout, resolveIp ] = process.argv.slice( 1 );
const { chromium } = require( playwrightPath );
const args = [ '--no-sandbox', '--disable-dev-shm-usage' ];
if ( resolveIp ) {
	args.push( '--host-resolver-rules=MAP ' + new URL( url ).hostname + ' ' + resolveIp );
}
( async () => {
	const browser = await chromium.launch( { executablePath: chrome, args } );
	const page = await browser.newPage( { viewport: { width: Number( width ), height: Number( height ) }, deviceScaleFactor: 1 } );
	/* Capture the anonymous visitor view: skip the edit-button token handoff redirect (link-page-edit-button.js). */
	await page.addInitScript( () => { try { window.sessionStorage.setItem( 'ecLinkEditHandoffAttempted', '1' ); } catch ( e ) {} } );
	/* Previews are not visits: drop analytics and view tracking so captures never inflate stats. */
	await page.route( /google-analytics\.com|googletagmanager\.com|doubleclick\.net|\/extrachill\/v1\/analytics\/|\/extrachill\/track-page-view\//, ( route ) => route.abort() );
	await page.goto( url, { waitUntil: 'load', timeout: Number( timeout ) } );
	await page.evaluate( () => document.fonts.ready );
	await page.screenshot( { path: output, fullPage: true } );
	/* Playwright kills the browser on exit; waiting for a graceful close only adds latency. */
	process.exit( 0 );
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
		(string) ( $job['resolve_ip'] ?? '' ),
	);
	$home    = sys_get_temp_dir();
	$env     = array(
		'HOME'               => $home,
		'NODE_COMPILE_CACHE' => trailingslashit( $home ) . 'ec-preview-node-cache',
		'PATH'               => getenv( 'PATH' ) ? getenv( 'PATH' ) : '/usr/local/bin:/usr/bin:/bin',
	);
	$pipes   = array();
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Fixed argv (no shell) to a configured Chromium runner; disabled hosts report unavailable.
	$process = proc_open(
		$command,
		array(
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		),
		$pipes,
		null,
		$env
	);
	if ( ! is_resource( $process ) ) {
		return new WP_Error( 'screenshot_spawn_failed', 'Could not start node.' );
	}
	stream_set_blocking( $pipes[1], false );
	stream_set_blocking( $pipes[2], false );

	$stderr   = '';
	$deadline = microtime( true ) + $timeout;
	$poll     = static function () use ( $process ) {
		$current = proc_get_status( $process );
		return is_array( $current ) ? $current : array(
			'running'  => false,
			'exitcode' => -1,
		);
	};
	$status   = $poll();
	while ( $status['running'] && microtime( true ) < $deadline ) {
		usleep( 100000 );
		$stderr .= (string) stream_get_contents( $pipes[2] );
		stream_get_contents( $pipes[1] );
		$status = $poll();
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
