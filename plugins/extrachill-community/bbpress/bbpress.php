<?php
/*
 * Template Name: bbPress Template
 */

get_header();

?>

<section class="main-content">

extrachill_breadcrumbs();

// Check if we are on a user profile page
$isUserProfile = bbp_is_single_user();

if (!$isUserProfile) {
    echo '<div class="ec-edge-gutter"><h1>' . esc_html(get_the_title()) . '</h1></div>';
}

// Output the standard WordPress content within the div
if (have_posts()) :
    while (have_posts()) : the_post();
        the_content();
    endwhile;
endif;

?>

</section>

<?php
get_footer();
