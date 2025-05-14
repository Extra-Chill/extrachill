<?php
/* following-feed.php 
 * Handles fetching posts relevant to bands followed by the current user.
 */

/**
 * Get topics or replies from forums associated with bands followed by the current user.
 *
 * @param string $post_type 'topic' or 'reply'.
 * @param int $user_id Deprecated (no longer used, determined automatically). Default null.
 * @return WP_Query WP_Query object containing the relevant posts.
 */
function extrachill_get_following_posts($post_type = 'topic', $user_id = null) { // Default to topic
    $current_user_id = get_current_user_id();
    if ( !$current_user_id ) {
        return new WP_Query(); // Return empty if user not logged in
    }

    // Use the new function to get followed band IDs
    $followed_band_ids = function_exists('bp_get_user_followed_bands') ? 
                             array_map('get_post_field', array_fill(0, count(get_user_meta($current_user_id, '_followed_band_profile_ids', true) ?: []), 'ID'), get_user_meta($current_user_id, '_followed_band_profile_ids', true) ?: [])
                             : array();

    if ( empty( $followed_band_ids ) ) {
        return new WP_Query(); // Return empty if no bands followed
    }

    // Get the forum IDs associated with these bands
    $band_forum_ids = array();
    foreach ( $followed_band_ids as $band_id ) {
        $forum_id = get_post_meta( $band_id, '_band_forum_id', true );
        if ( !empty( $forum_id ) && is_numeric($forum_id) ) {
            $band_forum_ids[] = absint( $forum_id );
        }
    }

    if ( empty( $band_forum_ids ) ) {
        return new WP_Query(); // Return empty if no associated forums found
    }
    $band_forum_ids = array_unique( $band_forum_ids );

    $paged = get_query_var('paged') ? get_query_var('paged') : 1; // Pagination

    // --- Construct Query Args --- 
    $args = array(
        'post_type' => $post_type, // 'topic' or 'reply'
        'post_parent__in' => $band_forum_ids, // Only posts whose parent is one of the followed band forums
        'post_status' => 'publish', // Consider 'closed' for topics? bbPress handles visibility.
        'posts_per_page' => get_option('_bbp_topics_per_page', 15), // Use bbPress setting for topics/page
        'paged' => $paged,
        'orderby' => 'date', // Or potentially 'meta_value' for last active time if querying topics
        'order' => 'DESC',
        'ignore_sticky_posts' => 1,
    );

    // If querying topics, it might be better to order by last activity
    if ($post_type === 'topic' || $post_type === bbp_get_topic_post_type()) {
        $args['meta_key'] = '_bbp_last_active_time';
        $args['orderby']  = 'meta_value';
        $args['order'] = 'DESC';
    }

    // Note: We are not excluding private forums here, assuming band forums are meant
    // to be accessible if the band profile is public. Add exclusion if needed.

    $posts_query = new WP_Query($args);
    return $posts_query; // Return WP_Query object for pagination
}


/* -- REMOVED: Old function to get followed users --
function extrachill_get_followed_users() {
    $current_user_id = get_current_user_id();
    $following = get_user_meta($current_user_id, 'extrachill_following', true);

    if (empty($following) || !is_array($following)) {
        return [];
    }

    $user_query = new WP_User_Query(array(
        'include' => $following,
        'fields' => array('ID', 'display_name')
    ));

    return $user_query->get_results();
}
*/

?>
