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

if (is_user_logged_in()) {
    echo '<p>Logged in as <a href="/user-dashboard">' . esc_html(wp_get_current_user()->display_name) . '</a>';
    if ($isUserProfile) {
        // Only add "Go Back" on user profile pages
        echo ' | <a href="javascript:history.back()">Go Back</a>';
    }
    echo '</p>';
} else {
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a>';
    if ($isUserProfile) {
        // Only add "Go Back" on user profile pages
        echo ' | <a href="javascript:history.back()">Go Back</a>';
    }
    echo '</p>';
}

echo '</div>'; // End of chill-home-header


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
