<?php
/**
 * Homepage Query Functions
 * 
 * Centralized database queries specifically for homepage/front-page functionality.
 * Includes forum fetching, recent topics, and latest activity queries.
 * 
 * @package Extra ChillCommunity
 * @version 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get standardized query arguments for homepage forums display.
 * Used by bbpress/loop-forums.php for the Community Forums section.
 *
 * @return array Query arguments for bbp_has_forums()
 */
function extrachill_get_homepage_forums_args() {
    return array(
        'post_parent' => 0,
        'meta_query' => array(
            array(
                'key' => '_show_on_homepage',
                'value' => '1',
                'compare' => '='
            ),
        ),
        'orderby' => 'meta_value',
        'meta_key' => '_bbp_last_active_time',
        'order' => 'DESC',
        'posts_per_page' => -1,
    );
}

/**
 * Fetches forums that are marked to show on the homepage.
 * Uses the _show_on_homepage boolean meta field.
 *
 * @return array Array of forum IDs
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
 * Fetches all artist forum IDs from artist_profile CPTs.
 *
 * @return array An array of artist forum IDs.
 */
function extrachill_fetch_all_artist_forum_ids() {
    $all_artist_profiles_query = new WP_Query(array(
        'post_type' => 'artist_profile',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'no_found_rows' => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ));

    $artist_forum_ids = array();
    if ($all_artist_profiles_query->have_posts()) {
        foreach ($all_artist_profiles_query->posts as $artist_profile_cpt_id) {
            $forum_id = get_post_meta($artist_profile_cpt_id, '_artist_forum_id', true);
            if (!empty($forum_id) && is_numeric($forum_id)) {
                $artist_forum_ids[] = absint($forum_id);
            }
        }
    }
    wp_reset_postdata();
    return array_unique(array_filter($artist_forum_ids));
}

/**
 * Get recent topics for homepage "Recently Active Topics" section.
 * Centralized version of the query from bbpress/front-page-recently-active.php
 *
 * @param int $count Number of topics to return (default: 3)
 * @return array Array of topic IDs
 */
function extrachill_get_recent_topics_for_homepage($count = 3) {
    $args = array(
        'post_type'      => bbp_get_topic_post_type(),
        'posts_per_page' => 10, // Fetch more to allow for potential exclusions
        'post_status'    => 'publish',
        'orderby'        => 'meta_value',
        'meta_key'       => '_bbp_last_active_time',
        'meta_type'      => 'DATETIME',
        'order'          => 'DESC',
        'fields'         => 'ids',
    );
    
    $query = new WP_Query($args);
    $recently_active_topic_ids = array();

    if ($query->have_posts()) {
        foreach ($query->posts as $topic_id) {
            $recently_active_topic_ids[] = $topic_id;
            if (count($recently_active_topic_ids) >= $count) {
                break;
            }
        }
    }
    wp_reset_postdata();

    return $recently_active_topic_ids;
}

/**
 * Fetches latest post info for homepage forums.
 * Uses the new boolean meta field instead of section-based approach.
 * 
 * @return string HTML output for latest activity display
 */
function fetch_latest_post_info_for_homepage() {
    $homepage_forum_ids = extrachill_fetch_homepage_forums();
    $artist_forum_ids = extrachill_fetch_all_artist_forum_ids();
    
    // Combine forum IDs, removing duplicates
    $all_forum_ids = array_merge($homepage_forum_ids, $artist_forum_ids);
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

/**
 * Constructs HTML output for latest activity display.
 * Helper function for fetch_latest_post_info_for_homepage().
 * 
 * @param int|false $current_post_id The post ID to display, or false if none
 * @return string HTML output for activity display
 */
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