<?php
/** Standalone public QR access contract: php tests/qr-code-public-access-smoke.php. */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
$GLOBALS['qr_user'] = 0;
$GLOBALS['qr_transients'] = array();

class WP_Error {
	public function __construct( public $code, public $message, public $data = array() ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
}
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function __( $text, $domain = '' ) { return $text; }
function wp_unslash( $value ) { return $value; }
function get_current_user_id() { return $GLOBALS['qr_user']; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function absint( $value ) { return abs( (int) $value ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_transient( $key ) {
	$entry = $GLOBALS['qr_transients'][ $key ] ?? null;
	return $entry && $entry['until'] > time() ? $entry['value'] : false;
}
function set_transient( $key, $value, $ttl ) {
	$GLOBALS['qr_transients'][ $key ] = array( 'value' => $value, 'until' => time() + $ttl );
	return true;
}
function check( $condition, $message ) {
	echo ( $condition ? 'PASS' : 'FAIL' ) . ': ' . $message . PHP_EOL;
	if ( ! $condition ) { exit( 1 ); }
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require dirname( __DIR__ ) . '/inc/Abilities/QRCodeAbility.php';
$ability = new ExtraChillNetwork\Abilities\QRCodeAbility();
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
check( $ability->check_permission(), 'anonymous allowed' );
$result = $ability->execute( array( 'url' => 'https://example.com', 'size' => 100 ) );
check( is_array( $result ) && $result['mime_type'] === 'image/png', 'anonymous generates real PNG' );
foreach ( array( '', 'not a url', 'ftp://example.com', 'javascript:alert(1)', 'data:text/plain,test', '//example.com' ) as $url ) {
	$result = $ability->execute( array( 'url' => $url ) );
	check( is_wp_error( $result ) && $result->get_error_data()['status'] === 400, 'reject invalid/non-http URL: ' . $url );
}
check( is_wp_error( $ability->execute( array( 'url' => 'https://example.com/' . str_repeat( 'x', 2048 ) ) ) ), 'URL length bounded' );
for ( $i = 1; $i < 30; ++$i ) {
	check( is_array( $ability->execute( array( 'url' => 'http://example.com', 'size' => 100 ) ) ), 'within IP limit: ' . ( $i + 1 ) );
}
$result = $ability->execute( array( 'url' => 'https://example.com' ) );
check( is_wp_error( $result ) && $result->get_error_data()['status'] === 429, '31st request returns 429' );
$GLOBALS['qr_user'] = 42;
check( $ability->check_permission(), 'logged-in allowed' );
check( is_wp_error( $ability->execute( array( 'url' => 'https://example.com' ) ) ), 'login does not bypass IP quota' );
$GLOBALS['qr_transients'] = array();
for ( $i = 0; $i < 30; ++$i ) {
	$_SERVER['REMOTE_ADDR'] = '192.0.2.' . ( $i + 2 );
	check( is_array( $ability->execute( array( 'url' => 'https://example.com', 'size' => 100 ) ) ), 'logged-in/Studio response compatible' );
}
$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
$result = $ability->execute( array( 'url' => 'https://example.com' ) );
check( is_wp_error( $result ) && $result->get_error_data()['status'] === 429, 'per-user limit survives IP changes' );
foreach ( $GLOBALS['qr_transients'] as &$entry ) {
	$entry['value']['expires'] = time() - 1;
}
unset( $entry );
check( is_array( $ability->execute( array( 'url' => 'https://example.com', 'size' => 100 ) ) ), 'expired window resets' );
echo "PASS: public QR access smoke complete\n";
