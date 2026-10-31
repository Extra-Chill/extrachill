<?php
/** Standalone OAuth unit tests: php tests/oauth/run.php (PHP OpenSSL required). */
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed, Generic.CodeAnalysis.UnusedFunctionParameter, WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- standalone test harness stubs WordPress functions.
define( 'ABSPATH', __DIR__ );
class WP_Error {
	public function __construct( public $code, public $message, public $data = array() ) {}
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }
function sanitize_text_field( $v ) { return trim( strip_tags( $v ) ); }
function sanitize_email( $v ) { return filter_var( $v, FILTER_SANITIZE_EMAIL ); }
function esc_url_raw( $v ) { return $v; }
function absint( $v ) { return abs( (int) $v ); }
function get_site_option( $k, $default_value = '' ) { return $GLOBALS['options'][ $k ] ?? $default_value; }
function get_transient( $k ) { return false; }
function set_transient( $k, $v, $ttl ) {}
function wp_remote_get( $url, $args ) {
	check( in_array( $url, array( 'https://appleid.apple.com/auth/keys', EC_GOOGLE_JWKS_URL ), true ), 'JWKS URL' );
	return array( 'keys' => array( $GLOBALS['jwk'] ) );
}
function wp_remote_retrieve_response_code( $r ) { return 200; }
function wp_remote_retrieve_body( $r ) { return json_encode( $r ); }
function wp_remote_retrieve_header( $r, $h ) { return 'max-age=3600'; }
function get_users( $args ) {
	foreach ( $GLOBALS['users'] as $u ) {
		if ( ( $GLOBALS['meta'][ $u->ID ][ $args['meta_key'] ] ?? '' ) === $args['meta_value'] ) {
			return array( $u ); }
	}
	return array();
}
function get_user_by( $field, $v ) {
	foreach ( $GLOBALS['users'] as $u ) {
		if ( ( 'email' === $field ? $u->user_email : $u->ID ) === $v ) {
			return $u; }
	}
	return false;
}
function update_user_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = $v; }
function wp_update_user( $data ) { $GLOBALS['users'][ $data['ID'] ]->display_name = $data['display_name']; }
function wp_generate_password( ...$args ) { return 'random-password'; }
function wp_rand( $min, $max ) { return 12345; }
function ec_generate_username_from_name( $name ) { return strtolower( str_replace( ' ', '', $name ) ); }
function ec_generate_username_from_email( $email ) { return strstr( $email, '@', true ); }
function apply_filters( $hook, $v, ...$args ) {
	if ( 'extrachill_create_community_user' === $hook ) {
		$GLOBALS['created'] = $args[0];
		return add_test_user( $args[0]['email'], $args[0]['username'] )->ID;
	}
	return $GLOBALS['filters'][ $hook ] ?? $v;
}
function do_action( $hook, ...$args ) { $GLOBALS['actions'][ $hook ][] = $args; }
function extrachill_users_is_uuid_v4( $v ) { return (bool) preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $v ); }
function ec_get_blog_id( $v ) { return 2; }
function is_user_member_of_blog( ...$args ) { return $GLOBALS['member']; }
function extrachill_users_record_registration_newsletter_consent( ...$args ) { $GLOBALS['consent'][] = $args; }
function wp_native_auth_generate_access_token( ...$args ) {
	$GLOBALS['access'][] = $args;
	return array(
		'token'      => 'access',
		'expires_at' => 2000000000,
	);
}
function wp_native_auth_issue_refresh_token( ...$args ) {
	$GLOBALS['refresh'][] = $args;
	return array(
		'token'      => 'refresh',
		'expires_at' => 2000001000,
	);
}
function ec_is_onboarding_complete( $id ) { return false; }
function ec_users_is_valid_return_to_url( $url ) { return str_starts_with( $url, 'https://community.extrachill.com/' ); }
function ec_users_create_account_created_token( $id ) { return 'created'; }
function ec_users_post_registration_redirect_url( $join, $complete, $url, $token ) { return $url ? $url : 'https://community.extrachill.com/'; }
function get_avatar_url( ...$args ) { return 'avatar'; }
function wp_set_current_user( ...$args ) { ++$GLOBALS['cookies']; }
function wp_set_auth_cookie( ...$args ) { ++$GLOBALS['cookies']; }
require dirname( __DIR__, 2 ) . '/inc/oauth/jwt-rs256.php';
require dirname( __DIR__, 2 ) . '/inc/oauth/google-service.php';
require dirname( __DIR__, 2 ) . '/inc/oauth/apple-service.php';

