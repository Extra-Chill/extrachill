<?php
/**
 * Notifications Page Content
 *
 * Displays notifications page content and hooks into theme's single-page.php
 * via extrachill_after_page_content when slug is "notifications".
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

// Global cache variable
$GLOBALS['extrachill_notifications_cache'] = null;

/**
 * Format notification message
 *
 * Wrapper function for backward compatibility.
 * Delegates to extrachill_render_notification_card() for actual rendering.
 *
 * @param array $notification Notification data array
 * @return string Formatted HTML for notification card
 */
function extrachill_format_notification_message($notification) {
    return extrachill_render_notification_card($notification);
}

/**
 * Display notifications page content
 *
 * Renders new and previously viewed notifications for the current user.
 * Marks notifications as read after display.
 */
function extrachill_display_notifications() {
    global $extrachill_notifications_cache; // Access global cache

    $current_user_id = get_current_user_id();

    // Check if notifications are cached
    if ($extrachill_notifications_cache === null) {
        // Fallback: fetch notifications if not cached (should not happen ideally)
        $extrachill_notifications_cache = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];
    }
    $notifications = $extrachill_notifications_cache; // Use cached notifications

    if (empty($notifications)) {
        echo '<p>No notifications found.</p>';
        return;
    }

    // Sort notifications by time in descending order (newest to oldest)
    usort($notifications, function ($a, $b) {
        return strtotime($b['time']) - strtotime($a['time']);
    });

    $new_notifications = array_filter($notifications, function ($notification) {
        return !$notification['read'];
    });

    if (!empty($new_notifications)) {
        echo '<h2>New Notifications</h2><div class="extrachill-notifications">';
        foreach ($new_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
        }
        echo '</div>';
    }

    extrachill_mark_notifications_as_read();

    $old_notifications = array_filter($notifications, function ($notification) {
        return $notification['read'];
    });

    if (!empty($old_notifications)) {
        echo '<h2>Previously Viewed</h2><div class="extrachill-notifications">';
        foreach ($old_notifications as $notification) {
            echo extrachill_format_notification_message($notification);
        }
        echo '</div>';
    }
}

/**
 * Render notifications page content via hook
 *
 * Hooks into theme's single-page.php to display notifications
 * when viewing the notifications page.
 */
function extrachill_community_render_notifications_content() {
    if (!is_page('notifications')) {
        return;
    }

    if (!is_user_logged_in()) {
        auth_redirect();
        return;
    }

    extrachill_display_notifications();
}
add_action('extrachill_after_page_content', 'extrachill_community_render_notifications_content', 5);
