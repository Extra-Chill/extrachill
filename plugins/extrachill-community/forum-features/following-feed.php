<?php
/* following-feed.php 
 * Handles fetching posts relevant to bands followed by the current user.
 */

/**
 * Get query arguments for topics from forums associated with bands followed by the current user.
 *
 * @param string $post_type 'topic' or 'reply'.
 * @return array WP_Query arguments array, or an empty array if no topics to display.
 */
function extrachill_get_following_posts_args($post_type = 'topic') {
    $current_user_id = get_current_user_id();
    if ( !$current_user_id ) {
        return array('post__in' => array(0)); // Return args that will result in no posts
    }

    // Get followed band profile IDs
    $followed_band_profile_ids = get_user_meta($current_user_id, '_followed_band_profile_ids', true);

    if ( empty($followed_band_profile_ids) || !is_array($followed_band_profile_ids) ) {
        return array('post__in' => array(0));
    }

    // Sanitize to ensure they are integers
    $followed_band_profile_ids = array_map('absint', $followed_band_profile_ids);
    $followed_band_profile_ids = array_filter($followed_band_profile_ids); // Remove any zeros from invalid IDs

    if ( empty( $followed_band_profile_ids ) ) {
        return array('post__in' => array(0));
    }

    // Get the forum IDs associated with these band profiles
    $band_forum_ids = array();
    foreach ( $followed_band_profile_ids as $band_profile_id ) {
        $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
        if ( !empty( $forum_id ) && is_numeric( $forum_id ) ) {
            $band_forum_ids[] = absint( $forum_id );
        }
    }

    $band_forum_ids = array_unique( $band_forum_ids );

    // --- TEMPORARY DEBUGGING --- 
    error_log('[DEBUG] Following Feed - User: ' . $current_user_id . ', Followed Band Profile IDs: ' . print_r($followed_band_profile_ids, true));
    error_log('[DEBUG] Following Feed - Calculated Band Forum IDs: ' . print_r($band_forum_ids, true));
    // --- END TEMPORARY DEBUGGING ---

    // If band_forum_ids is empty after all checks, ensure no posts are returned.
    if (empty($band_forum_ids)) {
        error_log('[DEBUG] Following Feed - No valid band_forum_ids found, returning post__in => 0 args.');
        return array('post__in' => array(0)); 
    }

    $actual_posts_per_page = bbp_get_topics_per_page();
    error_log('[DEBUG] Following Feed - bbp_get_topics_per_page() returned: ' . $actual_posts_per_page);

    // --- Construct Query Args (Simplified for debugging) --- 
    $args = array(
        'post_type'         => bbp_get_topic_post_type(), // Ensure correct post type
        'post_parent__in'   => $band_forum_ids,
        'posts_per_page'    => $actual_posts_per_page, 
        'paged'             => bbp_get_paged(),
        'orderby'           => 'meta_value',
        'meta_key'          => '_bbp_last_active_time',
        'order'             => 'DESC',
        'ignore_sticky_posts' => 1, // Standard for bbPress topic lists
        // 'suppress_filters' => true, // Uncomment to test if filters are interfering - USE WITH CAUTION
    );

    // If band_forum_ids is empty, ensure no posts are returned.
    if (empty($band_forum_ids)) {
        $args['post__in'] = array(0); // WP_Query trick to return no posts
        unset($args['post_parent__in']); // Avoid issues with empty post_parent__in
    }

    error_log('[DEBUG] Following Feed - Final Query Args: ' . print_r($args, true)); // Log the final args

    return $args;
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
