<?php
// Function to get recent forum topics with pagination
function extrachill_get_recent_forum_topics($paged = 1) {
    $private_forum_ids = extrachill_get_private_forum_ids();
    $recent_topics_args = array(
        'post_type' => 'topic',
        'posts_per_page' => get_option('posts_per_page'),
        'orderby' => 'date',
        'order' => 'DESC',
        'paged' => $paged,
        'post_parent__not_in' => $private_forum_ids // Exclude topics from private forums
    );
    $recent_topics = new WP_Query($recent_topics_args);

    return $recent_topics;
}
function extrachill_get_recent_forum_replies($paged = 1) {
    // Get IDs of private forums
    $private_forum_ids = extrachill_get_private_forum_ids();

    // If private_forum_ids is not empty, get private topic IDs
    if (!empty($private_forum_ids)) {
        $private_topic_ids = get_posts(array(
            'post_type' => 'topic',
            'post_parent__in' => $private_forum_ids,
            'fields' => 'ids',
            'posts_per_page' => -1, // Get all topics
            'no_found_rows' => true // Skip pagination for performance
        ));

        $exclude_topics = $private_topic_ids;
    } else {
        // If private_forum_ids is empty, do not exclude any topics
        $exclude_topics = array();
    }

    // Arguments for fetching recent replies, excluding those under private topics if necessary
    $recent_replies_args = array(
        'post_type' => 'reply',
        'posts_per_page' => get_option('posts_per_page'),
        'orderby' => 'date',
        'order' => 'DESC',
        'paged' => $paged,
        'post_parent__not_in' => $exclude_topics // Exclude replies from topics in private forums if applicable
    );

    $recent_replies = new WP_Query($recent_replies_args);

    return $recent_replies;
}



// Function to get IDs of private forums
function extrachill_get_private_forum_ids() {
    // If the user is part of the Extra Chill team, return an empty array (no exclusion needed)
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1') {
        return array();
    }

    // Find forums that require the Extra Chill team
    $private_forum_ids_query = array(
        'post_type' => bbp_get_forum_post_type(),
        'meta_key'   => '_require_extrachill_team',
        'meta_value' => '1',
        'fields' => 'ids',
        'posts_per_page' => -1,
    );
    return get_posts($private_forum_ids_query);
}


