<?php
// home/community-activity.php - Community Activity for 3x3 Grid

// Use WordPress object cache for performance
$cache_key = 'extrachill_recent_activity';
$activities = wp_cache_get($cache_key);

// If not in cache, query the database directly
if ($activities === false) {
    // Switch to community site in multisite network (blog ID 2)
    $current_blog_id = get_current_blog_id();
    switch_to_blog(2);

    // Query recent bbPress activity directly
    $args = array(
        'post_type' => array('topic', 'reply'),
        'post_status' => 'publish',
        'posts_per_page' => 3,
        'orderby' => 'date',
        'order' => 'DESC',
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_bbp_forum_id',
                'value' => array(),
                'compare' => 'NOT IN',
            ),
            array(
                'key' => '_bbp_forum_id',
                'value' => '1494',
                'compare' => '!=',
            ),
        ),
    );

    $query = new WP_Query($args);
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
            $date_time = get_the_date('c');
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
                'topic_url' => $topic_url,
            );
        }
        wp_reset_postdata();
    }

    restore_current_blog();

    // Cache for 10 minutes using WordPress object cache
    wp_cache_set($cache_key, $activities, '', 10 * MINUTE_IN_SECONDS);
}

if (!empty($activities)) {
    $counter = 0;
    foreach ($activities as $activity) {
        if (!is_array($activity) || $counter >= 3) {
            continue;
        }
        
        $dateFormatted = custom_human_time_diff(strtotime($activity['date_time']));
        $counter++;
        
        if ($activity['type'] === 'Reply') {
            printf(
                '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> replied to <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                esc_url($activity['user_profile_url']),
                esc_html($activity['username']),
                $counter,
                esc_url($activity['topic_url']),
                esc_html($activity['topic_title']),
                esc_url($activity['forum_url']),
                esc_html($activity['forum_title']),
                $dateFormatted
            );
        } else { // Topic
            printf(
                '<div class="home-3x3-card home-3x3-community-card"><a href="%s">%s</a> posted <a id="topic-%d" href="%s">%s</a> in <a href="%s">%s</a> - %s</div>',
                esc_url($activity['user_profile_url']),
                esc_html($activity['username']),
                $counter,
                esc_url($activity['topic_url']),
                esc_html($activity['topic_title']),
                esc_url($activity['forum_url']),
                esc_html($activity['forum_title']),
                $dateFormatted
            );
        }
    }
} else {
    echo '<div class="home-3x3-card home-3x3-empty">No recent activity.</div>';
}
?>