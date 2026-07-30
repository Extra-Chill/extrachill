<?php
/**
 * Focused tests for the role-aware Artist Platform homepage CTA.
 *
 * Run: php tests/test-artist-platform-cta.php
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['_test_logged_in']       = false;
$GLOBALS['_test_user_id']         = 0;
$GLOBALS['_test_artist_site_url'] = 'https://artist.extrachill.com';
$GLOBALS['_test_artist_ids']      = array();
$GLOBALS['_test_can_create']      = false;
$GLOBALS['_test_artist_calls']    = array();
$GLOBALS['_test_translations']    = array();

function add_action() {}

function is_user_logged_in() {
	return $GLOBALS['_test_logged_in'];
}

function get_current_user_id() {
	return $GLOBALS['_test_user_id'];
}

function ec_get_site_url( $site ) {
	$GLOBALS['_test_artist_calls'][] = array( 'site_url', $site );
	return $GLOBALS['_test_artist_site_url'];
}

function ec_get_artists_for_user( $user_id ) {
	$GLOBALS['_test_artist_calls'][] = array( 'artists', $user_id );
	return $GLOBALS['_test_artist_ids'];
}

function ec_can_create_artist_profiles( $user_id ) {
	$GLOBALS['_test_artist_calls'][] = array( 'can_create', $user_id );
	return $GLOBALS['_test_can_create'];
}

function __( $text ) {
	return $GLOBALS['_test_translations'][ $text ] ?? $text;
}

function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

require dirname( __DIR__ ) . '/inc/home/artist-platform-buttons.php';

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

function reset_artist_cta_state() {
	$GLOBALS['_test_logged_in']       = false;
	$GLOBALS['_test_user_id']         = 0;
	$GLOBALS['_test_artist_site_url'] = 'https://artist.extrachill.com';
	$GLOBALS['_test_artist_ids']      = array();
	$GLOBALS['_test_can_create']      = false;
	$GLOBALS['_test_artist_calls']    = array();
	$GLOBALS['_test_translations']    = array();
}

function render_artist_cta() {
	ob_start();
	ec_community_add_artist_platform_buttons();
	return ob_get_clean();
}

reset_artist_cta_state();
$action = ec_community_get_artist_platform_action();
check( 'anonymous visitors explore the Artist Platform', 'Explore Artist Platform' === $action['label'] );
check( 'explore action uses the canonical Artist Platform URL', 'https://artist.extrachill.com' === $action['url'] );
check( 'anonymous visitors do not trigger access lookups', array( array( 'site_url', 'artist' ) ) === $GLOBALS['_test_artist_calls'] );

reset_artist_cta_state();
$GLOBALS['_test_logged_in'] = true;
$GLOBALS['_test_user_id']   = 41;
$action                     = ec_community_get_artist_platform_action();
check( 'ordinary authenticated users explore the Artist Platform', 'Explore Artist Platform' === $action['label'] );
check( 'ordinary user capability is checked through the owning helper', in_array( array( 'can_create', 41 ), $GLOBALS['_test_artist_calls'], true ) );

reset_artist_cta_state();
$GLOBALS['_test_logged_in']  = true;
$GLOBALS['_test_user_id']    = 42;
$GLOBALS['_test_can_create'] = true;
$action                      = ec_community_get_artist_platform_action();
check( 'approved artists without a profile see the create action', 'Create Artist Profile' === $action['label'] );
check( 'create action uses the canonical create path', 'https://artist.extrachill.com/create-artist/' === $action['url'] );

reset_artist_cta_state();
$GLOBALS['_test_logged_in']  = true;
$GLOBALS['_test_user_id']    = 43;
$GLOBALS['_test_artist_ids'] = array( 99 );
$GLOBALS['_test_can_create'] = true;
$action                      = ec_community_get_artist_platform_action();
check( 'artist managers see the manage action', 'Manage Artists' === $action['label'] );
check( 'manage action uses the canonical manage path', 'https://artist.extrachill.com/manage-artist/' === $action['url'] );
check( 'artist membership takes precedence without a redundant capability check', ! in_array( array( 'can_create', 43 ), $GLOBALS['_test_artist_calls'], true ) );

reset_artist_cta_state();
$GLOBALS['_test_artist_site_url'] = 'https://artist.extrachill.com/';
$action                           = ec_community_get_artist_platform_action();
check( 'canonical root URL does not retain a duplicate trailing slash', 'https://artist.extrachill.com' === $action['url'] );

reset_artist_cta_state();
$GLOBALS['_test_artist_site_url'] = null;
check( 'missing Artist Platform dependency resolves no action', null === ec_community_get_artist_platform_action() );
check( 'missing Artist Platform dependency renders no markup', '' === render_artist_cta() );

reset_artist_cta_state();
$GLOBALS['_test_logged_in']                  = true;
$GLOBALS['_test_user_id']                    = 44;
$GLOBALS['_test_can_create']                 = true;
$GLOBALS['_test_artist_site_url']            = 'https://artist.extrachill.com/" onclick="alert(1)';
$GLOBALS['_test_translations']['Create Artist Profile'] = '<script>Create</script>';
$rendered                                    = render_artist_cta();
check( 'render escapes the action URL', false !== strpos( $rendered, '&quot; onclick=&quot;alert(1)/create-artist/' ) );
check( 'render does not emit an executable injected attribute', false === strpos( $rendered, 'href="https://artist.extrachill.com/" onclick=' ) );
check( 'render escapes the translated action label', false !== strpos( $rendered, '&lt;script&gt;Create&lt;/script&gt;' ) );
check( 'render includes the established CTA classes', false !== strpos( $rendered, 'class="button-2 button-medium"' ) );

$home_css = file_get_contents( dirname( __DIR__ ) . '/inc/assets/css/home.css' );
check(
	'desktop layout keeps the CTA centered in a flex row',
	1 === preg_match( '/\.artist-platform-homepage-actions\s*\{[^}]*display:\s*flex;[^}]*justify-content:\s*center;/s', $home_css )
);
check(
	'mobile layout stacks the CTA and gives its anchor a bounded full width',
	1 === preg_match( '/@media\s*\(max-width:\s*768px\).*?\.artist-platform-homepage-actions\s*\{[^}]*flex-direction:\s*column;.*?\.artist-platform-homepage-actions a\s*\{[^}]*width:\s*100%;[^}]*max-width:\s*300px;/s', $home_css )
);

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All Artist Platform CTA tests passed.\n";
exit( 0 );
