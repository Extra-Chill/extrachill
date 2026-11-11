<?php
/**
 * Standalone tests for per-entry expiry from Cache-Control lifetimes.
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress helpers and writes CLI output directly.

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EXTRACHILL_CACHE_TTL', 3600 );
define( 'EXTRACHILL_CACHE_DIR', sys_get_temp_dir() . '/extrachill-cache-expiry-tests-' . getmypid() );

$GLOBALS['extrachill_cache_test_options'] = array();

function absint( $value ) {
	return abs( (int) $value );
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {}

function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, $args );
}

function get_option( $name, $default = false ) {
	return $GLOBALS['extrachill_cache_test_options'][ $name ] ?? $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['extrachill_cache_test_options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['extrachill_cache_test_options'][ $name ] );
	return true;
}

require dirname( __DIR__ ) . '/inc/cache-store.php';
require dirname( __DIR__ ) . '/inc/gc.php';

function ec_expiry_assert( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
		exit( 1 );
	}
}

function ec_expiry_read_raw( $key ) {
	return unserialize( file_get_contents( extrachill_cache_file_path( $key, 1 ) ) );
}

$body = '<html><body>events</body></html>';

// --- Lifetime parsing -------------------------------------------------------
ec_expiry_assert( null, extrachill_cache_response_lifetime( array( 'Content-Type: text/html' ) ), 'no Cache-Control declares no lifetime' );
ec_expiry_assert( null, extrachill_cache_response_lifetime( array( 'Cache-Control: public' ) ), 'Cache-Control without a lifetime declares none' );
ec_expiry_assert( 300, extrachill_cache_response_lifetime( array( 'Cache-Control: public, max-age=300' ) ), 'max-age is honoured' );
ec_expiry_assert( 120, extrachill_cache_response_lifetime( array( 'cache-control: max-age=300, S-MAXAGE=120' ) ), 's-maxage wins over max-age, case-insensitively' );
ec_expiry_assert( 600, extrachill_cache_response_lifetime( array( 'Cache-Control: max-age=60, s-maxage=600' ) ), 's-maxage wins even when longer' );
ec_expiry_assert( 60, extrachill_cache_response_lifetime( array( 'Cache-Control: max-age=600', 'Cache-Control: max-age=60' ) ), 'repeated max-age uses the shortest' );
ec_expiry_assert( 90, extrachill_cache_response_lifetime( array( array( 'name' => 'Cache-Control', 'value' => 'max-age="90"' ) ) ), 'name/value header arrays and quoted values parse' );
ec_expiry_assert( false, extrachill_cache_response_lifetime( array( 'Cache-Control: no-store' ) ), 'no-store is not stored' );
ec_expiry_assert( false, extrachill_cache_response_lifetime( array( 'Cache-Control: private, max-age=300' ) ), 'private is not stored' );
ec_expiry_assert( false, extrachill_cache_response_lifetime( array( 'Cache-Control: max-age=0' ) ), 'zero lifetime is not stored' );
ec_expiry_assert( null, extrachill_cache_response_lifetime( array( 'Cache-Control: max-age=abc' ) ), 'malformed max-age is ignored' );

// --- Store path -------------------------------------------------------------
$now = time();

$key_short = extrachill_cache_key( 'https://events.example.com/artist/a/', 'desktop' );
ec_expiry_assert( true, extrachill_cache_store_page( $key_short, $body, array( 'Content-Type: text/html', 'Cache-Control: max-age=300' ), 1, $now ), 'max-age response is stored' );
$raw = ec_expiry_read_raw( $key_short );
ec_expiry_assert( $now + 300, $raw['expires'], 'stored expiry is now + max-age' );
ec_expiry_assert( array( array( 'name' => 'Content-Type', 'value' => 'text/html' ) ), $raw['headers'], 'Cache-Control itself is not replayed' );
ec_expiry_assert( $now + 300, extrachill_cache_file_expires( extrachill_cache_file_path( $key_short, 1 ) ), 'expiry is readable from the file head' );

$key_long = extrachill_cache_key( 'https://events.example.com/artist/b/', 'desktop' );
extrachill_cache_store_page( $key_long, $body, array( 'Cache-Control: max-age=999999' ), 1, $now );
ec_expiry_assert( $now + EXTRACHILL_CACHE_TTL, ec_expiry_read_raw( $key_long )['expires'], 'lifetime is capped at EXTRACHILL_CACHE_TTL' );

$key_plain = extrachill_cache_key( 'https://events.example.com/', 'desktop' );
extrachill_cache_store_page( $key_plain, $body, array( 'Content-Type: text/html' ), 1, $now );
ec_expiry_assert( false, array_key_exists( 'expires', ec_expiry_read_raw( $key_plain ) ), 'no lifetime stores no per-entry expiry' );
ec_expiry_assert( 0, extrachill_cache_file_expires( extrachill_cache_file_path( $key_plain, 1 ) ), 'entry without expiry reports 0' );

$key_nostore = extrachill_cache_key( 'https://events.example.com/private/', 'desktop' );
ec_expiry_assert( false, extrachill_cache_store_page( $key_nostore, $body, array( 'Cache-Control: no-store' ), 1, $now ), 'no-store response is not stored' );
ec_expiry_assert( false, is_file( extrachill_cache_file_path( $key_nostore, 1 ) ), 'no-store leaves no file' );
ec_expiry_assert( false, extrachill_cache_store_page( $key_nostore, $body, array( 'Cache-Control: private' ), 1, $now ), 'private response is not stored' );

// --- Serve path -------------------------------------------------------------
ec_expiry_assert( true, is_array( extrachill_cache_read( $key_short, 1, EXTRACHILL_CACHE_TTL ) ), 'entry before its expiry is a HIT' );
ec_expiry_assert( true, is_array( extrachill_cache_read( $key_plain, 1, EXTRACHILL_CACHE_TTL ) ), 'entry without expiry uses the global TTL' );

// Expired per-entry, but the file is only seconds old (well within the global TTL).
$key_expired = extrachill_cache_key( 'https://events.example.com/artist/c/', 'desktop' );
extrachill_cache_store_page( $key_expired, $body, array( 'Cache-Control: max-age=60' ), 1, $now - 120 );
$expired_path = extrachill_cache_file_path( $key_expired, 1 );
ec_expiry_assert( true, ( time() - filemtime( $expired_path ) ) < EXTRACHILL_CACHE_TTL, 'expired entry is still within the global TTL by mtime' );
ec_expiry_assert( false, extrachill_cache_read( $key_expired, 1, EXTRACHILL_CACHE_TTL ), 'expired entry within the global TTL is a MISS' );
ec_expiry_assert( false, is_file( $expired_path ), 'expired entry is evicted on read' );

// Global TTL still applies to entries with a longer per-entry expiry.
touch( extrachill_cache_file_path( $key_long, 1 ), time() - EXTRACHILL_CACHE_TTL - 10 );
ec_expiry_assert( false, extrachill_cache_read( $key_long, 1, EXTRACHILL_CACHE_TTL ), 'global TTL still bounds entries with an expiry' );

// --- GC ---------------------------------------------------------------------
extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );

$gc_fresh   = extrachill_cache_key( 'https://events.example.com/gc/fresh/', 'desktop' );
$gc_plain   = extrachill_cache_key( 'https://events.example.com/gc/plain/', 'desktop' );
$gc_expired = extrachill_cache_key( 'https://events.example.com/gc/expired/', 'desktop' );
$gc_old     = extrachill_cache_key( 'https://events.example.com/gc/old/', 'desktop' );

extrachill_cache_store_page( $gc_fresh, $body, array( 'Cache-Control: max-age=300' ), 1 );
extrachill_cache_store_page( $gc_plain, $body, array(), 1 );
extrachill_cache_store_page( $gc_expired, $body, array( 'Cache-Control: max-age=60' ), 1, time() - 120 );
extrachill_cache_store_page( $gc_old, $body, array(), 1 );
touch( extrachill_cache_file_path( $gc_old, 1 ), time() - EXTRACHILL_CACHE_TTL - 10 );

$dry = extrachill_cache_gc( array( 'dry_run' => true ) );
ec_expiry_assert( 2, $dry['deleted'], 'GC dry run counts per-entry-expired and TTL-expired files' );
ec_expiry_assert( true, is_file( extrachill_cache_file_path( $gc_expired, 1 ) ), 'GC dry run deletes nothing' );

$stats = extrachill_cache_gc();
ec_expiry_assert( 2, $stats['deleted'], 'GC deletes per-entry-expired and TTL-expired files' );
ec_expiry_assert( true, $stats['completed'], 'GC walk completes' );
ec_expiry_assert( false, is_file( extrachill_cache_file_path( $gc_expired, 1 ) ), 'GC removes an entry past its per-entry expiry within the global TTL' );
ec_expiry_assert( false, is_file( extrachill_cache_file_path( $gc_old, 1 ) ), 'GC removes an entry past the global TTL' );
ec_expiry_assert( true, is_file( extrachill_cache_file_path( $gc_fresh, 1 ) ), 'GC keeps an entry before its per-entry expiry' );
ec_expiry_assert( true, is_file( extrachill_cache_file_path( $gc_plain, 1 ) ), 'GC keeps a fresh entry without per-entry expiry' );

extrachill_cache_rrmdir( EXTRACHILL_CACHE_DIR );
fwrite( STDOUT, "All expiry tests passed.\n" );
