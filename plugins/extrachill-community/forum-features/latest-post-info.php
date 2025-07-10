<?php

function custom_human_time_diff($from, $to = '') {
    if (empty($to)) {
        $to = time();
    }
    $diff = (int) abs($to - $from);

    if ($diff < MINUTE_IN_SECONDS) {
        $since = sprintf(_n('%ss', '%ss', $diff), $diff);
    } elseif ($diff < HOUR_IN_SECONDS) {
        $minutes = floor($diff / MINUTE_IN_SECONDS);
        $since = sprintf(_n('%sm', '%sm', $minutes), $minutes);
    } elseif ($diff < DAY_IN_SECONDS) {
        $hours = floor($diff / HOUR_IN_SECONDS);
        $since = sprintf(_n('%sh', '%sh', $hours), $hours);
    } elseif ($diff < WEEK_IN_SECONDS) {
        $days = floor($diff / DAY_IN_SECONDS);
        $since = sprintf(_n('%sd', '%sd', $days), $days);
    } elseif ($diff < MONTH_IN_SECONDS) {
        $weeks = floor($diff / WEEK_IN_SECONDS);
        $since = sprintf(_n('%sw', '%sw', $weeks), $weeks);
    } elseif ($diff < YEAR_IN_SECONDS) {
        $years = floor($diff / YEAR_IN_SECONDS);
        $since = sprintf(_n('%syr', '%syr', $years), $years);
    }
    return $since . __(' ago');
}

/**
 * Fetches all band forum IDs from band_profile CPTs.
 *
 * @return array An array of band forum IDs.
 */
function extrachill_fetch_all_band_forum_ids() {
    $all_band_profiles_query = new WP_Query(array(
        'post_type' => 'band_profile',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));

    $band_forum_ids = array();
    if ($all_band_profiles_query->have_posts()) {
        foreach ($all_band_profiles_query->posts as $band_profile_cpt_id) {
            $forum_id = get_post_meta($band_profile_cpt_id, '_band_forum_id', true);
            if (!empty($forum_id) && is_numeric($forum_id)) {
                $band_forum_ids[] = absint($forum_id);
            }
        }
    }
    wp_reset_postdata();
    return array_unique(array_filter($band_forum_ids));
}

/**
 * Fetches forums that are marked to show on the homepage
 * Uses the new boolean meta field instead of section-based approach
 */
function extrachill_fetch_homepage_forums() {
    $forums_args = array(
        'post_type' => bbp_get_forum_post_type(),
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_show_on_homepage',
                'value' => '1',
                'compare' => '='
            ),
        ),
    );
    return get_posts($forums_args);
}

/**
 * Fetches latest post info for homepage forums
 * Uses the new boolean meta field instead of section-based approach
 */
function fetch_latest_post_info_for_homepage() {
    $homepage_forum_ids = extrachill_fetch_homepage_forums();
    $band_forum_ids = extrachill_fetch_all_band_forum_ids();
    
    // Combine forum IDs, removing duplicates
    $all_forum_ids = array_merge($homepage_forum_ids, $band_forum_ids);
    $all_forum_ids = array_unique(array_filter($all_forum_ids));

    if (empty($all_forum_ids)) {
        return "<div class=\"extrachill-recent-activity\"><p>No forums found for homepage.</p></div>";
    }
    
    // Query for the single latest post across all these forums
    $recent_activity_args = array(
        'post_type' => array(bbp_get_topic_post_type(), bbp_get_reply_post_type()),
        'posts_per_page' => 1,
        'post_status' => array('publish', 'closed'),
        'orderby' => 'date', // Order by post_date (latest first)
        'order' => 'DESC',
        'meta_query' => array(
            array(
                'key' => '_bbp_forum_id',
                'value' => $all_forum_ids,
                'compare' => 'IN',
            ),
        ),
        'no_found_rows' => true,
        'update_post_term_cache' => false,
        'update_post_meta_cache' => false,
    );
    
    $latest_post_query = new WP_Query($recent_activity_args);
    $current_post_id = false;
    if ($latest_post_query->have_posts()) {
        $latest_post_query->the_post();
        $current_post_id = get_the_ID();
        wp_reset_postdata();
    }

    return extrachill_construct_activity_output($current_post_id);
}

function fetch_latest_post_info_by_section($forum_section) {
    // For the 'top' section, we need to consider both standard forums and band forums.
    if ($forum_section === 'top') {
        $standard_forum_ids = extrachill_fetch_forums_by_section($forum_section); // Gets forums by section meta
        $band_forum_ids = extrachill_fetch_all_band_forum_ids(); // Gets all band forum IDs
        
        // Combine forum IDs, removing duplicates
        $all_forum_ids = array_merge($standard_forum_ids, $band_forum_ids);
        $all_forum_ids = array_unique(array_filter($all_forum_ids));

        if (empty($all_forum_ids)) {
            return "<div class=\"extrachill-recent-activity\"><p>No forums found in this section.</p></div>";
        }
        
        // Query for the single latest post across all these forums
        $recent_activity_args = array(
            'post_type' => array(bbp_get_topic_post_type(), bbp_get_reply_post_type()),
            'posts_per_page' => 1,
            'post_status' => array('publish', 'closed'),
            'orderby' => 'date', // Order by post_date (latest first)
            'order' => 'DESC',
            'meta_query' => array(
                array(
                    'key' => '_bbp_forum_id',
                    'value' => $all_forum_ids,
                    'compare' => 'IN',
                ),
            ),
            'no_found_rows' => true,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
        );
        
        $latest_post_query = new WP_Query($recent_activity_args);
        $current_post_id = false;
        if ($latest_post_query->have_posts()) {
            $latest_post_query->the_post();
            $current_post_id = get_the_ID();
            wp_reset_postdata();
        }

        return extrachill_construct_activity_output($current_post_id);

    } else {
        // Existing logic for other sections remains the same
        $forum_ids = extrachill_fetch_forums_by_section($forum_section);
        if (empty($forum_ids)) {
            return "<div class=\"extrachill-recent-activity\"><p>No forums found in this section.</p></div>";
        }

        $recent_activity_query = extrachill_customize_query_for_forum($forum_ids);
        $current_post_id = extrachill_fetch_latest_activity($recent_activity_query, $forum_ids);

        return extrachill_construct_activity_output($current_post_id);
    }

}

