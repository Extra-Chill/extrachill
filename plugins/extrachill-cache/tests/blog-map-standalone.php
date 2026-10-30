<?php
/**
 * Standalone tests for the host->blog_id map generator and its Site Health
 * staleness detector (extrachill.link alias partitioning, GH #21).
 *
 * @package ExtraChillCache
 */

// phpcs:disable -- Standalone test harness intentionally stubs WordPress functions and writes CLI output directly.

define( 'ABSPATH', __DIR__ . '/' );
define( 'WP_CONTENT_DIR', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'EXTRACHILL_CACHE_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EXTRACHILL_CACHE_VERSION', 'test' );

$GLOBALS['extrachill_cache_test_is_multisite']   = true;
$GLOBALS['extrachill_cache_test_sites']          = array();
$GLOBALS['extrachill_cache_test_alias_filters']  = array();
$GLOBALS['extrachill_cache_test_filter_hooks']   = array();
$GLOBALS['extrachill_cache_test_dropin_map_json'] = null;
$GLOBALS['extrachill_cache_test_dropin_exists']   = false;

function is_multisite() {
	return $GLOBALS['extrachill_cache_test_is_multisite'];
}

function get_sites( $args = array() ) {
	return $GLOBALS['extrachill_cache_test_sites'];
}

function apply_filters( $hook, $value, ...$args ) {
	if ( isset( $GLOBALS['extrachill_cache_test_alias_filters'][ $hook ] ) ) {
		return $GLOBALS['extrachill_cache_test_alias_filters'][ $hook ];
	}
	return $value;
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['extrachill_cache_test_filter_hooks'][ $hook ] = $callback;
}

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Standalone test stub for the WP core function under test.
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return $text;
}

require dirname( __DIR__ ) . '/inc/dropin-installer.php';
require dirname( __DIR__ ) . '/inc/site-health.php';

function extrachill_cache_test_site( $domain, $blog_id ) {
	return (object) array(
		'domain'  => $domain,
		'blog_id' => $blog_id,
	);
}

function extrachill_cache_test_assert_same( $expected, $actual, $message ) {
	if ( $expected !== $actual ) {
		fwrite( STDERR, "FAIL: {$message}\nExpected: " . var_export( $expected, true ) . "\nActual: " . var_export( $actual, true ) . "\n" );
		exit( 1 );
	}
}

// ---------------------------------------------------------------------------
// extrachill_cache_build_blog_map(): wp_blogs rows only, no aliases declared.
// ---------------------------------------------------------------------------
$GLOBALS['extrachill_cache_test_sites'] = array(
	extrachill_cache_test_site( 'extrachill.com', 1 ),
	extrachill_cache_test_site( 'artist.extrachill.com', 4 ),
);
$GLOBALS['extrachill_cache_test_alias_filters'] = array();

extrachill_cache_test_assert_same(
	array(
		'extrachill.com'         => 1,
		'artist.extrachill.com'  => 4,
	),
	extrachill_cache_build_blog_map(),
	'map contains only wp_blogs rows when no aliases are declared'
);

// ---------------------------------------------------------------------------
// A declared alias (the extrachill.link scenario) is merged into the map.
// ---------------------------------------------------------------------------
$GLOBALS['extrachill_cache_test_alias_filters']['extrachill_cache_dropin_alias_hosts'] = array(
	'extrachill.link'     => 4,
	'www.extrachill.link' => 4,
);

extrachill_cache_test_assert_same(
	array(
		'extrachill.com'        => 1,
		'artist.extrachill.com' => 4,
		'extrachill.link'       => 4,
		'www.extrachill.link'   => 4,
	),
	extrachill_cache_build_blog_map(),
	'declared aliases are merged into the generated host->blog_id map'
);

// ---------------------------------------------------------------------------
// Malformed alias entries (empty host, non-positive blog_id) are ignored.
// ---------------------------------------------------------------------------
$GLOBALS['extrachill_cache_test_alias_filters']['extrachill_cache_dropin_alias_hosts'] = array(
	''                => 4,
	'bad.example.com' => 0,
	'ok.example.com'  => 4,
);

