<?php
/**
 * Topic Sorting System
 * 
 * AJAX-powered sorting and filtering for forum topics with search capability.
 * Provides dynamic content updates without page refreshes.
 * 
 * @package ExtraChillCommunity
 */

// Enqueue sorting script
function enqueue_sorting_script() {
    wp_enqueue_script('sorting', EXTRACHILL_COMMUNITY_PLUGIN_URL . 'js/sorting.js', ['jquery'], null, true);

    // Localize script to pass AJAX URL and nonce
    wp_localize_script('sorting', 'extraChillAjax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('extrachill_sort_nonce')
    ]);
}
// add_action('wp_enqueue_scripts', 'enqueue_sorting_script');
/**
 * AJAX handler for topic sorting and search
 */
function extrachill_ajax_search() {
    check_ajax_referer('extrachill_sort_nonce', 'nonce');

    $forum_id = isset($_GET['forum_id']) ? absint($_GET['forum_id']) : 0;

    $args = [
        'post_type'      => 'topic',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
        'post_parent'    => $forum_id,
    ];

    $query = new WP_Query($args);

    ob_start();

    if ($query->have_posts()) {
        echo '<div class="bbp-body">';
        while ($query->have_posts()) {
            $query->the_post();
            
            // Set up bbPress global context for template tags
            bbpress()->current_topic_id = get_the_ID();

            // Load your exact topic card template part:
            bbp_get_template_part('loop', 'single-topic-card');
        }
        echo '</div>';
    } else {
        echo '<div class="bbp-body"><p>No topics found.</p></div>';
    }

    wp_reset_postdata();
    echo ob_get_clean();
    wp_die();
}



// add_action('wp_ajax_extrachill_ajax_search', 'extrachill_ajax_search');
// add_action('wp_ajax_nopriv_extrachill_ajax_search', 'extrachill_ajax_search');

// Explicit sorting functions (no filters)
function extrachill_sort_topics_by_upvotes($args) {
    $args['meta_key'] = 'upvote_count';
    $args['orderby'] = 'meta_value_num';
    $args['order'] = 'DESC';
    return $args;
}

function extrachill_sort_topics_by_popular($args) {
    global $wpdb;
    $popular_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT p.post_parent FROM $wpdb->posts p
         INNER JOIN $wpdb->posts t ON p.post_parent = t.ID
         WHERE p.post_type = %s AND t.post_type = %s AND p.post_date >= %s
         GROUP BY p.post_parent ORDER BY COUNT(p.ID) DESC LIMIT 15",
        bbp_get_reply_post_type(), bbp_get_topic_post_type(), date('Y-m-d H:i:s', strtotime('-90 days'))
    ));
    $args['post__in'] = !empty($popular_ids) ? $popular_ids : [0];
    $args['orderby'] = 'post__in';
    return $args;
}

function extrachill_search_topics_and_replies($args, $search_term) {
    $args['s'] = $search_term;
    return $args;
}
