<?php
/**
 * Artist Notification Card Rendering
 *
 * Provides custom notification card rendering for artist forum notifications.
 * Hooks into the extrachill_notification_card_render filter provided by
 * the Extra Chill Community plugin.
 *
 * @package ExtraChillArtistPlatform
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Render artist notification cards
 *
 * @param string $html Existing HTML (empty string from filter)
 * @param array $notification Notification data array
 * @return string HTML for notification card or empty string if not handled
 */
function ec_render_artist_notification_cards($html, $notification) {
    // Only handle artist notification types
    if (empty($notification['type']) || !in_array($notification['type'], ['new_artist_topic', 'new_artist_reply'])) {
        return $html;
    }

    // Extract notification data
    $actor_id = $notification['actor_id'] ?? null;
    $actor_display_name = $notification['actor_display_name'] ?? 'Someone';
    $actor_profile_link = $notification['actor_profile_link'] ?? '#';
    $topic_title = $notification['topic_title'] ?? '';
    $link = $notification['link'] ?? '#';
    $time = $notification['time'] ?? '';

    $time_formatted = $time ? esc_html(date('n/j/y \\a\\t g:ia', strtotime($time))) : '';
    $avatar = $actor_id ? get_avatar($actor_id, 40) : '';

    // Determine icon and message based on type
    if ($notification['type'] === 'new_artist_topic') {
        $icon = 'fa-comments';
        $message = sprintf(
            '<a href="%s">%s</a> started a new topic "<a href="%s">%s</a>" in your artist forum',
            esc_url($actor_profile_link),
            esc_html($actor_display_name),
            esc_url($link),
            esc_html($topic_title)
        );
    } else {
        // new_artist_reply
        $icon = 'fa-comment-dots';
        $message = sprintf(
            '<a href="%s">%s</a> replied in the topic "<a href="%s">%s</a>" in your artist forum',
            esc_url($actor_profile_link),
            esc_html($actor_display_name),
            esc_url($link),
            esc_html($topic_title)
        );
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
add_filter('extrachill_notification_card_render', 'ec_render_artist_notification_cards', 10, 2);