extrachill_cache_test_assert_same(
	array(
		'extrachill.com'        => 1,
		'artist.extrachill.com' => 4,
		'ok.example.com'        => 4,
	),
	extrachill_cache_build_blog_map(),
	'malformed alias entries (empty host / non-positive blog_id) are dropped'
);

// A non-array filter return value is ignored entirely rather than fataling.
$GLOBALS['extrachill_cache_test_alias_filters']['extrachill_cache_dropin_alias_hosts'] = 'not-an-array';

extrachill_cache_test_assert_same(
	array(
		'extrachill.com'        => 1,
		'artist.extrachill.com' => 4,
	),
	extrachill_cache_build_blog_map(),
	'a non-array alias filter return value is ignored'
);

$GLOBALS['extrachill_cache_test_alias_filters'] = array();

// ---------------------------------------------------------------------------
// Site Health registration: the test is added under the 'direct' bucket.
// ---------------------------------------------------------------------------
extrachill_cache_test_assert_same(
	true,
	isset( $GLOBALS['extrachill_cache_test_filter_hooks']['site_status_tests'] ),
	'blog map freshness test is registered against site_status_tests'
);

$registered = call_user_func( $GLOBALS['extrachill_cache_test_filter_hooks']['site_status_tests'], array( 'direct' => array(), 'async' => array() ) );
extrachill_cache_test_assert_same(
	true,
	isset( $registered['direct']['extrachill_cache_blog_map_freshness'] ),
	'freshness test is registered in the direct (synchronous) bucket, matching the extrachill-search pattern'
);

// ---------------------------------------------------------------------------
// extrachill_cache_get_blog_map_drift(): no drop-in installed.
// ---------------------------------------------------------------------------
$result = extrachill_cache_blog_map_site_health_test();
extrachill_cache_test_assert_same( 'recommended', $result['status'], 'no drop-in installed reports a recommended (non-critical) status' );

// ---------------------------------------------------------------------------
// Drift detection: baked map matches live map exactly.
// ---------------------------------------------------------------------------
define( 'EXTRACHILL_CACHE_DROPIN_BLOG_MAP', wp_json_encode( array(
	'extrachill.com'        => 1,
	'artist.extrachill.com' => 4,
) ) );

$drift = extrachill_cache_get_blog_map_drift();
extrachill_cache_test_assert_same( true, $drift['installed'], 'baked map is detected as installed once the constant is defined' );
extrachill_cache_test_assert_same( false, $drift['stale'], 'identical baked and live maps are not stale' );

$result = extrachill_cache_blog_map_site_health_test();
extrachill_cache_test_assert_same( 'good', $result['status'], 'a fresh map reports a good Site Health status' );

// ---------------------------------------------------------------------------
// Drift detection: a live alias is missing from the baked map (the exact
// extrachill.link regression this issue is about).
// ---------------------------------------------------------------------------
$GLOBALS['extrachill_cache_test_alias_filters']['extrachill_cache_dropin_alias_hosts'] = array(
	'extrachill.link' => 4,
);

$drift = extrachill_cache_get_blog_map_drift();
extrachill_cache_test_assert_same( true, $drift['stale'], 'a live alias absent from the baked map is detected as drift' );
extrachill_cache_test_assert_same( array( 'extrachill.link' ), $drift['missing'], 'the missing host is identified by name' );
extrachill_cache_test_assert_same( array(), $drift['changed'], 'no changed hosts when the only diff is a missing addition' );
extrachill_cache_test_assert_same( array(), $drift['removed'], 'no removed hosts when the only diff is a missing addition' );

$result = extrachill_cache_blog_map_site_health_test();
extrachill_cache_test_assert_same( 'critical', $result['status'], 'a stale map reports a critical Site Health status' );

fwrite( STDOUT, "All blog map tests passed.\n" );
