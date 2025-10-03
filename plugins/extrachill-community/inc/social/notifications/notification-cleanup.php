<?php
/**
 * Notification Cleanup
 *
 * Manages notification read status and automatic cleanup of old notifications.
 * Removes read notifications older than one week.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Mark notifications as read when viewed
 *
 * Marks all unread notifications as read and triggers cleanup of old notifications.
 */
function extrachill_mark_notifications_as_read() {
    // Security check: ensure user is logged in
    if ( ! is_user_logged_in() ) {
        return;
    }

    $current_user_id = get_current_user_id();
    $notifications = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];

    // Additional safety check
    if ( ! is_array( $notifications ) ) {
        return;
    }

    foreach ($notifications as &$notification) {
        if (!$notification['read']) {
            $notification['read'] = true;
            $notification['viewed_time'] = current_time('mysql');
        }
    }

    // Update the notifications after marking them as read
    update_user_meta($current_user_id, 'extrachill_notifications', $notifications);

    // After marking as read, proceed to clean up old notifications
    extrachill_cleanup_old_notifications_for_user($current_user_id);
}

/**
 * Clean up old read notifications
 *
 * Removes read notifications older than one week.
 * Keeps all unread notifications regardless of age.
 *
 * @param int $user_id User ID to clean up notifications for
 */
function extrachill_cleanup_old_notifications_for_user($user_id) {
    // Security check: validate user ID
    $user_id = (int) $user_id;
    if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
        return;
    }

    $notifications = get_user_meta($user_id, 'extrachill_notifications', true) ?: [];

    // Safety check
    if ( ! is_array( $notifications ) ) {
        return;
    }

    $currentTime = current_time('timestamp');
    $oneWeekAgo = strtotime('-1 week', $currentTime);

    $notifications = array_filter($notifications, function($notification) use ($oneWeekAgo) {
        if (!empty($notification['read'])) {
            if (!empty($notification['viewed_time'])) {
                $viewedTime = strtotime($notification['viewed_time']);
                return $viewedTime > $oneWeekAgo;
            }
            return false; // If read but no viewed_time, consider it for removal
        }
        return true; // Keep all unread notifications
    });

    // Update the notifications after cleaning up old ones
    update_user_meta($user_id, 'extrachill_notifications', $notifications);
}
