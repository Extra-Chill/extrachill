<?php
/**
 * Public Stories abilities for the main publication.
 *
 * Projection and schemas live in the network-wide foundation.
 *
 * @package ExtraChillBlog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', 'extrachill_blog_register_public_projection_ability_category' );
add_action( 'wp_abilities_api_init', 'extrachill_blog_register_stories_abilities' );

/**
 * Register Stories for main-site posts.
 *
 * @return void
 */
function extrachill_blog_register_stories_abilities() {
	// Network-activated extrachill-network provides the shared projection. Guarding
	// keeps deploy order safe: no fatal if this plugin lands before the network one.
	if ( ! function_exists( 'extrachill_register_stories_abilities' ) ) {
		return;
	}

	extrachill_register_stories_abilities( 'post', 'extrachill-blog' );
}
