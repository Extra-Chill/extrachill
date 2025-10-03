<?php
/**
 * Notification Card Template
 *
 * Renders individual notification cards with type-specific icons and messages.
 * Clean implementation using actor_id structure with no legacy fallbacks.
 *
 * @package ExtraChillCommunity
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render a single notification card
 *
 * @param array $notification Notification data array
 * @return string Formatted HTML for notification card
 */
function extrachill_render_notification_card($notification) {
    // Allow plugins to provide custom rendering
    $custom_render = apply_filters('extrachill_notification_card_render', '', $notification);
    if (!empty($custom_render)) {
        return $custom_render;
    }

    // Input validation
    if (!is_array($notification) || empty($notification['type'])) {
        return '';
    }

    // Extract core data
    $type = $notification['type'];
    $actor_id = $notification['actor_id'] ?? null;
    $actor_display_name = $notification['actor_display_name'] ?? 'Someone';
    $actor_profile_link = $notification['actor_profile_link'] ?? '#';
    $topic_title = $notification['topic_title'] ?? '';
    $link = $notification['link'] ?? '#';
    $time = $notification['time'] ?? '';

    // Format timestamp
    $time_formatted = $time ? esc_html(date('n/j/y \\a\\t g:ia', strtotime($time))) : '';

    // Get actor avatar
    $avatar = $actor_id ? get_avatar($actor_id, 40) : '';

    // Determine icon and message based on type
    switch ($type) {
        case 'reply':
            $icon = 'fa-reply';
            $message = sprintf(
                '<a href="%s">%s</a> replied to your topic "<a href="%s">%s</a>"',
                esc_url($actor_profile_link),
                esc_html($actor_display_name),
                esc_url($link),
                esc_html($topic_title)
            );
            break;

        case 'mention':
            $icon = 'fa-at';
            $message = sprintf(
                '<a href="%s">%s</a> mentioned you in "<a href="%s">%s</a>"',
                esc_url($actor_profile_link),
                esc_html($actor_display_name),
                esc_url($link),
                esc_html($topic_title)
            );
            break;

        default:
            // Generic notification card for unknown types
            $icon = 'fa-bell';
            $message = sprintf(
                '<a href="%s">%s</a> sent you a notification about "<a href="%s">%s</a>"',
                esc_url($actor_profile_link),
                esc_html($actor_display_name),
                esc_url($link),
                esc_html($topic_title ?: 'this content')
            );
            break;
    }

    // Render notification card HTML
    return sprintf(
        '<div class="notification-card">
            <div class="notification-card-header">
                <span class="notification-type-icon"><i class="fas %s"></i></span>
                <span class="notification-timestamp">%s</span>
            </div>
            <div class="notification-card-body">
                <div class="notification-avatar">%s</div>
                <div class="notification-message">%s</div>
            </div>
        </div>',
        esc_attr($icon),
        $time_formatted,
        $avatar,
        $message
    );
}
