<?php
/**
 * Template Name: Main Blog Comments Feed
 *
 * @package YourThemeName
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

// Retrieve the user_id query variable value from the URL
$community_user_id = get_query_var('user_id', 0); // Default to 0 if not set
// Fetch user data based on the community_user_id
$user_info = get_userdata($community_user_id);
$user_nicename = $user_info ? $user_info->user_nicename : null;

// Check if a valid user ID is present
if ($user_nicename) {
    // Construct the link back to the user's bbPress profile
    $profile_link = '/u/' . $user_nicename;
    echo '<p><a href="' . esc_url($profile_link) . '">Back to Profile</a></p>'; // Display the link
}


?>


    <?php
    while ( have_posts() ) : the_post();

        // Display comments for the user ID obtained from the URL
        if ($community_user_id) {
            echo display_main_site_comments_for_user($community_user_id);
        } else {
            echo 'User ID not provided.';
        }

    endwhile; // End of the loop.
    echo '</div>'; // End of chill-home

    ?>

<?php
get_sidebar();
get_footer();
?>
