<?php
/**
 * Standalone tests for shared serve/store/purge URL identities.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress helpers and writes CLI output directly.

define( 'EXTRACHILL_CACHE_DROPIN', true );
define( 'EXTRACHILL_CACHE_DIR', sys_get_temp_dir() . '/extrachill-cache-identity-tests-' . getmypid() );

function absint( $value ) {
	return abs( (int) $value );
}

require dirname( __DIR__ ) . '/inc/cache-store.php';

function extrachill_cache_identity_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
		exit( 1 );
	}
}

foreach ( array( '/slug', '/parent/slug', '/parent.with.dot/slug' ) as $path ) {
	$canonical = 'https://example.com' . $path . '/';
	$identity  = extrachill_cache_normalize_url_identity( 'https://example.com' . $path );
	extrachill_cache_identity_test_assert_same( $canonical, $identity, 'directory-like paths gain a trailing slash' );
	extrachill_cache_identity_test_assert_same( $identity, extrachill_cache_normalize_url_identity( $canonical ), 'slash forms share an identity' );
	foreach ( array( 'desktop', 'mobile' ) as $device ) {
		extrachill_cache_identity_test_assert_same( extrachill_cache_key( $identity, $device ), extrachill_cache_key( extrachill_cache_normalize_url_identity( $canonical ), $device ), 'slash forms share a key in each device bucket' );
	}
}

foreach ( array( '/', '/sitemap.xml', '/feed.rss', '/nested/file.html', '/sitemap.xml/', '/.well-known' ) as $path ) {
	$url = 'https://example.com' . $path;
	extrachill_cache_identity_test_assert_same( $url, extrachill_cache_normalize_url_identity( $url ), 'root and file-like paths retain their exact identity' );
}
extrachill_cache_identity_test_assert_same( 'https://example.com/', extrachill_cache_normalize_url_identity( 'https://example.com' ), 'absent root path remains a slash' );

foreach ( array( '/slug', '/slug/' ) as $path ) {
	extrachill_cache_identity_test_assert_same( 'https://example.com/slug/', extrachill_cache_normalize_url_identity( 'https://example.com' . $path . '?utm_source=test&fbclid=123' ), 'tracking-only queries are still discarded' );
	extrachill_cache_identity_test_assert_same( 'https://example.com/slug/?page=2&utm_source=test', extrachill_cache_normalize_url_identity( 'https://example.com' . $path . '?page=2&utm_source=test' ), 'functional queries are still retained verbatim' );
}
extrachill_cache_identity_test_assert_same( 'https://example.com/feed.rss?format=xml', extrachill_cache_normalize_url_identity( 'https://example.com/feed.rss?format=xml' ), 'file-like query handling is unchanged' );

// Exercise the same request identity helper used by the drop-in, then purge via a permalink.
$_SERVER['HTTP_HOST']   = 'extrachill.link';
$_SERVER['REQUEST_URI'] = '/gavin-hamilton';
$_SERVER['HTTPS']       = 'on';
$identity              = extrachill_cache_url_identity();
extrachill_cache_identity_test_assert_same( 'https://extrachill.link/gavin-hamilton/', $identity, 'no-slash request uses the canonical identity' );

foreach ( array( 'desktop', 'mobile' ) as $device ) {
	$key = extrachill_cache_key( $identity, $device );
	extrachill_cache_identity_test_assert_same( true, extrachill_cache_write( $key, '<html>cached link page</html>', array(), 13 ), 'no-slash request payload is stored' );
	extrachill_cache_identity_test_assert_same( true, is_array( extrachill_cache_read( $key, 13, 60 ) ), 'stored payload can be served' );
}
extrachill_cache_identity_test_assert_same( 2, extrachill_cache_delete_url( 'https://extrachill.link/gavin-hamilton/', 13 ), 'canonical purge deletes both no-slash request variants' );
foreach ( array( 'desktop', 'mobile' ) as $device ) {
	$key = extrachill_cache_key( $identity, $device );
	extrachill_cache_identity_test_assert_same( false, is_file( extrachill_cache_file_path( $key, 13 ) ), 'purged file no longer exists' );
	extrachill_cache_identity_test_assert_same( false, extrachill_cache_read( $key, 13, 60 ), 'no-slash request misses after canonical purge' );
}

extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
fwrite( STDOUT, "All URL identity tests passed.\n" );
