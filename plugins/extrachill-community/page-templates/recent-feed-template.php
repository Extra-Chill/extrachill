<?php
/*
 * Template Name: Recent Topics Feed
 * Description: A page template to show the most recently updated topics in the forum.
 */

get_header();
?>
    <?php
    /**
     * generate_inside_site_container hook.
     * This hook is inside the #page div element, handled by GeneratePress.
     */
    do_action( 'generate_inside_site_container' );
    ?>
        <?php
        /**
         * generate_inside_container hook.
         * This hook is inside the .container div element (itself inside #content), handled by GeneratePress.
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
} else {
    echo '<h1>Recently Active Topics</h1>';
}

echo '</span>';

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

echo '</div>'; // End of chill-home-header

// Set up the query to fetch the most recent topics
$args = array(
    'post_type' => bbp_get_topic_post_type(),
    'posts_per_page' => 15,
    'paged' => bbp_get_paged(),  // use bbp_get_paged() to get the correct page number
    'meta_key' => '_bbp_last_active_time',
    'orderby' => 'meta_value',
    'order' => 'DESC',
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key' => '_bbp_last_active_time',
            'compare' => 'EXISTS'
        ),
        array(
            'key' => '_bbp_forum_id',
            'value' => '1494',
            'compare' => '!='
        )
    ),
    'post_status' => array('publish', 'closed', 'acf-disabled', 'private', 'hidden')
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
echo '</div>'; // End of chill-home

?>
    <?php // The closing divs for .site-content and .page have been removed. ?>
<?php
get_footer();
?>
