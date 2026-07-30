<?php
/**
 * Focused tests for Community concert-history privacy and availability.
 *
 * Run: php tests/test-concert-history-privacy.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_current_user_id'] = 0;
$GLOBALS['_test_visibility']      = array();
$GLOBALS['_test_rest_calls']      = 0;
$GLOBALS['_test_rest_response']   = array( 'total_shows' => 2 );
$GLOBALS['_test_display_user_id'] = 10;

class WP_Error {}

function add_action() {}

function extrachill_users_can_view_concert_history( $user_id ) {
	return (int) $GLOBALS['_test_current_user_id'] === (int) $user_id || ( $GLOBALS['_test_visibility'][ $user_id ] ?? false );
}

function ec_cross_site_rest_request() {
	++$GLOBALS['_test_rest_calls'];
	return $GLOBALS['_test_rest_response'];
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function bbp_get_displayed_user_id() {
	return $GLOBALS['_test_display_user_id'];
}

function get_current_user_id() {
	return $GLOBALS['_test_current_user_id'];
}

function ec_get_blog_id( $site ) {
	return 'events' === $site ? 7 : 0;
}

function get_home_url() {
	return 'https://events.example.com/my-shows/';
}

function add_query_arg( $key, $value, $url ) {
	return $url . '?' . $key . '=' . $value;
}

function esc_url( $value ) {
	return $value;
}

function esc_html( $value ) {
	return (string) $value;
}

function __( $value ) {
	return $value;
}

function esc_html_e( $value ) {
	echo esc_html( $value );
}

function number_format_i18n( $value ) {
	return (string) $value;
}

function _n( $single, $plural, $number ) {
	return 1 === $number ? $single : $plural;
}

function wp_kses_post( $value ) {
	return $value;
}

require __DIR__ . '/../inc/user-profiles/concert-history.php';

$failures = 0;

function check( $label, $condition ) {
	global $failures;
	if ( $condition ) {
		echo "PASS: $label\n";
		return;
	}
	echo "FAIL: $label\n";
	++$failures;
}

$GLOBALS['_test_visibility'][10]      = true;
$GLOBALS['_test_current_user_id']     = 20;
$GLOBALS['_test_rest_response']       = array( 'total_shows' => 8 );
$GLOBALS['_test_rest_calls']          = 0;

check( 'other user can read public stats', 8 === ec_community_get_concert_stats( 10 )['total_shows'] );
ob_start();
ec_community_display_concert_history();
$public_output = ob_get_clean();
check( 'other user sees the public concert card', false !== strpos( $public_output, 'Concert History' ) );
check( 'each authorized read requests fresh Events data', 2 === $GLOBALS['_test_rest_calls'] );

$GLOBALS['_test_visibility'][10]  = false;
$GLOBALS['_test_current_user_id'] = 20;
$calls_before                     = $GLOBALS['_test_rest_calls'];
check( 'other user cannot read private stats', null === ec_community_get_concert_stats( 10 ) );
check( 'privacy denial happens before the cross-site request', $calls_before === $GLOBALS['_test_rest_calls'] );

$GLOBALS['_test_current_user_id'] = 0;
check( 'anonymous viewer cannot read private stats', null === ec_community_get_concert_stats( 10 ) );

$GLOBALS['_test_current_user_id'] = 10;
$GLOBALS['_test_rest_response']   = array( 'total_shows' => 8 );
check( 'owner can read private stats', 8 === ec_community_get_concert_stats( 10 )['total_shows'] );
ob_start();
ec_community_display_concert_history();
$owner_output = ob_get_clean();
check( 'owner sees and can manage a private concert history', false !== strpos( $owner_output, 'My Concert History' ) && false !== strpos( $owner_output, 'View your full concert history' ) );

$GLOBALS['_test_rest_response'] = array( 'total_shows' => 0 );
ob_start();
ec_community_display_concert_history();
$zero_output = ob_get_clean();
check( 'legitimate zero stats render the owner start-history state', false !== strpos( $zero_output, 'haven\'t tracked any shows' ) );
check( 'legitimate zero stats are not reported as unavailable', false === strpos( $zero_output, 'temporarily unavailable' ) );

$GLOBALS['_test_rest_response'] = new WP_Error();
ob_start();
ec_community_display_concert_history();
$error_output = ob_get_clean();
check( 'API failure renders an unavailable state for the owner', false !== strpos( $error_output, 'temporarily unavailable' ) );
check( 'API failure is not presented as zero shows', false === strpos( $error_output, 'haven\'t tracked any shows' ) );

$GLOBALS['_test_rest_response'] = array();
ob_start();
ec_community_display_concert_history();
$malformed_output = ob_get_clean();
check( 'malformed stats render an unavailable state for the owner', false !== strpos( $malformed_output, 'temporarily unavailable' ) );

$GLOBALS['_test_current_user_id'] = 20;
$GLOBALS['_test_visibility'][10]  = true;
$GLOBALS['_test_rest_response']   = new WP_Error();
ob_start();
ec_community_display_concert_history();
$visitor_error_output = ob_get_clean();
check( 'unavailable stats render no card for another user', '' === $visitor_error_output );

$GLOBALS['_test_current_user_id'] = 0;
ob_start();
ec_community_display_concert_history();
$anonymous_error_output = ob_get_clean();
check( 'unavailable stats render no card for an anonymous viewer', '' === $anonymous_error_output );

$source = file_get_contents( __DIR__ . '/../inc/user-profiles/concert-history.php' );
check( 'concert profile has no transient cache coupling', false === strpos( $source, '_transient' ) );
check( 'concert profile has no mark or visibility invalidation hooks', false === strpos( $source, 'ec_users_event_marked' ) && false === strpos( $source, 'extrachill_users_visibility_changed' ) );

echo "\n";
if ( $failures > 0 ) {
	echo "$failures test(s) FAILED.\n";
	exit( 1 );
}

echo "All concert-history privacy tests passed.\n";
