<?php
/**
 * Notification Handler
 *
 * Central handler for the extrachill_notify action. Accepts user IDs and notification data,
 * enriches with actor information, and stores in user meta.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle notification action hook
 *
 * Central handler for all notifications. Accepts user IDs and notification data,
 * enriches with actor information, and stores in user meta on community.extrachill.com
 * for network-wide notification access.
 *
 * @param int|array $user_ids Single user ID or array of user IDs to notify
 * @param array $notification_data Notification data array with required fields:
 *                                 - actor_id (int): User ID who triggered notification
 *                                 - type (string): Notification type identifier
 *                                 - link (string): URL to notification target
 *                                 - topic_title (string): Title/subject of notification
 *                                 Optional fields: post_id, item_id, etc.
 */
function extrachill_handle_notification($user_ids, $notification_data) {
    // Normalize user IDs to array
    if (!is_array($user_ids)) {
        $user_ids = [$user_ids];
    }

    // Validate required fields
    if (empty($notification_data['actor_id']) || empty($notification_data['type']) || empty($notification_data['link'])) {
        return;
    }

    // Get actor data for enrichment
    $actor_id = (int) $notification_data['actor_id'];
    $actor_data = get_userdata($actor_id);

    if (!$actor_data) {
        return;
    }

    // Enrich notification data with actor info and timestamps
    $enriched_notification = array_merge($notification_data, [
        'actor_id'           => $actor_id,
        'actor_display_name' => $actor_data->display_name,
        'actor_profile_link' => bbp_get_user_profile_url($actor_id),
        'time'               => current_time('mysql'),
        'read'               => false,
    ]);

    // Switch to community site for centralized notification storage
    $community_blog_id = get_blog_id_from_url( 'community.extrachill.com', '/' );
    if ( ! $community_blog_id ) {
        return; // Failsafe: can't find community site
    }

    $current_blog_id = get_current_blog_id();
    $switched = false;

    if ( $current_blog_id !== $community_blog_id ) {
        switch_to_blog( $community_blog_id );
        $switched = true;
    }

    try {
        // Add notification to each user's meta on community site
        foreach ($user_ids as $user_id) {
            $user_id = (int) $user_id;

            // Validate user exists
            if ($user_id <= 0 || !get_userdata($user_id)) {
                continue;
            }

            // Get existing notifications
            $notifications = get_user_meta($user_id, 'extrachill_notifications', true) ?: [];

            // Append new notification
            $notifications[] = $enriched_notification;

            // Update user meta
            update_user_meta($user_id, 'extrachill_notifications', $notifications);
        }
    } finally {
        if ( $switched ) {
            restore_current_blog();
        }
    }
}
add_action('extrachill_notify', 'extrachill_handle_notification', 10, 2);
