<?php
/**
 * Verify owners see an unavailable state without the cross-site dependency.
 *
 * Run: php tests/test-concert-history-dependency-absent.php
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}

function extrachill_users_can_view_concert_history() {
	return true;
}

function bbp_get_displayed_user_id() {
	return 10;
}

function get_current_user_id() {
	return 10;
}

function esc_html_e( $value ) {
	echo htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' );
}

require __DIR__ . '/../inc/user-profiles/concert-history.php';

ob_start();
ec_community_display_concert_history();
$output = ob_get_clean();

if ( false === strpos( $output, 'temporarily unavailable' ) || false !== strpos( $output, 'haven\'t tracked any shows' ) ) {
	echo "FAIL: dependency failure must render the owner unavailable state.\n";
	exit( 1 );
}

echo "PASS: dependency failure renders the owner unavailable state.\n";
