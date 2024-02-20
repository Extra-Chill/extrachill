<?php
/*
 * Template Name: bbPress Template
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
    // Display the title for non-profile pages
    echo '<h1>' . get_the_title() . '</h1>';
}

echo '</span>';

if (is_user_logged_in()) :
    echo '<p>Logged in as <a href="/user-dashboard">' . esc_html(wp_get_current_user()->display_name) . '.</a></p>';
else :
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p>';
endif;

echo '</div>'; // End of chill-home-header

// Determine the appropriate link based on the context
if (bbp_is_single_topic() || bbp_is_single_reply()) {
    // If in a topic or reply, link back to the forum
    $forum_id = bbp_get_forum_id(bbp_get_topic_forum_id());
    $forum_name = get_the_title($forum_id);
    echo '<p id="back-to-prev"><a href="' . bbp_get_forum_permalink($forum_id) . '">Back to ' . esc_html($forum_name) . '</a></p>';
} elseif (bbp_is_single_forum()) {
    // If in a forum, link back to the community home
    echo '<p id="back-to-prev"><a href="' . home_url('/') . '">Back to Community Home</a></p>';
}
// Insert Back to Previous Page link here
if ($isUserProfile && !empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], get_home_url()) !== false) {
    // Simplified for demonstration; consider enhancing for production use
    echo '<p id="back-to-profile"><a href="' . esc_url($_SERVER['HTTP_REFERER']) . '">Back to Previous Page</a></p>';
}
// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

// Here, we call the function to display online user stats
if (function_exists('display_online_users_stats')) {
    display_online_users_stats();
}
echo '</div>'; // End of chill-home
?>
<?php get_footer(); ?>
