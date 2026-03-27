<?php
/**
 * Template Name: Main Blog Comments Feed
 *
 * Displays comments from the main blog site in the community interface.
 *
 * @package ExtraChillCommunity
 */

get_header();
?>
<?php extrachill_breadcrumbs(); ?>

<?php

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<div class="page-content"><h1 class="profile-title-inline">' . $title . '</h1></div>';

} else {
    // Display the title for non-profile pages
    echo '<div class="page-content"><h1>' . get_the_title() . '</h1></div>';
}

if (is_user_logged_in()) :
    echo '<div class="page-content"><p>Logged in as <a href="' . bbp_get_user_profile_url(wp_get_current_user()->ID) . '">' . esc_html(wp_get_current_user()->display_name) . '.</a></p></div>';
else :
    echo '<div class="page-content"><p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p></div>';
endif;

?>


    <?php
    while ( have_posts() ) : the_post();

        // Get user ID from URL parameter
        $community_user_id = get_query_var('user_id', 0);
        
        // Display comments for the user ID obtained from the URL
        if ($community_user_id) {
            echo function_exists('display_main_site_comments_for_user') ? display_main_site_comments_for_user($community_user_id) : '<div class="bbpress-comments-error">Multisite plugin not activated.</div>';
        } else {
            echo 'User ID not provided.';
        }

    endwhile; // End of the loop.

    ?>
<?php
get_footer();
?>
