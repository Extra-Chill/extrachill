<?php
/*
 * Template Name: Following Topics Feed
 * Description: A page template to show the most recently updated topics that the users the current user is following have participated in as either the topic starter or the most recent replier.
 */

get_header();

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';

    // Display the follow button only on user profile pages
    if (function_exists('extrachill_follow_button')) {
        extrachill_follow_button(bbp_get_displayed_user_id());
    }
} else {
    echo '<h1>Following Topics</h1>';
}

echo '</span>';

if (is_user_logged_in()) :
    echo '<p>Logged in as <a href="/user-dashboard">' . esc_html(wp_get_current_user()->display_name) . '.</a></p>';
else :
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p>';
endif;

echo '</div>'; // End of chill-home-header

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home

if (is_user_logged_in()) {
    $current_user_id = get_current_user_id();
    $following_users = extrachill_get_followed_users(); // Fetch followed users
    if (!is_array($following_users)) {
        $following_users = array();
    }
} else {
    $following_users = array(); // No users to follow if not logged in
}

// Collect IDs of followed users
$followed_user_ids = array_map(function($user) {
    return $user->ID;
}, $following_users);

if (!empty($followed_user_ids)) {
    global $wpdb;

    // Get topics where followed users are either the topic starter or the most recent replier
    $topic_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT p.ID
        FROM $wpdb->posts p
        LEFT JOIN $wpdb->postmeta pm ON p.ID = pm.post_id
        WHERE p.post_type = %s
        AND (
            p.post_author IN (" . implode(',', $followed_user_ids) . ")
            OR (
                pm.meta_key = '_bbp_last_active_id'
                AND pm.meta_value IN (" . implode(',', $followed_user_ids) . ")
            )
        )
    ", bbp_get_topic_post_type()));

    if (!empty($topic_ids)) {
        // Set up the query to fetch the most recent topics
        $args = array(
            'post_type' => bbp_get_topic_post_type(),
            'posts_per_page' => 15,
            'paged' => bbp_get_paged(),  // use bbp_get_paged() to get the correct page number
            'post__in' => $topic_ids,
            'meta_key' => '_bbp_last_active_time',
            'orderby' => 'meta_value',
            'order' => 'DESC',
        );

        if (bbp_has_topics($args)) {
            ?>
            <div id="bbpress-forums" class="bbpress-wrapper">
                <?php bbp_get_template_part('pagination', 'topics'); // Pagination above the topics ?>
                <?php bbp_get_template_part('loop', 'topics'); // The loop that displays topics ?>
                <?php bbp_get_template_part('pagination', 'topics'); // Pagination below the topics ?>
            </div>
            <?php
        } else {
            echo '<div class="bbp-template-notice"><p>No recent topics found.</p></div>';
        }
    } else {
        echo '<div class="bbp-template-notice"><p>No recent topics found.</p></div>';
    }
} else {
    echo '<div class="bbp-template-notice"><p>You are not following any users.</p></div>';
}

get_footer();
?>
