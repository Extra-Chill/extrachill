<?php
/**
 * Plugin Name: Extra Chill Events Network Blocks
 * Description: Exposes Events-owned public blocks across the Extra Chill network without loading the Events runtime on other sites.
 * Version: 0.59.1
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: true
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/Core/VenueBookingConfig.php';

/**
 * Return an activation error unless every runtime dependency is network-active.
 *
 * @param bool $network_wide Whether WordPress is activating across the network.
 * @return WP_Error|null
 */
function extrachill_events_network_blocks_activation_error( bool $network_wide ) {
	if ( ! is_multisite() || ! $network_wide ) {
		return new WP_Error( 'network_activation_required', __( 'Extra Chill Events Network Blocks must be activated for the entire multisite network.', 'extrachill-events' ) );
	}

	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$required = array(
		'extrachill-network/extrachill-network.php' => 'Extra Chill Network',
		'extrachill-api/extrachill-api.php'         => 'Extra Chill API',
	);
	$missing  = array();
	foreach ( $required as $plugin => $label ) {
		if ( ! is_plugin_active_for_network( $plugin ) ) {
			$missing[] = $label;
		}
	}

	if ( $missing ) {
		/* translators: %s: Comma-separated plugin names. */
		return new WP_Error( 'network_dependency_required', sprintf( __( 'Network-activate these required plugins first: %s.', 'extrachill-events' ), implode( ', ', $missing ) ) );
	}

	return null;
}

/**
 * Fail activation clearly before WordPress records an unusable network plugin.
 *
 * @param bool $network_wide Whether WordPress is activating across the network.
 */
function extrachill_events_activate_network_blocks( bool $network_wide ): void {
	$error = extrachill_events_network_blocks_activation_error( $network_wide );
	if ( is_wp_error( $error ) ) {
		wp_die( esc_html( $error->get_error_message() ), esc_html__( 'Network activation required', 'extrachill-events' ), array( 'response' => 500 ) );
	}
}
register_activation_hook( __FILE__, 'extrachill_events_activate_network_blocks' );

/** Register Events-owned blocks that are safe to render on every network site. */
function extrachill_events_register_network_blocks(): void {
	if ( defined( 'EXTRACHILL_EVENTS_PLUGIN_FILE' ) && function_exists( 'ec_is_events_site' ) && ec_is_events_site() ) {
		return;
	}

	$booking_inquiry_dir = __DIR__ . '/build/venue-booking-inquiry';
	if ( file_exists( $booking_inquiry_dir . '/block.json' ) ) {
		register_block_type( $booking_inquiry_dir );
	}
}
add_action( 'init', 'extrachill_events_register_network_blocks' );
