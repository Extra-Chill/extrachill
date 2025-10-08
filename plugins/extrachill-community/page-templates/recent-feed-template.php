<?php
/*
 * Template Name: Recent Activity Feed
 * Description: A page template to show the most recent replies across all forums in a Twitter-like stream.
 */

get_header();
?>
<div class="page-content">
    <?php
    /**
     * Custom hook for inside site container.
     */
    do_action( 'extra_chill_inside_site_container' );
    ?>
    <div class="site-content">
        <div class="container">
            <?php
            /**
             * Custom hook for inside container.
             */
            do_action( 'extra_chill_inside_container' );
            ?>
        <?php extrachill_breadcrumbs(); ?>

<?php

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if ($isUserProfile) {
    $title = '@' . bbp_get_displayed_user_field('user_nicename');
    echo '<h1 class="profile-title-inline">' . $title . '</h1>';
} else {
    echo '<h1>Recent Activity</h1>';
}

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

// Set up the query to fetch the most recent replies
if (extrachill_get_recent_feed_query(15)) {
    // bbPress stores query in bbpress()->reply_query
    $bbp = bbpress();
    ?>
    <div id="bbpress-forums" class="bbpress-wrapper">
        <?php bbp_get_template_part('loop', 'replies'); ?>
        <?php
        // Pagination at bottom
        if ( ! empty( $bbp->reply_query ) ) {
            extrachill_pagination( $bbp->reply_query, 'bbpress' );
        }
        ?>
    </div>
    <?php
} else {
    echo '<div class="bbp-template-notice"><p>No recent activity found.</p></div>';
}

?>
        </div><!-- .container -->
    </div><!-- .site-content -->
</div><!-- .page-content -->
<?php
get_footer();
?>
