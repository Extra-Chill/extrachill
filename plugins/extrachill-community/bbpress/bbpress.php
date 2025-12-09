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
}

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;
get_footer();
