<?php
/* following-feed.php 
*/

function extrachill_get_following_posts($post_type, $user_id = null) {
    $current_user_id = get_current_user_id();
    $following = $user_id ? [$user_id] : get_user_meta($current_user_id, 'extrachill_following', true);

    if (empty($following) || !is_array($following)) {
        return new WP_Query(); // Return an empty WP_Query object if no following
    }

    $paged = get_query_var('paged') ? get_query_var('paged') : 1; // Pagination

    // Get IDs of private forums
    $private_forum_ids = extrachill_get_private_forum_ids();

    // Initialize array to hold IDs to exclude
    $exclude_ids = array();

    // If there are private forums, exclude topics and replies within them
    if (!empty($private_forum_ids)) {
        // Exclude topics in private forums directly
        $topics_in_private_forums = get_posts(array(
            'post_type' => 'topic',
            'post_parent__in' => $private_forum_ids,
            'fields' => 'ids',
            'posts_per_page' => -1, // Get all topics
            'no_found_rows' => true, // Skip pagination for performance
        ));

        // Combine topic IDs to exclude
        $exclude_ids = array_merge($exclude_ids, $topics_in_private_forums);

        // For replies, we need to further exclude based on their associated topic's forum being private
        if ($post_type === 'reply') {
            $replies_in_private_topics = get_posts(array(
                'post_type' => 'reply',
                'fields' => 'ids',
                'posts_per_page' => -1, // Get all replies
                'no_found_rows' => true, // Skip pagination for performance
                'post_parent__in' => $topics_in_private_forums, // Only get replies to topics that are already excluded
            ));

            // Combine topic and reply IDs to exclude
            $exclude_ids = array_merge($exclude_ids, $replies_in_private_topics);
        }
    }

    // Arguments for fetching following posts, excluding those under private topics if necessary
    $args = array(
        'post_type' => $post_type,
        'post_status' => 'publish',
        'author__in' => $following,
        'posts_per_page' => get_option('posts_per_page'), // Adjust for pagination
        'paged' => $paged, // Pagination
        'post__not_in' => $exclude_ids, // Exclude posts in private forums
    );

    $posts = new WP_Query($args);
    return $posts; // Return WP_Query object for pagination
}




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

?>