function extrachill_fetch_forums_by_section($forum_section) {
    $forums_args = array(
        'post_type' => bbp_get_forum_post_type(),
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_bbp_forum_section',
                'value' => $forum_section,
                'compare' => '='
            ),
        ),
    );
    // Do NOT explicitly include 5432 here anymore, it's handled in fetch_latest_post_info_by_section
    return get_posts($forums_args);
}

function extrachill_customize_query_for_forum($forum_ids, $posts_per_page = 5) {
    // This function is now only used for sections other than 'top'.
    // The logic for 'top' is handled directly in fetch_latest_post_info_by_section.

    // Include replies in the post types for all forums
    $post_types = array(bbp_get_topic_post_type(), bbp_get_reply_post_type());

    // Arguments for the WP_Query
    $recent_activity_args = array(
        'post_type' => $post_types,
        'posts_per_page' => 1,
        'meta_query' => array(
            array(
                'key' => '_bbp_last_active_time',
                'compare' => 'EXISTS',
            ),
        ),
        'orderby' => 'meta_value',
        'order' => 'DESC',
        'post_parent__in' => $forum_ids,
        'no_found_rows' => true, // Improve performance when pagination is not needed
    );

    // Execute the query
    $query = new WP_Query($recent_activity_args);

    // Check for errors in the query
    if (is_wp_error($query)) {
        return null; // Return null or handle the error appropriately
    }

    return $query;
}


function extrachill_fetch_latest_activity($recent_activity_query, $forum_ids) {
    // This function is now only used for sections other than 'top'.
    // The logic for 'top' is handled directly in fetch_latest_post_info_by_section.
    
    if ($recent_activity_query->have_posts()) {
        $recent_activity_query->the_post();
        $latest_post_id = get_the_ID();
        $latest_post_time = get_post_time('U', true, $latest_post_id);  // Get Unix timestamp of the latest post

        // Check if the latest post is a topic and has replies
        if (get_post_type($latest_post_id) === bbp_get_topic_post_type()) {
            $replies_args = array(
                'post_type' => bbp_get_reply_post_type(),
                'posts_per_page' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'post_parent' => $latest_post_id,  // Set this to the ID of the topic to find replies
            );
            $replies_query = new WP_Query($replies_args);
            if ($replies_query->have_posts()) {
                $replies_query->the_post();
                $latest_reply_id = get_the_ID();
                $latest_reply_time = get_post_time('U', true, $latest_reply_id);  // Get Unix timestamp of the latest reply

                // Compare topic and reply times to determine the most recent
                if ($latest_reply_time > $latest_post_time) {
                    return $latest_reply_id;  // Return reply ID if the reply is newer
                }
            }
            wp_reset_postdata();
        }
        
        return $latest_post_id;  // Return topic ID if no newer reply exists
    }

    return false;  // Return false if no posts were found
}


function extrachill_construct_activity_output($current_post_id) {
    if (!$current_post_id) {
        return "<div class=\"extrachill-recent-activity\"><p>No recent activity found in this section.</p></div>";
    }

    // Fetch post information
    $author_id = get_post_field('post_author', $current_post_id);
    $author_name = get_the_author_meta('display_name', $author_id);
    $author_profile_url = bbp_get_user_profile_url($author_id);

    $post_type = get_post_type($current_post_id);
    $topic_id = $post_type === bbp_get_reply_post_type() ? bbp_get_reply_topic_id($current_post_id) : $current_post_id;
    $forum_id = bbp_get_topic_forum_id($topic_id);
    $forum_title = get_the_title($forum_id);
    // Use human_time_diff for verbose output
    $time_diff = human_time_diff( get_post_time('U', true, $current_post_id), current_time('timestamp', true) ) . ' ago';
    
    $reply_url = $post_type === bbp_get_reply_post_type() ? bbp_get_reply_url($current_post_id) : get_permalink($topic_id);
    $type_label = $post_type === bbp_get_reply_post_type() ? 'replied to' : 'posted';
    $title = get_the_title($topic_id);

    // Construct output
    $output = sprintf(
        '<div class="extrachill-recent-activity"><ul><li><b>Latest:</b> <a href="%s">%s</a> %s <a href="%s">%s</a> in <a href="%s">%s</a> - %s</li></ul></div>',
        esc_url($author_profile_url),
        esc_html($author_name),
        $type_label,
        esc_url($reply_url),
        $title,
        esc_url(get_permalink($forum_id)),
        esc_html($forum_title),
        $time_diff
    );

    return $output;
}
