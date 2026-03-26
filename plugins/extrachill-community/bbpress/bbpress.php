<?php
/*
 * Template Name: bbPress Template
 */

get_header();

echo '<section class="main-content">';

extrachill_breadcrumbs();

$isUserProfile = bbp_is_single_user();

if ( ! $isUserProfile ) {
	echo '<div class="ec-edge-gutter"><h1>' . esc_html( get_the_title() ) . '</h1></div>';
}

if ( have_posts() ) :
	while ( have_posts() ) : the_post();
		the_content();
	endwhile;
endif;

echo '</section>';

get_footer();
