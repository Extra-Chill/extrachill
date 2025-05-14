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
    // Check if the user is part of the Extra Chill team
    if (is_user_logged_in() && get_user_meta(get_current_user_id(), 'extrachill_team', true) == '1') {
        return array(); // No exclusion needed for team members
    }

    // Check if private forum IDs are already stored in an option
    $private_forum_ids = get_option('extrachill_private_forum_ids');

    // If private forums have not been stored or empty, fetch and update
    if ($private_forum_ids === false || empty($private_forum_ids)) {
        $private_forum_ids_query = array(
            'post_type' => bbp_get_forum_post_type(),
            'meta_key'   => '_require_extrachill_team',
            'meta_value' => '1',
            'fields' => 'ids',
            'posts_per_page' => -1,
        );

        // Get the private forum IDs
        $private_forum_ids = get_posts($private_forum_ids_query);

        // Store them permanently using update_option()
        update_option('extrachill_private_forum_ids', $private_forum_ids);
    }

    return $private_forum_ids;
}

function extrachill_refresh_private_forum_ids() {
    $private_forum_ids_query = array(
        'post_type' => bbp_get_forum_post_type(),
        'meta_key'   => '_require_extrachill_team',
        'meta_value' => '1',
        'fields' => 'ids',
        'posts_per_page' => -1,
    );

    // Get and update the option with new private forum IDs
    $private_forum_ids = get_posts($private_forum_ids_query);
    update_option('extrachill_private_forum_ids', $private_forum_ids);

    return $private_forum_ids;
}

// extrachill_refresh_private_forum_ids();



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

