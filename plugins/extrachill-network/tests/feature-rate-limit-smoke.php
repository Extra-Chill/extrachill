<?php
/** Standalone coverage of feature adapters to the shared fixed-window primitive. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
class WP_Error {
	public function __construct( public $code, public $message = '', public $data = array() ) {}
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function apply_filters( $hook, $value ) {
	return 'extrachill_rate_limit_store' === $hook ? 'feature_store' : $value;
}
function extrachill_analytics_get_client_ip() { return '203.0.113.10'; }
function wp_salt( $scheme ) { return 'test-salt'; }
function feature_store( $key, $ttl, $operation ) {
	if ( ! empty( $GLOBALS['unavailable'] ) ) {
		return new WP_Error( 'rate_limiter_unavailable' );
	}
	$count = $GLOBALS['counts'][ $key ] ?? 0;
	if ( 'increment' === $operation ) {
		$GLOBALS['counts'][ $key ] = ++$count;
	}
	return $count;
}
function check_feature( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
require dirname( __DIR__ ) . '/inc/core/rate-limit.php';
require dirname( __DIR__, 2 ) . '/extrachill-analytics/inc/core/write-integrity.php';
for ( $i = 0; $i < 240; ++$i ) {
	check_feature( true === extrachill_analytics_check_public_write_rate_limit(), 'Analytics admits through 240.' );
}
$error = extrachill_analytics_check_public_write_rate_limit();
check_feature( 'analytics_write_rate_limited' === $error->code && 429 === $error->data['status'], 'Analytics preserves its 429 contract.' );
$GLOBALS['unavailable'] = true;
$error = extrachill_analytics_check_public_write_rate_limit();
check_feature( 'analytics_write_limiter_unavailable' === $error->code && 503 === $error->data['status'], 'Analytics preserves unavailable errors.' );
echo "PASS: analytics adapter uses shared admission and fail closed.\n";
