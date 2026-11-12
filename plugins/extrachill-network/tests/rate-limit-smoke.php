<?php
// phpcs:disable
/** Standalone contract and production-cache-path checks. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
	public function __construct( public $code, public $message = '', public $data = array() ) {}
}
class WP_REST_Request {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $message ) { return $message; }
function sanitize_key( $value ) { return $value; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_salt( $scheme ) { return 'test-salt'; }
function apply_filters( $hook, $value ) {
	return 'extrachill_rate_limit_store' === $hook && isset( $GLOBALS['store'] ) ? $GLOBALS['store'] : $value;
}
function wp_using_ext_object_cache() { return $GLOBALS['available']; }
function wp_cache_add( $key, $value, $group, $ttl ) {
	if ( isset( $GLOBALS['cache'][$group][$key] ) ) { return false; }
	$GLOBALS['cache'][$group][$key] = $value;
	return true;
}
function wp_cache_incr( $key, $value, $group ) {
	if ( ! isset( $GLOBALS['cache'][$group][$key] ) || ! empty( $GLOBALS['incr_failed'] ) ) { return false; }
	return $GLOBALS['cache'][$group][$key] += $value;
}
function wp_cache_get( $key, $group ) { return $GLOBALS['cache'][$group][$key] ?? false; }
function wp_cache_delete( $key, $group ) { unset( $GLOBALS['cache'][$group][$key] ); return true; }
function check( $passed, $message ) {
	if ( ! $passed ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/inc/core/rate-limit.php';
require dirname( __DIR__, 2 ) . '/extrachill-api/inc/middleware/public-write-admission.php';
$GLOBALS['available'] = true;
check( 0 === extrachill_rate_limit_count( 'client', 60 ), 'empty count' );
check( true === extrachill_rate_limit_admit( 'client', 2, 60 ), 'first admission' );
check( true === extrachill_rate_limit_admit( 'client', 2, 60 ), 'exact limit admitted' );
$retry = extrachill_rate_limit_admit( 'client', 2, 60 );
check( is_int( $retry ) && 1 <= $retry && 60 >= $retry, 'bounded retry-after' );
check( 3 === extrachill_rate_limit_count( 'client', 60 ), 'rejected attempts counted' );
check( 3 === extrachill_rate_limit_count( 'client', 60 ), 'read-only count' );
check( true === extrachill_rate_limit_admit( 'another-client', 2, 60 ), 'keys isolated' );
check( true === extrachill_rate_limit_reset( 'client', 60 ), 'reset succeeds' );
check( 0 === extrachill_rate_limit_count( 'client', 60 ), 'reset clears count' );
check( true === extrachill_rate_limit_admit( 'client', 2, 60 ), 'admission after reset' );
$GLOBALS['incr_failed'] = true;
check( is_wp_error( extrachill_rate_limit_admit( 'client', 2, 60 ) ), 'failed increment refuses' );
$GLOBALS['incr_failed'] = false;
$GLOBALS['available'] = false;
foreach ( array( 'admit', 'count', 'reset' ) as $operation ) {
	$result = 'admit' === $operation ? extrachill_rate_limit_admit( 'client', 2, 60 ) : call_user_func( 'extrachill_rate_limit_' . $operation, 'client', 60 );
	check( is_wp_error( $result ) && 'rate_limiter_unavailable' === $result->code, 'unavailable ' . $operation );
}
$GLOBALS['available'] = true;
$GLOBALS['store'] = 'not_callable';
check( is_wp_error( extrachill_rate_limit_admit( 'client', 2, 60 ) ), 'invalid store' );
$GLOBALS['store'] = static function () { return false; };
check( is_wp_error( extrachill_rate_limit_admit( 'client', 2, 60 ) ), 'invalid counter result' );
unset( $GLOBALS['store'] );
// The in-memory cache deliberately retains old entries: only the window key changes.
$start = time();
check( true === extrachill_rate_limit_admit( 'rollover', 1, 1 ), 'rollover initial admission' );
while ( $start === time() ) { usleep( 1000 ); }
check( 0 === extrachill_rate_limit_count( 'rollover', 1 ), 'new window starts empty' );
check( true === extrachill_rate_limit_admit( 'rollover', 1, 1 ), 'new window admits' );
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$request = new WP_REST_Request();
foreach ( array( 'read', 'write' ) as $kind ) {
	$adapter = 'extrachill_api_check_public_' . $kind . '_rate_limit';
	check( true === $adapter( $request, 'scope', 1 ), 'adapter admits ' . $kind );
	$error = $adapter( $request, 'scope', 1 );
	check( is_wp_error( $error ) && 'public_' . $kind . '_rate_limited' === $error->code, 'adapter error code ' . $kind );
	check( 429 === $error->data['status'] && (string) $error->data['retry_after'] === $error->data['headers']['Retry-After'], 'adapter retry header' );
	$GLOBALS['available'] = false;
	check( 'api_rate_limiter_unavailable' === $adapter( $request, 'other', 1 )->code, 'adapter unavailable contract' );
	$GLOBALS['available'] = true;
}
echo "rate-limit smoke: passed\n";
