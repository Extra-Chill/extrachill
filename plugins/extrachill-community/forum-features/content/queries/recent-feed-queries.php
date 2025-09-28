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


/**
 * Get standardized query arguments for recent activity feed.
 * Simple chronological feed of all forum topics and replies.
 *
 * @param int $per_page Number of posts per page (default: 15)
 * @param int $paged Current page number (default: 1)
 * @return array Query arguments for mixed topic/reply query
 */
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

/**
 * Execute recent feed query and return whether activity was found.
 * Complete wrapper function for recent feed page template.
 *
 * @param int $per_page Number of posts per page (default: 15)
 * @param int $paged Current page number (defaults to bbPress pagination)
 * @return bool True if activity found, false otherwise
 */
function extrachill_get_recent_feed_query($per_page = 15, $paged = null) {
    if ($paged === null) {
        $paged = bbp_get_paged(); // Use bbPress pagination helper
    }
    
    $args = extrachill_get_recent_replies_args($per_page, $paged);
    
    // Set global for pagination access
    global $bbp_reply_query;
    $bbp_reply_query = new WP_Query($args);
    
    return bbp_has_replies($args);
}

/**
 * Generate pagination for recent feed template
 *
 * @return array|false Pagination HTML array or false if no pagination needed
 */
function extrachill_get_recent_feed_pagination() {
    global $bbp_reply_query;
    
    if (!$bbp_reply_query || !$bbp_reply_query->max_num_pages > 1) {
        return false;
    }
    
    $current_page = max(1, bbp_get_paged());
    $total_pages = $bbp_reply_query->max_num_pages;
    $total_replies = $bbp_reply_query->found_posts;
    $per_page = $bbp_reply_query->query_vars['posts_per_page'];
    
    // Calculate pagination display values
    $start = (($current_page - 1) * $per_page) + 1;
    $end = min($current_page * $per_page, $total_replies);
    
    return array(
        'count_html' => "Viewing activity $start to $end (of $total_replies total)",
        'links_html' => paginate_links(array(
            'total' => $total_pages,
            'current' => $current_page,
            'prev_text' => '&laquo; Previous',
            'next_text' => 'Next &raquo;',
            'type' => 'list'
        ))
    );
}

/**
 * Get recent activity for custom implementations.
 * Returns WP_Query object instead of bbPress loop setup.
 *
 * @param int $per_page Number of posts per page (default: 15)
 * @param int $paged Current page number (default: 1)
 * @return WP_Query Query object with recent topics and replies
 */
function extrachill_get_recent_activity_query($per_page = 15, $paged = 1) {
    $args = extrachill_get_recent_replies_args($per_page, $paged);
    
    return new WP_Query($args);
}