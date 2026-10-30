<?php
/**
 * Notification Bell Display Component
 *
 * Renders the notification bell icon with an unread-count badge in the theme
 * header for logged-in users. Lives in extrachill-users (network-activated) so
 * the bell appears on EVERY site in the network, not just community.
 *
 * Reads the unread count from the network notification substrate
 * (extrachill/get-notification-unread-count). The substrate table is keyed by
 * base_prefix, so it is the same physical table on every site — no
 * switch_to_blog is required here. The bell always links to the community
 * site's /notifications page, which renders the full notification list.
 *
 * Moved here from extrachill-community per Extra-Chill/extrachill-users#104.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Display the notification bell with unread count.
 *
 * Renders for logged-in users only. Reads the unread count from the network
 * notification substrate and always links to the community notifications page.
 */
function extrachill_users_display_notification_bell() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$unread_count = extrachill_users_get_unread_notification_count();

	// Resolve the community notifications URL. ec_get_site_url() is provided by
	// extrachill-network (a hard dependency of this plugin), but guard anyway
	// so a missing helper degrades gracefully instead of fataling.
	$notifications_url = function_exists( 'ec_get_site_url' )
		? ec_get_site_url( 'community' ) . '/notifications'
		: home_url( '/notifications' );
	?>
	<div class="notification-bell-icon header-right-icon">
		<a href="<?php echo esc_url( $notifications_url ); ?>" title="Notifications">
			<?php
			// ec_icon() is a theme helper (network-wide). Fall back to a simple
			// span if it is unavailable so the bell never fatals.
			if ( function_exists( 'ec_icon' ) ) {
				echo ec_icon( 'bell', 'notification-bell-svg' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted, self-contained SVG markup for a fixed internal icon id
			} else {
				echo '<span class="notification-bell-svg" aria-hidden="true">&#128276;</span>';
			}
			?>
			<?php if ( $unread_count > 0 ) : ?>
				<span class="notification-count"><?php echo (int) $unread_count; ?></span>
			<?php endif; ?>
		</a>
	</div>
	<?php
}

/**
 * Get the current user's unread notification count from the substrate.
 *
 * Thin wrapper over the extrachill/get-notification-unread-count ability
 * (registered by this plugin). Falls back to 0 if the ability is unavailable.
 *
 * @return int Unread notification count.
 */
function extrachill_users_get_unread_notification_count() {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return 0;
	}

	$ability = wp_get_ability( 'extrachill/get-notification-unread-count' );
	if ( ! $ability ) {
		return 0;
	}

	$result = $ability->execute( array( 'user_id' => get_current_user_id() ) );
	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return 0;
	}

	return isset( $result['unread_count'] ) ? (int) $result['unread_count'] : 0;
}

// Hook notification bell into theme header (priority 20: after navigation, before avatar menu).
add_action( 'extrachill_header_top_right', 'extrachill_users_display_notification_bell', 20 );
