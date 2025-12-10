<?php
/**
 * Newsletter Homepage Integration
 *
 * Handles homepage template override and custom archive header
 * for newsletter.extrachill.com (homepage-as-archive pattern).
 *
 * @package ExtraChillNewsletter
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_render_homepage() {
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
	if ( ! $newsletter_blog_id || get_current_blog_id() !== $newsletter_blog_id ) {
		return;
	}
	include EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/core/templates/homepage.php';
}
add_action( 'extrachill_homepage_content', 'newsletter_render_homepage' );
function newsletter_homepage_query( $query ) {
	// Only modify main query on newsletter site homepage
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
	if ( $newsletter_blog_id && ! is_admin() && $query->is_main_query() && is_front_page() && get_current_blog_id() === $newsletter_blog_id ) {
		$query->set( 'post_type', 'newsletter' );
		$query->set( 'posts_per_page', 10 );
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}
}
add_action( 'pre_get_posts', 'newsletter_homepage_query' );
function newsletter_override_archive_header() {
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
	if ( ! $newsletter_blog_id || get_current_blog_id() !== $newsletter_blog_id || ! is_front_page() ) {
		return;
	}

	?>
	<header class="page-header">
		<h1 class="page-title">Extra Chill Newsletters</h1>
	</header>
	<div class="taxonomy-description">
		<p>Original music journalism from the underground.</p>
	</div>
	<?php

	// Remove the default archive header
	remove_action( 'extrachill_archive_header', 'extrachill_default_archive_header', 10 );
}
add_action( 'extrachill_archive_header', 'newsletter_override_archive_header', 5 );
