<?php

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return trim( strip_tags( (string) $text ) );
	}
}
/* Standalone HTTP diagnostics and timeout policy regression test. */
define( 'ABSPATH', __DIR__ );

class WP_Error {
	public function __construct( private $code, private $message, private $data = null ) {}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function add_filter( $hook, $callback, ...$unused ) { $GLOBALS['filters'][ $hook ][] = $callback; }
function apply_filters( $hook, $value, ...$args ) {
	foreach ( $GLOBALS['filters'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function ec_get_site_url( $key ) { return 'https://example.test'; }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function get_current_user_id() { return 0; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_remote_request( $url, $args ) {
	$GLOBALS['last_request'] = $args;
	return apply_filters( 'pre_http_request', false, $args, $url );
}
function wp_remote_retrieve_response_code( $response ) { return $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_retrieve_header( $response, $key ) { return $response['headers'][ $key ] ?? ''; }
function wp_has_ability( $name ) { return 'example/read' === $name || 'example/write' === $name; }
function wp_get_ability( $name ) {
	return new class( $name ) {
		public function __construct( private $name ) {}
		public function get_meta() { return array( 'annotations' => array( 'readonly' => 'example/read' === $this->name ) ); }
	};
}
function check( $condition, $label ) {
	if ( ! $condition ) { fwrite( STDERR, "FAIL: $label\n" ); exit( 1 ); }
}
require __DIR__ . '/../inc/core/cross-site-rest.php';
add_filter( 'pre_http_request', function () { return $GLOBALS['response']; } );
$GLOBALS['response'] = array(
	'response' => array( 'code' => 403 ),
	'body' => json_encode( array( 'code' => 'rest_ability_cannot_execute', 'message' => 'You cannot execute this ability.', 'data' => array( 'status' => 403, 'retryable' => false ) ) ),
);
$path = '/wp-abilities/v1/abilities/example/read/run';
$error = ec_cross_site_rest_request_http( 'events', 'POST', $path );
check( 'rest_ability_cannot_execute' === $error->get_error_code(), 'upstream code' );
check( str_contains( $error->get_error_message(), 'You cannot execute this ability.' ), 'upstream message' );
check( str_contains( $error->get_error_message(), 'events' ) && str_contains( $error->get_error_message(), $path ), 'message context' );
check( 'events' === $error->get_error_data()['site_key'] && $path === $error->get_error_data()['path'], 'error data context' );
check( 403 === $error->get_error_data()['status'] && false === $error->get_error_data()['retryable'], 'status and retry metadata' );
check( 30.0 === $GLOBALS['last_request']['timeout'], 'readonly POST timeout' );
ec_cross_site_rest_request_http( 'events', 'POST', '/wp-abilities/v1/abilities/example/write/run' );
check( 15.0 === $GLOBALS['last_request']['timeout'], 'write timeout unchanged' );
ec_cross_site_rest_request_http( 'events', 'POST', '/wp-abilities/v1/abilities/example/missing/run' );
check( 15.0 === $GLOBALS['last_request']['timeout'], 'unknown ability is not assumed readonly' );
add_filter( 'ec_cross_site_http_timeout', function ( $timeout, $site, $method, $path, $read ) { return $read ? 42 : $timeout; } );
$GLOBALS['response'] = new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 42002 milliseconds' );
$error = ec_cross_site_rest_request_http( 'events', 'GET', '/wp/v2/posts' );
check( 'cross_site_timeout' === $error->get_error_code(), 'distinct timeout code' );
check( 42.0 === $GLOBALS['last_request']['timeout'], 'filtered GET timeout applied' );
check( str_contains( $error->get_error_message(), 'events /wp/v2/posts' ) && str_contains( $error->get_error_message(), '42 seconds' ), 'timeout message context and seconds' );
check( 'events' === $error->get_error_data()['site_key'] && '/wp/v2/posts' === $error->get_error_data()['path'], 'timeout data context' );
ec_cross_site_rest_request_http( 'events', 'GET', '/wp/v2/posts', array( 'timeout' => 7 ) );
check( 7.0 === $GLOBALS['last_request']['timeout'], 'explicit timeout wins' );
$GLOBALS['response'] = new WP_Error( 'http_request_failed', 'Connection refused' );
$error = ec_cross_site_rest_request_http( 'events', 'GET', '/wp/v2/posts' );
check( 'ec_cross_site_request_failed' === $error->get_error_code(), 'other transport errors remain distinct' );
$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ), 'body' => '{"ok":true}' );
check( array( 'ok' => true ) === ec_cross_site_rest_request_http( 'events', 'GET', '/wp/v2/posts' ), 'success unchanged' );
echo "Cross-site HTTP errors smoke test passed.\n";
