<?php
// phpcs:disable
/** Standalone purpose-bound token contract. Run: php tests/signed-token-smoke.php */
define( 'ABSPATH', __DIR__ );
function wp_salt( $scheme ) { return 'signed-token-test-salt'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function check( $passed, $message ) {
	if ( ! $passed ) { throw new RuntimeException( $message ); }
}
require dirname( __DIR__ ) . '/inc/core/signed-token.php';
$claims = array( 'event_id' => 42, 'action' => 'mark', 'nested' => array( 'city' => 'Montréal' ) );
$now = time();
$token = extrachill_sign( 'users.attendance_intent', $claims, 60 );
check( $claims === extrachill_verify( 'users.attendance_intent', $token, $now ), 'round trip' );
check( null === extrachill_verify( 'events.archive_intent', $token, $now ), 'wrong purpose' );
check( null === extrachill_verify( 'users.attendance_intent', 'A' . substr( $token, 1 ), $now ), 'tampered payload' );
check( null === extrachill_verify( 'users.attendance_intent', substr( $token, 0, -1 ) . ( 'a' === substr( $token, -1 ) ? 'b' : 'a' ), $now ), 'tampered signature' );
check( null === extrachill_verify( 'users.attendance_intent', $token, $now + 61 ), 'expiry' );
check( $claims === extrachill_verify( 'test.forever', extrachill_sign( 'test.forever', $claims, 0 ), PHP_INT_MAX ), 'explicit never expires' );
foreach ( array( '', '.', 'bad.token', str_repeat( 'A', 8193 ), $token . '.extra' ) as $bad ) {
	check( null === extrachill_verify( 'users.attendance_intent', $bad ), 'malformed or oversized token' );
}
foreach ( array( '', 'UPPER', 'spaces here', "newline\n", str_repeat( 'a', 65 ) ) as $purpose ) {
	check( '' === extrachill_sign( $purpose, $claims, 60 ), 'invalid signing purpose' );
	check( null === extrachill_verify( $purpose, $token ), 'invalid verifying purpose' );
}
check( '' === extrachill_sign( 'test', $claims, -1 ), 'negative TTL refused' );
// Valid signatures must not bypass envelope shape/type checks.
foreach ( array( 'null', '{"p":"test","c":{},"e":"0"}', '{"p":"other","c":[],"e":0}', '{"p":"test","c":null,"e":0}', '{"p":"test","c":[],"e":-1}', '{"p":"test","c":[],"e":' . $now . '}' ) as $json ) {
	$payload = rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	$key = hash_hmac( 'sha256', 'extrachill-sign:test', wp_salt( 'auth' ) );
	check( null === extrachill_verify( 'test', $payload . '.' . hash_hmac( 'sha256', $payload, $key ), $now ), 'invalid authenticated envelope' );
}
echo "signed-token smoke: passed\n";