function check( $condition, $label ) {
	if ( ! $condition ) {
		throw new RuntimeException( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI test failure label.
	}
	$GLOBALS['checks'] = ( $GLOBALS['checks'] ?? 0 ) + 1;
}
function add_test_user( $email, $name = 'existing' ) {
	$id                      = count( $GLOBALS['users'] ) + 1;
	$GLOBALS['users'][ $id ] = (object) array(
		'ID'           => $id,
		'user_email'   => $email,
		'user_login'   => $name,
		'display_name' => $name,
	);
	return $GLOBALS['users'][ $id ];
}
function reset_accounts() {
	foreach ( array( 'users', 'meta', 'filters', 'actions', 'consent', 'access', 'refresh' ) as $key ) {
		$GLOBALS[ $key ] = array();
	}
	$GLOBALS['member']  = true;
	$GLOBALS['cookies'] = 0;
}
function b64( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url encoding for JWT fixtures.
}
function jwt( $claims ) {
	$body = b64( json_encode( array(
		'alg' => 'RS256',
		'kid' => 'local',
	) ) ) . '.' . b64( json_encode( $claims ) );
	openssl_sign( $body, $signature, $GLOBALS['key'], OPENSSL_ALGO_SHA256 );
	return $body . '.' . b64( $signature );
}
$GLOBALS['key']     = openssl_pkey_new( array(
	'private_key_bits' => 2048,
	'private_key_type' => OPENSSL_KEYTYPE_RSA,
) );
$details            = openssl_pkey_get_details( $GLOBALS['key'] );
$GLOBALS['jwk']     = array(
	'kid' => 'local',
	'kty' => 'RSA',
	'n'   => b64( $details['rsa']['n'] ),
	'e'   => b64( $details['rsa']['e'] ),
);
$GLOBALS['options'] = array(
	'extrachill_apple_bundle_id'  => 'com.extrachill.app',
	'extrachill_apple_client_id'  => 'com.extrachill.web',
	'extrachill_google_client_id' => 'google-client',
);
$claims             = array(
	'sub'              => 'apple-sub',
	'aud'              => 'com.extrachill.app',
	'iss'              => 'https://appleid.apple.com',
	'exp'              => time() + 3600,
	'iat'              => time(),
	'nonce'            => hash( 'sha256', 'raw-nonce' ),
	'email'            => 'relay@privaterelay.appleid.com',
	'email_verified'   => true,
	'is_private_email' => 'true',
);
foreach ( array( true, 'true', false, 'false', 1 ) as $verified ) {
	$c = array_merge( $claims, array(
		'email_verified'   => $verified,
		'is_private_email' => $verified,
	) );
	$r = ec_verify_apple_token( jwt( $c ), 'raw-nonce' );
	check( ! is_wp_error( $r ) && in_array( $verified, array( true, 'true' ), true ) === $r['email_verified'] && $r['is_private_email'] === $r['email_verified'], 'strict Apple boolean normalization' );
}
foreach ( array( '', 'wrong-nonce' ) as $nonce ) {
	check( is_wp_error( ec_verify_apple_token( jwt( $claims ), $nonce ) ), 'required matching raw nonce' ); }
foreach ( array( 'nonce', 'sub' ) as $missing ) {
	$c = $claims;
	unset( $c[ $missing ] );
	check( is_wp_error( ec_verify_apple_token( jwt( $c ), 'raw-nonce' ) ), 'required claim ' . $missing );
}
foreach ( array( 'com.extrachill.app', 'com.extrachill.web', 'unknown' ) as $aud ) {
	$c = array_merge( $claims, array( 'aud' => $aud ) );
	check( is_wp_error( ec_verify_apple_token( jwt( $c ), 'raw-nonce' ) ) === ( 'unknown' === $aud ), 'audience allowlist' );
}
$c = array_merge( $claims, array( 'aud' => array( 'unknown', 'com.extrachill.app' ) ) );
check( ! is_wp_error( ec_verify_apple_token( jwt( $c ), 'raw-nonce' ) ), 'array token audience' );
$tampered = jwt( $claims );
$parts    = explode( '.', $tampered );
$parts[1] = b64( json_encode( array_merge( $claims, array( 'sub' => 'forged' ) ) ) );
check( is_wp_error( ec_verify_apple_token( implode( '.', $parts ), 'raw-nonce' ) ), 'forged signature rejected' );
foreach ( array( array( 'exp' => time() - 60 ), array( 'iat' => time() + 600 ), array( 'iss' => 'https://evil.example' ) ) as $override ) {
	check( is_wp_error( ec_verify_apple_token( jwt( array_merge( $claims, $override ) ), 'raw-nonce' ) ), 'registered JWT claim validation' );
}
$identity = ec_verify_apple_token( jwt( $claims ), 'raw-nonce' );
reset_accounts();
$linked = add_test_user( 'different@example.com' );
ec_link_apple_account( $linked->ID, 'apple-sub' );
$other = add_test_user( $identity['email'] );
$r     = ec_oauth_apple_user( $identity, array( 'given_name' => 'Untrusted' ), array() );
check( $r['user_id'] === $linked->ID && 'existing' === $r['user']->display_name, 'subject takes precedence; profile never updates linked users' );
$no_email = $identity;
unset( $no_email['email'] );
check( ec_oauth_apple_user( $no_email, array(), array() )['user_id'] === $linked->ID, 'linked subject without email' );
reset_accounts();
check( is_wp_error( ec_oauth_apple_user( $no_email, array(), array() ) ), 'unlinked subject requires email' );
$existing = add_test_user( $identity['email'] );
$GLOBALS['filters']['ec_apple_auto_link_verified_email'] = false;
$r = ec_oauth_apple_user( $identity, array(), array() );
check( is_wp_error( $r ) && 409 === $r->data['status'], 'disabled email linking conflict' );
$GLOBALS['filters'] = array();
$unverified         = array_merge( $identity, array( 'email_verified' => false ) );
check( is_wp_error( ec_oauth_apple_user( $unverified, array(), array() ) ) && empty( $GLOBALS['meta'] ), 'unverified email cannot link' );
$r = ec_oauth_apple_user( $identity, array( 'given_name' => 'Ignored' ), array() );
check( $r['linked_existing'] && $r['user_id'] === $existing->ID && isset( $GLOBALS['actions']['ec_apple_account_linked'] ) && isset( $GLOBALS['meta'][ $existing->ID ]['oauth_linked_at'] ), 'verified email auto-links and fires action' );
reset_accounts();
$r = ec_oauth_apple_user( $identity, array(
	'given_name'  => 'Apple',
	'family_name' => 'User',
), array( 'email' => 'attacker@example.com' ) );
check( $r['is_new'] && 'Apple User' === $r['user']->display_name && $identity['email'] === $r['user']->user_email && 'appleuser' === $r['user']->user_login, 'creation uses relay email and creation-only profile' );

$device = '12345678-1234-4234-8234-123456789abc';
reset_accounts();
$r = ec_apple_login_with_tokens( jwt( $claims ), $device, array(
	'raw_nonce'            => 'raw-nonce',
	'newsletter_consent'   => true,
	'device_name'          => 'iPhone',
	'set_cookie'           => true,
	'success_redirect_url' => 'https://evil.example/',
) );
check( $r['success'] && 'access' === $r['access_token'] && 'refresh' === $r['refresh_token'] && false === $r['onboarding_completed'], 'Apple shared token response' );
check( 0 === $GLOBALS['cookies'] && 'https://community.extrachill.com/' === $r['redirect_url'], 'native flow ignores cookie and redirect options' );
check( array( 1, $identity['email'], true, 'web', 'apple', '' ) === $GLOBALS['consent'][0] && array( 1, $device, 'iPhone' ) === $GLOBALS['refresh'][0], 'Apple consent and token inputs' );
check( is_wp_error( ec_apple_login_with_tokens( jwt( $claims ), 'bad', array( 'raw_nonce' => 'raw-nonce' ) ) ), 'device validation' );
$GLOBALS['member'] = false;
check( is_wp_error( ec_apple_login_with_tokens( jwt( $claims ), $device, array( 'raw_nonce' => 'raw-nonce' ) ) ) && 1 === count( $GLOBALS['access'] ), 'membership checked before tokens' );
reset_accounts();
$google = array_merge( $claims, array(
	'sub'  => 'google-sub',
	'aud'  => 'google-client',
	'iss'  => 'accounts.google.com',
	'name' => 'Google User',
) );
$r      = ec_google_login_with_tokens( jwt( $google ), $device, array(
	'newsletter_consent'   => true,
	'set_cookie'           => true,
	'device_name'          => 'browser',
	'success_redirect_url' => 'https://community.extrachill.com/feature/',
) );
check( $r['success'] && 'access' === $r['access_token'] && 2 === $GLOBALS['cookies'] && 'https://community.extrachill.com/feature/' === $r['redirect_url'], 'Google still uses shared tail with browser behavior' );
check( 'google' === $GLOBALS['consent'][0][4] && array( 1, $device, 'browser' ) === $GLOBALS['refresh'][0], 'Google attribution and device preserved' );
check( array( 'success', 'access_token', 'access_expires_at', 'refresh_token', 'refresh_expires_at', 'onboarding_completed', 'redirect_url', 'user' ) === array_keys( $r ), 'unchanged token response keys' );
$google['aud'] = array( 'other', 'google-client' );
check( ! is_wp_error( ec_verify_google_token( jwt( $google ) ) ), 'Google array audience compatibility' );
$google['email_verified'] = 'true';
check( is_wp_error( ec_verify_google_token( jwt( $google ) ) ), 'Google still requires boolean verified email' );
echo 'Passed ' . (int) $GLOBALS['checks'] . " OAuth checks.\n";
