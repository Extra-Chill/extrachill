<?php
/**
 * Standalone tests for safe shared-cache response headers.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress helpers and writes CLI output directly.

define( 'EXTRACHILL_CACHE_DROPIN', true );
define( 'EXTRACHILL_CACHE_DIR', sys_get_temp_dir() . '/extrachill-cache-header-tests-' . getmypid() );

function absint( $value ) {
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/inc/cache-store.php';

function extrachill_cache_header_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
		exit( 1 );
	}
}

$filtered = extrachill_cache_filter_headers(
	array(
		'cache-control: private, no-store',
		'content-security-policy: block-all-mixed-content',
		'CONTENT-SECURITY-POLICY: block-all-mixed-content',
		'Content-Security-Policy: upgrade-insecure-requests',
		'Permissions-Policy: geolocation=()',
		'Referrer-Policy: strict-origin-when-cross-origin',
		'Strict-Transport-Security: max-age=31536000; includeSubDomains',
		'X-Content-Type-Options: nosniff',
		'X-Frame-Options: SAMEORIGIN',
		'Cross-Origin-Embedder-Policy: require-corp',
		'Cross-Origin-Opener-Policy: same-origin',
		'Cross-Origin-Resource-Policy: same-site',
		'Origin-Agent-Cluster: ?1',
		'Content-Type: text/plain',
		'content-type: text/html; charset=UTF-8',
		'Last-Modified: Mon, 01 Jan 2024 00:00:00 GMT',
		'last-modified: Tue, 02 Jan 2024 00:00:00 GMT',
		'Set-Cookie: session=secret',
		'Authorization: Bearer secret',
		'WWW-Authenticate: Basic realm="private"',
		'Connection: keep-alive',
		'Keep-Alive: timeout=5',
		'Transfer-Encoding: chunked',
		'Upgrade: websocket',
		'Content-Length: 123',
		'Vary: Cookie',
		'X-User-ID: 42',
		'Malformed without colon',
		'Content-Security-Policy:',
		"Content-Security-Policy: default-src 'self'\r\nSet-Cookie: injected=1",
		array( 'name' => 'Content-Security-Policy', 'value' => "script-src 'self'" ),
		array( 'name' => 'Set-Cookie', 'value' => 'array-secret=1' ),
		array( 'name' => 'Content-Security-Policy' ),
	)
);

extrachill_cache_header_test_assert_same(
	array(
		array( 'name' => 'Content-Type', 'value' => 'text/html; charset=UTF-8' ),
		array( 'name' => 'Last-Modified', 'value' => 'Tue, 02 Jan 2024 00:00:00 GMT' ),
		array( 'name' => 'Content-Security-Policy', 'value' => 'block-all-mixed-content' ),
		array( 'name' => 'Content-Security-Policy', 'value' => 'upgrade-insecure-requests' ),
		array( 'name' => 'Content-Security-Policy', 'value' => "script-src 'self'" ),
		array( 'name' => 'Permissions-Policy', 'value' => 'geolocation=()' ),
		array( 'name' => 'Referrer-Policy', 'value' => 'strict-origin-when-cross-origin' ),
		array( 'name' => 'Strict-Transport-Security', 'value' => 'max-age=31536000; includeSubDomains' ),
		array( 'name' => 'X-Content-Type-Options', 'value' => 'nosniff' ),
		array( 'name' => 'X-Frame-Options', 'value' => 'SAMEORIGIN' ),
		array( 'name' => 'Cross-Origin-Embedder-Policy', 'value' => 'require-corp' ),
		array( 'name' => 'Cross-Origin-Opener-Policy', 'value' => 'same-origin' ),
		array( 'name' => 'Cross-Origin-Resource-Policy', 'value' => 'same-site' ),
		array( 'name' => 'Origin-Agent-Cluster', 'value' => '?1' ),
	),
	$filtered,
	'allowlist is case-insensitive, canonical, deterministic, and excludes unsafe or malformed headers'
);

$identity    = 'https://example.com/article';
$desktop_key = extrachill_cache_key( $identity, 'desktop' );
$mobile_key  = extrachill_cache_key( $identity, 'mobile' );

extrachill_cache_header_test_assert_same( false, $desktop_key === $mobile_key, 'desktop and mobile use separate cache keys' );

foreach ( array( $desktop_key, $mobile_key ) as $key ) {
	extrachill_cache_header_test_assert_same(
		true,
		extrachill_cache_write( $key, '<html><body>cached</body></html>', $filtered, 1 ),
		'cache payload writes successfully'
	);
	$payload = extrachill_cache_read( $key, 1, 60 );
	extrachill_cache_header_test_assert_same( $filtered, $payload['headers'], 'safe headers survive cache storage for each device variant' );
}

extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
fwrite( STDOUT, "All header tests passed.\n" );
