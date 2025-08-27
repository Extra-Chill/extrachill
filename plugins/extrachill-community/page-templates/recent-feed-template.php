<?php
/*
 * Template Name: Recent Activity Feed
 * Description: A page template to show the most recent replies across all forums in a Twitter-like stream.
 */

get_header();
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
} else {
    echo '<h1>Recent Activity</h1>';
}

echo '</span>';

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home-header

// Set up the query to fetch the most recent replies
if (extrachill_get_recent_feed_query(15)) {
?>
    <div id="bbpress-forums" class="bbpress-wrapper">
        <?php bbp_get_template_part('pagination', 'replies'); // Pagination above the replies ?>
        <?php bbp_get_template_part('loop', 'replies'); // The loop that displays replies ?>
        <?php bbp_get_template_part('pagination', 'replies'); // Pagination below the replies ?>
    </div>
<?php
} else {
    echo '<div class="bbp-template-notice"><p>No recent activity found.</p></div>';
}
echo '</div>'; // End of chill-home

?>
    <?php // The closing divs for .site-content and .page have been removed. ?>
<?php
get_footer();
?>
