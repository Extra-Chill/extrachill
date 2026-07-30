<?php
/**
 * Focused tests for the personalized Community homepage header.
 *
 * Run: php tests/test-homepage-header.php
 */

define( 'ABSPATH', __DIR__ );
define( 'OBJECT', 'OBJECT' );
define( 'EXTRACHILL_COMMUNITY_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

$GLOBALS['_test_logged_in'] = false;
function add_action() {}
function is_user_logged_in() {
	return $GLOBALS['_test_logged_in'];
}
function is_front_page() {
	return true;
}
function home_url( $path = '' ) {
	return 'https://community.extrachill.com' . $path;
}
function esc_html_e( $text ) {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}

require dirname( __DIR__ ) . '/inc/home/actions.php';

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

ob_start();
extrachill_community_home_header();
$logged_out = ob_get_clean();

check( 'logged-out header leads with the community promise', false !== strpos( $logged_out, 'Music is better when it starts a conversation.' ) );
check( 'logged-out header links to canonical registration tab', false !== strpos( $logged_out, '/login/#tab-register' ) );
check( 'logged-out header links into live conversations', false !== strpos( $logged_out, '/recent' ) );
check( 'logged-out header does not render member composer actions', false === strpos( $logged_out, 'Create Discussion' ) );

if ( $failures > 0 ) {
	exit( 1 );
}

echo "All homepage header tests passed.\n";
exit( 0 );
