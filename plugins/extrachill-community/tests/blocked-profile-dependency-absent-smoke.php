<?php
/**
 * Verify blocked-profile suppression no-ops when extrachill-users is absent.
 *
 * Without extrachill_users_is_blocked() there is no moderation state, so no
 * profile may 404, no robots directives may change and no sitemap entries
 * may be excluded.
 *
 * Run: php tests/blocked-profile-dependency-absent-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

class WP_Query {
	public $is_404 = false;

	public function set_404() {
		$this->is_404 = true;
	}
}

function add_action() {}
function add_filter() {}

function bbp_is_single_user() {
	return true;
}

function bbp_get_displayed_user_id() {
	return 750;
}

function current_user_can( $cap ) {
	return false;
}

function status_header( $code ) {
	$GLOBALS['_status_headers'][] = $code;
}

function nocache_headers() {}

function get_users( $args = array() ) {
	echo "FAIL: get_users() must not run when extrachill-users is absent.\n";
	exit( 1 );
}

$GLOBALS['_status_headers'] = array();
$GLOBALS['wp_query']        = new WP_Query();

require dirname( __DIR__ ) . '/inc/user-profiles/blocked-profile.php';

$failures = 0;

extrachill_community_block_banned_profiles();
if ( $GLOBALS['wp_query']->is_404 || array() !== $GLOBALS['_status_headers'] ) {
	echo "FAIL: dependency-absent profile must not 404.\n";
	++$failures;
}

if ( extrachill_community_user_is_blocked( 750 ) ) {
	echo "FAIL: no user may be treated as blocked without extrachill-users.\n";
	++$failures;
}

if ( array() !== extrachill_community_get_blocked_user_ids() ) {
	echo "FAIL: blocked id list must be empty without extrachill-users.\n";
	++$failures;
}

$robots = extrachill_community_blocked_profile_robots( array( 'max-image-preview' => 'large' ) );
if ( array( 'max-image-preview' => 'large' ) !== $robots ) {
	echo "FAIL: wp_robots must pass through untouched without extrachill-users.\n";
	++$failures;
}

$args = extrachill_community_exclude_blocked_users_from_sitemap( array( 'number' => 2000 ) );
if ( array( 'number' => 2000 ) !== $args ) {
	echo "FAIL: sitemap args must pass through untouched without extrachill-users.\n";
	++$failures;
}

if ( $failures ) {
	exit( 1 );
}

echo "PASS: dependency-absent blocked-profile suppression no-ops cleanly.\n";
exit( 0 );
