<?php
/**
 * Temporary routing for retired Admin Tools network-admin links.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_init', 'ec_redirect_legacy_admin_tools_page' );

/**
 * Route the retired umbrella page to Network's owner-native landing page.
 */
function ec_redirect_legacy_admin_tools_page() {
	if ( defined( 'EXTRACHILL_ADMIN_TOOLS_VERSION' ) || ! current_user_can( 'manage_network_options' ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation compatibility.
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( 'extrachill-admin-tools' !== $page ) {
		return;
	}

	wp_safe_redirect( network_admin_url( 'admin.php?page=' . EXTRACHILL_NETWORK_MENU_SLUG ), 301 );
	exit;
}
