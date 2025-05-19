<?php
// Function to get recent forum topics with pagination
function extrachill_get_recent_forum_topics($paged = 1) {
    $recent_topics_args = array(
        'post_type' => 'topic',
        'posts_per_page' => get_option('posts_per_page'),
        'orderby' => 'date',
        'order' => 'DESC',
        'paged' => $paged,
    );
    $recent_topics = new WP_Query($recent_topics_args);

    return $recent_topics;
}
function extrachill_get_recent_forum_replies($paged = 1) {
    // If private_forum_ids is empty, do not exclude any topics
    $exclude_topics = array();

    // Arguments for fetching recent replies, excluding those under private topics if necessary
    $recent_replies_args = array(
        'post_type' => 'reply',
        'posts_per_page' => get_option('posts_per_page'),
        'orderby' => 'date',
        'order' => 'DESC',
        'paged' => $paged,
    );

    $recent_replies = new WP_Query($recent_replies_args);

    return $recent_replies;
}

function extrachill_get_recent_forum_posts_combined($paged = 1) {
    // Fetch topics and replies separately
    $recent_topics = extrachill_get_recent_forum_topics($paged);
    $recent_replies = extrachill_get_recent_forum_replies($paged);

    // Merge the post objects into a single array
    $combined_posts = array_merge($recent_topics->posts, $recent_replies->posts);

    // Sort the combined array by post date
    usort($combined_posts, function($a, $b) {
        return strcmp($b->post_date, $a->post_date); // Order by date descending
    });

    return $combined_posts;
}

add_action( 'bbp_register_theme_packages', 'bsp_register_plugin_template1' );

// Define the path to your custom template directory
function bsp_get_template1_path() {
    return BSP_PLUGIN_DIR . '/page-templates/recent-feed-template.php';
}

// Register your custom template stack with bbPress
function bsp_register_plugin_template1() {
    bbp_register_template_stack( 'bsp_get_template1_path', 12 );
}

