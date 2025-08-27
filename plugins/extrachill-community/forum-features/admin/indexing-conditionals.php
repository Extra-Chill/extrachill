<?php
// Function to conditionally modify robots meta tag for user topic and reply pages
function extrachill_conditional_noindex_yoast($robots) {
    if (is_bbpress() && bbp_is_single_user()) {
        $user_id = bbp_get_displayed_user_id();

        // Get the current URL
        global $wp;
        $current_url = add_query_arg(array(), home_url($wp->request));

        // Check if we are on the user's topics or replies page
        $is_topics_page = strpos($current_url, '/topics') !== false;
        $is_replies_page = strpos($current_url, '/replies') !== false;

        // Get user's topics and replies count
        $topics_count = bbp_get_user_topic_count_raw($user_id);
        $replies_count = bbp_get_user_reply_count_raw($user_id);

        // Conditionally set noindex meta tag
        if (($is_topics_page && $topics_count == 0) || ($is_replies_page && $replies_count == 0)) {
            $robots = 'noindex,follow';
        } else {
            $robots = 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';
        }
    }

    return $robots;
}
add_filter('wpseo_robots', 'extrachill_conditional_noindex_yoast');
