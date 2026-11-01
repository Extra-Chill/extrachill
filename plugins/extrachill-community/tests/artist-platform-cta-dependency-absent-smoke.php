<?php
/**
 * Verify the Community homepage CTA degrades cleanly without Artist Platform.
 *
 * The network blog-id contract (ec_get_site_url()) is a hard dependency, so
 * the real extrachill-network helpers are loaded. The optional dependency is
 * the Artist Platform plugin: without its user helpers, a logged-in member
 * still gets the generic Artist Platform entry point instead of a fatal.
 *
 * Run: php tests/artist-platform-cta-dependency-absent-smoke.php
 */

define( 'ABSPATH', __DIR__ );

function add_action() {}
function add_filter() {
	return true;
}
function apply_filters( $hook, $value ) {
	unset( $hook );
	return $value;
}
function get_site_option( $name, $default = false ) {
	unset( $name );
	return $default;
}
function is_user_logged_in() {
	return true;
}
function get_current_user_id() {
	return 42;
}
function __( $text ) {
	return $text;
}
function esc_url( $url ) {
	return htmlspecialchars( $url, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

// Load the real network blog-id contract. Homeboy exports
// EXTRACHILL_NETWORK_PATH when extrachill-network is a validation dependency;
// otherwise fall back to the monorepo sibling checkout.
$ec_network_path = getenv( 'EXTRACHILL_NETWORK_PATH' );
if ( ! is_string( $ec_network_path ) || '' === $ec_network_path ) {
	$ec_network_path = dirname( __DIR__, 2 ) . '/extrachill-network';
}
require_once rtrim( $ec_network_path, '/' ) . '/inc/core/blog-ids.php';

require dirname( __DIR__ ) . '/inc/home/artist-platform-buttons.php';

if ( function_exists( 'ec_get_artists_for_user' ) || function_exists( 'ec_can_create_artist_profiles' ) ) {
	echo "FAIL: Artist Platform helpers must be absent for this smoke.\n";
	exit( 1 );
}

ob_start();
ec_community_add_artist_platform_buttons();
$rendered = ob_get_clean();

if ( false === strpos( $rendered, 'href="https://artist.extrachill.com"' ) ) {
	echo "FAIL: dependency-absent render should link to the network Artist Platform URL.\n";
	exit( 1 );
}

if ( false === strpos( $rendered, 'Explore Artist Platform' ) ) {
	echo "FAIL: dependency-absent render should fall back to the generic Artist Platform action.\n";
	exit( 1 );
}

if ( false !== strpos( $rendered, 'manage-artist' ) || false !== strpos( $rendered, 'create-artist' ) ) {
	echo "FAIL: dependency-absent render must not offer member-specific artist actions.\n";
	exit( 1 );
}

echo "PASS: dependency-absent render falls back to the generic Artist Platform action and does not fail.\n";
exit( 0 );
