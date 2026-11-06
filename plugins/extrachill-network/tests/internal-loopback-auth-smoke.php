<?php
/**
 * Standalone security tests for the HTTP-loopback internal user assertion (#37).
 *
 * Covers the purpose-derived key, the signed nonce, single-use consumption,
 * fail-closed replay storage, and the localhost restriction.
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['ila_current_user'] = 0;

class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = null ) {}
}

class wpdb {}

function add_filter( ...$args ) {}
function add_action( ...$args ) {}
function wp_salt( $scheme = 'auth' ) { return 'test-network-secret'; }
function sanitize_text_field( $value ) { return (string) $value; }
function get_main_site_id() { return 1; }
function get_user_by( $field, $id ) { return 41 === (int) $id ? (object) array( 'ID' => 41 ) : false; }
function wp_set_current_user( $id ) { $GLOBALS['ila_current_user'] = (int) $id; }

require_once __DIR__ . '/../inc/core/cross-site-rest.php';

$failures = 0;
function ila_assert( bool $condition, string $message ): void {
	global $failures;
	if ( $condition ) {
		echo "PASS: {$message}\n";
		return;
	}
	++$failures;
	echo "FAIL: {$message}\n";
}

/** Fake wpdb whose INSERT IGNORE honors a unique option_name. */
function ila_reset(): void {
	$_SERVER                     = array( 'REMOTE_ADDR' => '127.0.0.1' );
	$GLOBALS['ila_current_user'] = 0;
	$GLOBALS['wpdb']             = new class() extends wpdb {
		public $last_error = '';
		public $seen       = array();
		public $fail       = false;
		public function get_blog_prefix( $id ) { return 'wp_'; }
		public function esc_like( $value ) { return addcslashes( $value, '_%' ); }
		public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
		public function query( $query ) {
			if ( $this->fail ) {
				return false;
			}
			if ( str_starts_with( $query[0], 'DELETE' ) ) {
				return 0;
			}
			$key = $query[1][1]; // [0] is the %i table identifier.
			if ( isset( $this->seen[ $key ] ) ) {
				return 0;
			}
			$this->seen[ $key ] = true;
			return 1;
		}
	};
}

/** Build signed headers for user 41 and expose them as request headers. */
function ila_send(): array {
	$headers = ec_cross_site_build_auth_headers( 41 );
	foreach ( $headers as $key => $value ) {
		$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', $key ) ) ] = $value;
	}
	return $headers;
}

// A valid assertion authenticates once; verification stays side-effect free.
ila_reset();
$headers = ila_send();
ila_assert( true === ec_cross_site_authenticate_internal_request( null ), 'valid assertion authenticates' );
ila_assert( 41 === $GLOBALS['ila_current_user'], 'valid assertion sets the asserted user' );
ila_assert( ec_cross_site_verify_signature( 41, (int) $headers['X-EC-Internal-Timestamp'], $headers['X-EC-Internal-Signature'] ), 'signature verification is pure (does not consume)' );
ila_assert( ec_cross_site_authenticate_internal_request( null ) instanceof WP_Error, 'replayed assertion is rejected' );

// The nonce is required and covered by the signature.
ila_reset();
ila_send();
$_SERVER['HTTP_X_EC_INTERNAL_NONCE'] = str_repeat( 'a', 32 );
ila_assert( ec_cross_site_authenticate_internal_request( null ) instanceof WP_Error, 'swapped nonce is rejected' );
unset( $_SERVER['HTTP_X_EC_INTERNAL_NONCE'] );
ila_assert( ec_cross_site_authenticate_internal_request( null ) instanceof WP_Error, 'missing nonce is rejected' );
ila_assert( 0 === $GLOBALS['ila_current_user'], 'rejected nonces never set a user' );

// The key is derived for this purpose, never AUTH_SALT directly; expiry holds.
$nonce = str_repeat( 'b', 32 );
$now   = time();
$sig   = ec_cross_site_sign_request( 41, $now, $nonce );
ila_assert( hash_hmac( 'sha256', "ec-internal:41:$now", wp_salt( 'auth' ) ) !== $sig, 'legacy AUTH_SALT signature is not accepted' );
ila_assert( hash_hmac( 'sha256', "ec-internal-v2:41:$now:$nonce", wp_salt( 'auth' ) ) !== $sig, 'raw salt is not used as the HMAC key' );
ila_assert( ! ec_cross_site_verify_signature( 41, $now - 301, ec_cross_site_sign_request( 41, $now - 301, $nonce ), $nonce ), 'expired assertion fails' );
ila_assert( ! ec_cross_site_verify_signature( 41, $now + 301, ec_cross_site_sign_request( 41, $now + 301, $nonce ), $nonce ), 'future-dated assertion fails' );

// Localhost alone is not authority; non-local callers are ignored; storage failure fails closed.
ila_reset();
ila_assert( null === ec_cross_site_authenticate_internal_request( null ), 'localhost without an assertion does nothing' );
ila_send();
$_SERVER['REMOTE_ADDR'] = '203.0.113.1';
ila_assert( null === ec_cross_site_authenticate_internal_request( null ), 'non-local caller is ignored' );
$_SERVER['REMOTE_ADDR'] = '::1';
$GLOBALS['wpdb']->fail  = true;
ila_assert( ec_cross_site_authenticate_internal_request( null ) instanceof WP_Error, 'replay-store failure denies authentication' );
ila_assert( 0 === $GLOBALS['ila_current_user'], 'replay-store failure never sets a user' );

// Each assertion is unique, and earlier authentication results are preserved.
ila_reset();
$first  = ila_send();
$second = ec_cross_site_build_auth_headers( 41 );
ila_assert( $first['X-EC-Internal-Nonce'] !== $second['X-EC-Internal-Nonce'], 'each assertion carries a fresh nonce' );
ila_assert( true === ec_cross_site_authenticate_internal_request( true ), 'existing authentication result is preserved' );
ila_assert( 0 === $GLOBALS['ila_current_user'], 'preserved result does not switch user' );

if ( $failures > 0 ) {
	echo "\n{$failures} failure(s)\n";
	exit( 1 );
}
echo "\nAll internal loopback auth checks passed.\n";
