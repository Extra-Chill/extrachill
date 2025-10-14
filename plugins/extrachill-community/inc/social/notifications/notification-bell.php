<?php
/**
 * Notification Bell Display Component
 *
 * Renders notification bell icon with unread count badge in header.
 * Displays for logged-in users only.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display notification bell with unread count
 *
 * Reads notifications from community.extrachill.com for network-wide notification access.
 * Always links to community site notifications page.
 */
function extrachill_display_notification_bell() {
    if (!is_user_logged_in()) {
        return;
    }

    global $extrachill_notifications_cache;
    $current_user_id = get_current_user_id();

    // Switch to community site to read notifications
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
        // Check if notifications are cached
        if ($extrachill_notifications_cache === null) {
            // Fetch notifications and store in cache
            $extrachill_notifications_cache = get_user_meta($current_user_id, 'extrachill_notifications', true) ?: [];
        }
        $notifications = $extrachill_notifications_cache;

        // Filter unread notifications for the count
        $unread_count = count(array_filter($notifications, function ($notification) {
            return !$notification['read'];
        }));

    } finally {
        if ( $switched ) {
            restore_current_blog();
        }
    }
    ?>
    <div class="notification-bell-icon">
        <a href="https://community.extrachill.com/notifications" title="Notifications">
            <i class="fa-solid fa-bell"></i>
            <?php if ($unread_count > 0) : ?>
                <span class="notification-count"><?php echo $unread_count; ?></span>
            <?php endif; ?>
        </a>
    </div>
    <?php
}

// Hook notification bell into theme header (priority 20: after navigation, before avatar menu)
add_action('extrachill_header_top_right', 'extrachill_display_notification_bell', 20);
