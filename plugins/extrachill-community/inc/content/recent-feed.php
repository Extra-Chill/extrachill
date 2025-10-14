<?php
/**
 * Recent Feed Query Functions
 *
 * Centralized database queries for the Recent Activity Feed page template.
 * Handles activity feed with both topics and replies in chronological order.
 *
 * @package ExtraChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


function extrachill_get_recent_replies_args($per_page = 15, $paged = 1) {
    return array(
        'post_type' => array(bbp_get_topic_post_type(), bbp_get_reply_post_type()),
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'orderby' => 'date',
        'order' => 'DESC',
        'post_status' => array('publish', 'closed', 'acf-disabled', 'private', 'hidden')
    );
}

function extrachill_get_recent_feed_query($per_page = 15, $paged = null) {
    if ($paged === null) {
        $paged = bbp_get_paged(); // Use bbPress pagination helper
    }

    $args = extrachill_get_recent_replies_args($per_page, $paged);

    return bbp_has_replies($args);
}

function extrachill_get_recent_activity_query($per_page = 15, $paged = 1) {
    $args = extrachill_get_recent_replies_args($per_page, $paged);

    return new WP_Query($args);
}
