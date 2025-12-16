<?php
/**
 * Co-Authors Plus Integration
 *
 * Blog-specific integration for Co-Authors Plus plugin.
 * Hooks into theme's extrachill_post_meta_author filter for author display.
 *
 * @package ExtraChillBlog
 * @since 0.3.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provide co-authors display for post meta
 * Hooks into theme's extrachill_post_meta_author filter
 *
 * @param string  $author_html Current author HTML (empty by default)
 * @param WP_Post $post        Current post object
 * @return string Author HTML with co-authors links, or empty to use default
 */
function extrachill_blog_coauthors_display( $author_html, $post ) {
	if ( function_exists( 'coauthors_posts_links' ) ) {
		ob_start();
		coauthors_posts_links();
		return ob_get_clean();
	}
	return $author_html;
}
add_filter( 'extrachill_post_meta_author', 'extrachill_blog_coauthors_display', 10, 2 );

/**
 * Register co-authors REST API fields
 * Only runs when Co-Authors Plus is active
 */
if ( function_exists( 'get_coauthors' ) ) {
	add_action( 'rest_api_init', 'extrachill_blog_register_coauthors' );

	function extrachill_blog_register_coauthors() {
		register_rest_field( 'post',
			'coauthors',
			array(
				'get_callback'    => 'extrachill_blog_get_coauthors',
				'update_callback' => null,
				'schema'          => null,
			)
		);
	}

	/**
	 * Get co-authors data for REST API
	 */
	function extrachill_blog_get_coauthors( $object, $field_name, $request ) {
		$coauthors = get_coauthors( $object['id'] );

		$authors = array();
		if ( ! empty( $coauthors ) ) {
			foreach ( $coauthors as $author ) {
				$authors[] = array(
					'display_name' => $author->display_name,
					'user_nicename' => $author->user_nicename
				);
			}
		} else {
			$default_author = get_userdata( get_post_field( 'post_author', $object['id'] ) );
			if ( $default_author ) {
				$authors[] = array(
					'display_name' => $default_author->display_name,
					'user_nicename' => $default_author->user_nicename
				);
			}
		}

		return $authors;
	}
}

/**
 * Dequeue Co-Authors Plus styles when not needed
 * Only runs on blog sites where the plugin is active
 */
add_action( 'wp_enqueue_scripts', 'extrachill_blog_dequeue_coauthors_styles', 100 );

function extrachill_blog_dequeue_coauthors_styles() {
	if ( is_admin() ) {
		return;
	}

	// Only dequeue if not on a single post or if co-authors functions don't exist
	if ( ! is_single() || ! function_exists( 'get_coauthors' ) ) {
		wp_dequeue_style( 'co-authors-plus-coauthors-style' );
		wp_dequeue_style( 'co-authors-plus-avatar-style' );
		wp_dequeue_style( 'co-authors-plus-name-style' );
		wp_dequeue_style( 'co-authors-plus-image-style' );
	}
}