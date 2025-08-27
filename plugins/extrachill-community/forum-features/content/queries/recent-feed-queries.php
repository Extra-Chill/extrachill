<?php
/**
 * Recent Feed Query Functions
 * 
 * Centralized database queries for the Recent Activity Feed page template.
 * Handles reply-based Twitter-like feed with configurable forum exclusions.
 * 
 * @package ExtraChillCommunity  
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


/**
 * Get standardized query arguments for recent replies feed.
 * Simple chronological feed of all forum replies.
 *
 * @param int $per_page Number of replies per page (default: 15)
 * @param int $paged Current page number (default: 1)
 * @return array Query arguments for bbp_has_replies()
 */
function extrachill_get_recent_replies_args($per_page = 15, $paged = 1) {
    return array(
        'post_type' => bbp_get_reply_post_type(),
        'posts_per_page' => $per_page,
        'paged' => $paged,
        'orderby' => 'date',
        'order' => 'DESC',
        'post_status' => array('publish', 'closed', 'acf-disabled', 'private', 'hidden')
    );
}

/**
 * Execute recent feed query and return whether replies were found.
 * Complete wrapper function for recent feed page template.
 *
 * @param int $per_page Number of replies per page (default: 15)
 * @param int $paged Current page number (defaults to bbPress pagination)
 * @return bool True if replies found, false otherwise
 */
function extrachill_get_recent_feed_query($per_page = 15, $paged = null) {
    if ($paged === null) {
        $paged = bbp_get_paged(); // Use bbPress pagination helper
    }
    
    $args = extrachill_get_recent_replies_args($per_page, $paged);
    
    return bbp_has_replies($args);
}

/**
 * Get recent replies for custom implementations.
 * Returns WP_Query object instead of bbPress loop setup.
 *
 * @param int $per_page Number of replies per page (default: 15)
 * @param int $paged Current page number (default: 1)
 * @return WP_Query Query object with recent replies
 */
function extrachill_get_recent_replies_query($per_page = 15, $paged = 1) {
    $args = extrachill_get_recent_replies_args($per_page, $paged);
    
    return new WP_Query($args);
}