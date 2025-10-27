<?php
/**
 * Newsletter Homepage Integration
 *
 * Handles homepage template override and custom archive header
 * for newsletter.extrachill.com (homepage-as-archive pattern).
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function newsletter_override_homepage( $template ) {
	// Only override on newsletter.extrachill.com (blog ID 9)
	if ( get_current_blog_id() === 9 ) {
		return EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/core/templates/homepage.php';
	}

	return $template;
}
add_filter( 'extrachill_template_homepage', 'newsletter_override_homepage' );
function newsletter_homepage_query( $query ) {
	// Only modify main query on newsletter site homepage
	if ( ! is_admin() && $query->is_main_query() && is_front_page() && get_current_blog_id() === 9 ) {
		$query->set( 'post_type', 'newsletter' );
		$query->set( 'posts_per_page', 10 );
		$query->set( 'orderby', 'date' );
		$query->set( 'order', 'DESC' );
	}
}
add_action( 'pre_get_posts', 'newsletter_homepage_query' );
function newsletter_override_archive_header() {
	if ( get_current_blog_id() !== 9 || ! is_front_page() ) {
		return;
	}

	?>
	<header class="page-header">
		<h1 class="page-title">Extra Chill Newsletters</h1>
	</header>
	<div class="taxonomy-description">
		<p>Independent music journalism with personality. Explore our newsletter archive below.</p>
	</div>
	<?php

	// Remove the default archive header
	remove_action( 'extrachill_archive_header', 'extrachill_default_archive_header', 10 );
}
add_action( 'extrachill_archive_header', 'newsletter_override_archive_header', 5 );
