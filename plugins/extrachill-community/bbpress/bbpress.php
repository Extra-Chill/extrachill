<?php
/*
 * Template Name: bbPress Template
 */

get_header();

extrachill_breadcrumbs();

$isUserProfile = bbp_is_single_user();

if ( ! $isUserProfile ) {
	echo '<div class="page-content"><h1>' . esc_html( get_the_title() ) . '</h1></div>';
}

if ( have_posts() ) :
	while ( have_posts() ) : the_post();
		the_content();
	endwhile;
endif;

get_footer();
