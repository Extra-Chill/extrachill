<?php
// phpcs:ignoreFile -- Standalone WordPress doubles.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
function add_action( ...$args ) {}
function wp_salt( $scheme ) { return 'read-test-secret'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function get_userdata( $id ) { return 42 === $id ? (object) array( 'ID' => 42 ) : false; }
function rest_url( $path ) { return 'https://example.com/wp-json/' . $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
require_once dirname( __DIR__, 2 ) . '/extrachill-network/inc/core/signed-token.php';
require_once dirname( __DIR__ ) . '/inc/notifications/read-redirect.php';
function read_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}
foreach ( array( false, true ) as $all ) {
	$url = $all ? ec_notifications_mark_all_read_url( 'https://example.com/', 42 ) : ec_notifications_read_redirect_url( 9, 'https://example.com/', 42 );
	parse_str( parse_url( $url, PHP_URL_QUERY ), $params );
	read_check( ! isset( $params['uid'], $params['sig'] ), 'new format only' );
	$verified = ec_notifications_read_verify( 0, 0, '', $params['token'], $all );
	read_check( 42 === $verified['user_id'] && ( $all ? 0 : 9 ) === $verified['notification_id'], 'round trip' );
	read_check( null === ec_notifications_read_verify( 0, 0, '', $params['token'], $all, time() + 90 * DAY_IN_SECONDS + 1 ), 'expiry' );
	$sig = $all ? ec_notifications_read_all_signature( 42 ) : ec_notifications_read_signature( 42, 9 );
	read_check( null !== ec_notifications_read_verify( 42, 9, $sig, '', $all, EXTRACHILL_SIGN_LEGACY_UNTIL - 1 ), 'legacy before cutoff' );
	foreach ( array( 0, 1 ) as $offset ) {
		read_check( null === ec_notifications_read_verify( 42, 9, $sig, '', $all, EXTRACHILL_SIGN_LEGACY_UNTIL + $offset ), 'legacy cutoff' );
	}
	read_check( null === ec_notifications_read_verify( 0, 0, '', $params['token'], ! $all ), 'cross purpose' );
	$purpose = $all ? 'users.notification_read_all' : 'users.notification_read';
	$bad = extrachill_sign( $purpose, array( 'user_id' => 99, 'notification_id' => 9 ), 3600 );
	read_check( null === ec_notifications_read_verify( 0, 0, '', $bad, $all ), 'missing user' );
}
echo "PASS: notification read/read-all tokens, expiry, legacy cutoff and purpose separation.\n";
