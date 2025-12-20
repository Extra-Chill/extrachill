<?php
/**
 * Subforum Button Styling
 *
 * @package ExtraChill\Community
 */

/**
 * Add button-3 button-small classes to subforum links.
 *
 * @param array $classes Existing classes
 * @param int   $forum_id Forum ID
 * @return array Modified classes
 */
add_filter( 'bbp_list_forums_subforum_classes', 'extrachill_subforum_button_classes', 10, 2 );
function extrachill_subforum_button_classes( $classes, $forum_id ) {
    $classes[] = 'button-3';
    $classes[] = 'button-small';
    return $classes;
}

/**
 * Hide topic and reply counts from subforum listings.
 * These counts (displayed as "12, 18") are not user-friendly.
 */
add_filter( 'bbp_after_list_forums_parse_args', 'extrachill_hide_subforum_counts' );
function extrachill_hide_subforum_counts( $r ) {
    $r['show_topic_count'] = false;
    $r['show_reply_count'] = false;
    return $r;
}

/**
 * Order subforums by most recent activity.
 */
add_filter( 'bbp_after_forum_get_subforums_parse_args', 'extrachill_order_subforums_by_activity' );
function extrachill_order_subforums_by_activity( $r ) {
    $r['orderby']  = 'meta_value';
    $r['meta_key'] = '_bbp_last_active_time';
    $r['order']    = 'DESC';
    return $r;
}
