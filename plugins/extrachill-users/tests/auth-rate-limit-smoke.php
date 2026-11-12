<?php
/** Standalone coverage of the users adapters to network admission. */
define( 'ABSPATH', __DIR__ );
define( 'MINUTE_IN_SECONDS', 60 );
class WP_Error {
	public function __construct( public $code, public $message = '', public $data = array() ) {}
}
class WP_User {}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function __( $value ) { return $value; }
function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function is_email( $value ) { return false !== strpos( $value, '@' ); }
function get_user_by( ...$args ) { return false; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function wp_salt( $scheme ) { return 'test-salt'; }
function get_current_network_id() { return 1; }
function apply_filters( $hook, $value ) {
	return 'extrachill_rate_limit_store' === $hook ? 'users_test_store' : $value;
}
function users_test_store( $key, $ttl, $operation ) {
	if ( ! empty( $GLOBALS['unavailable'] ) ) {
		return extrachill_rate_limit_unavailable();
	}
	if ( 'reset' === $operation ) {
		unset( $GLOBALS['counts'][ $key ] );
		return true;
	}
	$count = $GLOBALS['counts'][ $key ] ?? 0;
	if ( 'increment' === $operation ) {
		$GLOBALS['counts'][ $key ] = ++$count;
	}
	return $count;
}
function check_users_limit( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}
require dirname( __DIR__, 2 ) . '/extrachill-network/inc/core/rate-limit.php';
require dirname( __DIR__ ) . '/inc/auth/login.php';
require dirname( __DIR__ ) . '/inc/auth/password-reset.php';
require dirname( __DIR__ ) . '/inc/auth/register.php';
$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
for ( $i = 0; $i < 5; ++$i ) {
	$error = new WP_Error( 'incorrect_password' );
	check_users_limit( $error === ec_rate_limit_login( $error, 'person' ), 'First five login failures retain the auth error.' );
	check_users_limit( true === ec_record_password_reset_attempt(), 'First five reset attempts admitted.' );
	check_users_limit( true === extrachill_users_admit_registration_attempt(), 'First five registrations admitted.' );
}
check_users_limit( 'ec_login_blocked' === ec_rate_limit_login( new WP_Error( 'incorrect_password' ), 'person' )->code, 'Sixth login failure blocked.' );
check_users_limit( 'ec_login_blocked' === ec_rate_limit_login( new WP_User(), 'person' )->code, 'Valid credentials blocked at cap.' );
check_users_limit( ec_is_password_reset_blocked() && is_int( ec_record_password_reset_attempt() ), 'Reset cap returns retry-after.' );
$error = extrachill_users_admit_registration_attempt();
check_users_limit( 'registration_rate_limited' === $error->code && 429 === $error->data['status'] && 0 < $error->data['retry_after'], 'Registration retains 429 metadata.' );
ec_clear_attempts_on_login( 'person', new WP_User() );
check_users_limit( ! ec_is_login_blocked( 'person' ) && true === ec_record_failed_login( 'person' ), 'Successful sign-in resets the counter.' );
check_users_limit( 1 === extrachill_rate_limit_count( ec_get_login_attempt_key( 'person' ), EXTRACHILL_USERS_LOGIN_RATE_WINDOW ), 'A later failure survives reset.' );
$GLOBALS['unavailable'] = true;
check_users_limit( 'ec_login_limiter_unavailable' === ec_rate_limit_login( new WP_User(), 'person' )->code, 'Login preserves unavailable code.' );
check_users_limit( 'ec_password_reset_limiter_unavailable' === ec_record_password_reset_attempt()->code, 'Reset preserves unavailable code.' );
check_users_limit( 'registration_limiter_unavailable' === extrachill_users_admit_registration_attempt()->code, 'Registration preserves unavailable code.' );
check_users_limit( ec_is_login_blocked( 'person' ) && ec_is_password_reset_blocked(), 'Read checks fail closed.' );
$GLOBALS['unavailable'] = false;
unset( $_SERVER['REMOTE_ADDR'] );
check_users_limit( ec_is_login_blocked( 'person' ) && ec_is_password_reset_blocked(), 'Missing requester fails closed.' );
check_users_limit( is_wp_error( ec_clear_login_attempts( 'person' ) ) && is_wp_error( extrachill_users_admit_registration_attempt() ), 'Missing requester cannot reset or register.' );
echo "PASS: users adapters share admission, preserve limits and errors, and reset login attempts.\n";
