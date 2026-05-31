<?php
/**
 * Notifications Page Content
 *
 * Displays notifications page content and hooks into theme's single-page.php
 * via extrachill_after_page_content when slug is "notifications".
 *
 * Reads from the network notification substrate in the extrachill-users plugin
 * (extrachill/get-notifications + extrachill/mark-notifications-read) rather
 * than the legacy per-user `extrachill_notifications` user_meta blob. The
 * substrate table is keyed by base_prefix, so no switch_to_blog is required.
 *
 * Parent epic: Extra-Chill/extrachill-community#82.
 *
 * @package ExtraChillCommunity
 */

if ( ! defined('ABSPATH') ) {
	exit;
}

/**
 * Display notifications page content.
 *
 * Renders new (unread) and previously viewed notifications for the current
 * user, then marks the unread ones as read. Reads from the network substrate.
 */
function extrachill_display_notifications() {
	$current_user_id = get_current_user_id();

	$result        = extrachill_community_fetch_notifications( $current_user_id );
	$notifications = isset( $result['notifications'] ) && is_array( $result['notifications'] )
		? $result['notifications']
		: array();

	if ( empty( $notifications ) ) {
		echo '<p>No notifications found.</p>';
		return;
	}

	$new_notifications = array_filter(
		$notifications,
		function ( $notification ) {
			return empty( $notification['read'] );
		}
	);

	if ( ! empty( $new_notifications ) ) {
		echo '<h2>New Notifications</h2><div class="extrachill-notifications">';
		foreach ( $new_notifications as $notification ) {
			echo wp_kses_post( extrachill_render_notification_card( $notification ) );
		}
		echo '</div>';
	}

	// Mark all unread notifications as read in the substrate.
	extrachill_community_mark_notifications_read( $current_user_id );

	$old_notifications = array_filter(
		$notifications,
		function ( $notification ) {
			return ! empty( $notification['read'] );
		}
	);

	if ( ! empty( $old_notifications ) ) {
		echo '<h2>Previously Viewed</h2><div class="extrachill-notifications">';
		foreach ( $old_notifications as $notification ) {
			echo wp_kses_post( extrachill_render_notification_card( $notification ) );
		}
		echo '</div>';
	}
}

/**
 * Fetch notifications for a user from the substrate.
 *
 * Thin wrapper over the extrachill/get-notifications ability. Returns the full
 * ability result (already newest-first and enriched) or an empty shape on
 * failure.
 *
 * @param int $user_id User ID.
 * @return array Ability result with a 'notifications' key.
 */
function extrachill_community_fetch_notifications( $user_id ) {
	$empty = array( 'notifications' => array() );

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return $empty;
	}

	$ability = wp_get_ability( 'extrachill/get-notifications' );
	if ( ! $ability ) {
		return $empty;
	}

	$result = $ability->execute(
		array(
			'user_id'  => (int) $user_id,
			'page'     => 1,
			'per_page' => 100,
		)
	);

	if ( is_wp_error( $result ) || ! is_array( $result ) ) {
		return $empty;
	}

	return $result;
}

/**
 * Mark all unread notifications as read for a user via the substrate.
 *
 * @param int $user_id User ID.
 * @return void
 */
function extrachill_community_mark_notifications_read( $user_id ) {
	if ( ! function_exists( 'wp_get_ability' ) ) {
		return;
	}

	$ability = wp_get_ability( 'extrachill/mark-notifications-read' );
	if ( ! $ability ) {
		return;
	}

	// notification_id 0 marks ALL unread for the user.
	$ability->execute(
		array(
			'user_id'         => (int) $user_id,
			'notification_id' => 0,
		)
	);
}

/**
 * Render notifications page content via hook.
 *
 * Hooks into theme's single-page.php to display notifications when viewing the
 * notifications page.
 */
function extrachill_community_render_notifications_content() {
	if ( ! is_page('notifications') ) {
		return;
	}

	if ( ! is_user_logged_in() ) {
		auth_redirect();
		return;
	}

	extrachill_display_notifications();
}
add_action('extrachill_after_page_content', 'extrachill_community_render_notifications_content', 5);
