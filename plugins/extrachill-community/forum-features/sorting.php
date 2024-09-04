<?php
function wp_surgeon_sort_topics_by_upvotes($args) {
    // Check if sorting by upvotes is selected
    if (isset($_GET['sort']) && $_GET['sort'] == 'upvotes') {
        $args['meta_key'] = 'upvote_count'; // Assuming 'upvote_count' is stored as post meta
        $args['orderby'] = 'meta_value_num';
        $args['order'] = 'DESC';
    }

    return $args;
}
add_filter('bbp_before_has_topics_parse_args', 'wp_surgeon_sort_topics_by_upvotes');

// Function to filter topics by time range
function wp_surgeon_filter_topics_by_time_range($args) {
    // Limiting posts by time range
    if (isset($_GET['time_range']) && in_array($_GET['time_range'], ['7', '30', '90'])) {
        $days = (int) $_GET['time_range'];
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // Ensure meta_query is an array and initialize if empty
        if (!isset($args['meta_query'])) {
            $args['meta_query'] = array();
        }

        $args['meta_query'][] = array(
            'key' => '_bbp_last_active_time',
            'value' => $date,
            'compare' => '>=',
            'type' => 'DATETIME'
        );
    }

    // Log the query arguments for debugging
    error_log(print_r($args, true));

    return $args;
}
add_filter('bbp_after_has_topics_parse_args', 'wp_surgeon_filter_topics_by_time_range');

// Function to search topics and replies
function wp_surgeon_search_topics_and_replies($args) {
    // Handle searching for topics and replies
    if (isset($_GET['bbp_search']) && !empty($_GET['bbp_search'])) {
        $search_term = sanitize_text_field($_GET['bbp_search']);
        $args['s'] = $search_term;
    }

    return $args;
}
add_filter('bbp_after_has_topics_parse_args', 'wp_surgeon_search_topics_and_replies');

// AJAX handler for the search
function wp_surgeon_ajax_search() {
    $args = array(
        'post_type' => 'topic',
        'posts_per_page' => 15,
        'paged' => 1
    );

    // Handle sorting by upvotes
    if (isset($_GET['sort']) && $_GET['sort'] == 'upvotes') {
        $args['meta_key'] = 'upvote_count';
        $args['orderby'] = 'meta_value_num';
        $args['order'] = 'DESC';
    }

    // Handle filtering by time range
    if (isset($_GET['time_range']) && in_array($_GET['time_range'], ['7', '30', '90'])) {
        $days = (int) $_GET['time_range'];
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $args['meta_query'][] = array(
            'key' => '_bbp_last_active_time',
            'value' => $date,
            'compare' => '>=',
            'type' => 'DATETIME'
        );
    }

    // Handle searching for topics and replies
    if (isset($_GET['bbp_search']) && !empty($_GET['bbp_search'])) {
        $search_term = sanitize_text_field($_GET['bbp_search']);
        $args['s'] = $search_term;
    }

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        ob_start();
        while ($query->have_posts()) {
            $query->the_post();
            bbp_get_template_part('loop', 'single-topic');
        }
        $content = ob_get_clean();
        echo $content;
    } else {
        echo ''; // No posts found
    }

    wp_reset_postdata();
    wp_die();
}
add_action('wp_ajax_wp_surgeon_ajax_search', 'wp_surgeon_ajax_search');
add_action('wp_ajax_nopriv_wp_surgeon_ajax_search', 'wp_surgeon_ajax_search');
