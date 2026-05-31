<?php
/**
 * Notification Bell Display Component
 *
 * Renders notification bell icon with unread count badge in header.
 * Displays for logged-in users only.
 *
 * Reads the unread count from the network notification substrate in the
 * extrachill-users plugin (extrachill/get-notification-unread-count) rather
 * than the legacy per-user `extrachill_notifications` user_meta blob. The
 * substrate table is keyed by base_prefix, so it is the same physical table
 * on every site — no switch_to_blog is required here.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Display notification bell with unread count.
 *
 * Reads the unread count from the network notification substrate. Always links
 * to the community site notifications page.
 */
function extrachill_display_notification_bell() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$unread_count = extrachill_community_get_unread_notification_count();
	?>
	<div class="notification-bell-icon header-right-icon">
		<a href="<?php echo esc_url( ec_get_site_url( 'community' ) . '/notifications' ); ?>" title="Notifications">
			<?php echo ec_icon('bell', 'notification-bell-svg'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ec_icon() returns trusted, self-contained SVG markup for a fixed internal icon id ?>
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
 * (registered by extrachill-users). Falls back to 0 if the ability is
 * unavailable (e.g. the users plugin is not loaded).
 *
 * @return int Unread notification count.
 */
function extrachill_community_get_unread_notification_count() {
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

// Hook notification bell into theme header (priority 20: after navigation, before avatar menu)
add_action('extrachill_header_top_right', 'extrachill_display_notification_bell', 20);
