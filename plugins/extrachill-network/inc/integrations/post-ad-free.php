<?php
/**
 * Per-post ad-free flag.
 *
 * Any singular post, on any network site and of any post type, can opt out of
 * ads with the `_extrachill_ad_free` post meta. It feeds the network ad policy
 * through the canonical `extrachill_should_block_ads` filter, which both
 * extrachill_get_ad_policy() and the ad blocklist output honor, so a flagged
 * post never shows ads to anybody (logged in or not).
 *
 * The meta is registered for REST so the block editor and REST clients can
 * toggle it with ordinary post edit rights.
 *
 * @package ExtraChill\Network
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'EXTRACHILL_AD_FREE_META_KEY' ) ) {
	define( 'EXTRACHILL_AD_FREE_META_KEY', '_extrachill_ad_free' );
}

/**
 * Register the ad-free post meta for every post type.
 */
function extrachill_register_post_ad_free_meta(): void {
	register_post_meta(
		'',
		EXTRACHILL_AD_FREE_META_KEY,
		array(
			'type'              => 'boolean',
			'description'       => 'Never show ads on this post.',
			'single'            => true,
			'default'           => false,
			'show_in_rest'      => true,
			'sanitize_callback' => 'rest_sanitize_boolean',
			'auth_callback'     => static function ( $allowed, $meta_key, $post_id ) {
				return current_user_can( 'edit_post', (int) $post_id );
			},
		)
	);
}
add_action( 'init', 'extrachill_register_post_ad_free_meta' );

/**
 * Whether a post on the current blog is flagged ad-free.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function extrachill_is_post_ad_free( int $post_id ): bool {
	return $post_id > 0 && (bool) get_post_meta( $post_id, EXTRACHILL_AD_FREE_META_KEY, true );
}

/**
 * Block ads on posts flagged ad-free.
 *
 * @hook extrachill_should_block_ads
 * @param bool                 $should_block_ads Whether ads are already blocked.
 * @param array<string, mixed> $context          Ad request context.
 * @return bool
 */
function extrachill_block_ads_on_ad_free_posts( $should_block_ads, $context = array() ) {
	if ( $should_block_ads ) {
		return true;
	}

	$context = is_array( $context ) ? $context : array();

	// Post IDs are blog-local; only judge posts on the current blog.
	if ( isset( $context['blog_id'] ) && get_current_blog_id() !== (int) $context['blog_id'] ) {
		return false;
	}

	$post_id = isset( $context['post_id'] ) ? (int) $context['post_id'] : 0;

	return extrachill_is_post_ad_free( $post_id );
}
add_filter( 'extrachill_should_block_ads', 'extrachill_block_ads_on_ad_free_posts', 10, 2 );
