<?php
/*
 * Template Name: bbPress Template
 */

get_header();

extrachill_breadcrumbs();

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if (!$isUserProfile) {
    echo '<h1>' . esc_html(get_the_title()) . '</h1>';

    // ✅ Check if we are on a single topic page
    if (bbp_is_single_topic()) {
        ?><div class="views-container"><?php
        do_action( 'extrachill_share_button' );
        echo '<p class="topic-views">';
        ec_the_post_views();
        echo '</p>';
        ?></div><?php
    }
}

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;
?>

<?php get_footer(); ?>
