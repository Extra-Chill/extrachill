<?php
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/recent-activity', array(
        'methods' => 'GET',
        'callback' => 'get_recent_community_activity',
        'permission_callback' => '__return_true', // Make this endpoint public
    ));
});

function get_recent_community_activity($request) {
    // Define the query arguments
    $args = array(
        'post_type' => array('topic', 'reply'), // Assuming 'topic' and 'reply' are the post types
        'post_status' => 'publish',
        'posts_per_page' => 3,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(
            'relation' => 'AND', // Combine multiple conditions
            array(
                'key'     => '_bbp_forum_id', // Adjust if necessary
                'value'   => extrachill_get_private_forum_ids(), // Assuming this function returns IDs of private forums
                'compare' => 'NOT IN',
            ),
            array(
                'key'     => '_bbp_forum_id',
                'value'   => '1494', // The specific forum ID to exclude
                'compare' => '!=', // Exclude this specific forum
            ),
        ),
    );

    // Perform the query
    $query = new WP_Query($args);

    // Prepare the response
    $activities = array();
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            $post_id = get_the_ID();
            $post_type = get_post_type($post_id);
            $forum_id = ('reply' === $post_type) ? bbp_get_reply_forum_id($post_id) : bbp_get_topic_forum_id($post_id);
            $forum_title = get_the_title($forum_id);
            $topic_title = ('reply' === $post_type) ? get_the_title(bbp_get_reply_topic_id($post_id)) : get_the_title($post_id);
            $username = get_the_author();
            $user_profile_url = bbp_get_user_profile_url(get_the_author_meta('ID'));
            $date_time = get_the_date('c'); // ISO 8601 format
            $forum_url = bbp_get_forum_permalink($forum_id);
            $topic_url = ('reply' === $post_type) ? bbp_get_reply_url($post_id) : bbp_get_topic_permalink($post_id);

            $activities[] = array(
                'id' => $post_id,
                'type' => ('reply' === $post_type) ? 'Reply' : 'Topic',
                'username' => $username,
                'user_profile_url' => $user_profile_url,
                'topic_title' => $topic_title,
                'forum_title' => $forum_title,
                'date_time' => $date_time,
                'forum_url' => $forum_url,
                'topic_url' => $topic_url, // Direct link to the topic or reply
            );
        }
        wp_reset_postdata();
    }

    return new WP_REST_Response($activities, 200);
}



