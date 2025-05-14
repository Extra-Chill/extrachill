<?php
/**
 * Template Name: Main Blog Comments Feed
 *
 * @package YourThemeName
 */

get_header();
?>
<div <?php generate_do_attr( 'page' ); ?>>
    <?php
    /**
     * generate_inside_site_container hook.
     */
    do_action( 'generate_inside_site_container' );
    ?>
    <div <?php generate_do_attr( 'site-content' ); ?>>
        <?php
        /**
         * generate_inside_container hook.
         */
        do_action( 'generate_inside_container' );
        ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

echo '<div id="chill-home">';
echo '<div id="chill-home-header"><span>';

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';

    // Display the follow button only on user profile pages
    // -- REMOVED Follow Button Call --
    /*
    if (function_exists('extrachill_follow_button')) {
        extrachill_follow_button(bbp_get_displayed_user_id());
    }
    */
} else {
    // Display the title for non-profile pages
    echo '<h1>' . get_the_title() . '</h1>';
}

echo '</span>';

if (is_user_logged_in()) :
    echo '<p>Logged in as <a href="<?php echo bbp_get_user_profile_url(wp_get_current_user()->ID); ?>">' . esc_html(wp_get_current_user()->display_name) . '.</a></p>';
else :
    echo '<p>You are not signed in. <a href="/login">Login</a> or <a href="/register">Register</a></p>';
endif;

echo '</div>'; // End of chill-home-header

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

        </div><!-- .site-content -->
    </div><!-- .page -->
<?php
get_sidebar();
get_footer();
?>
