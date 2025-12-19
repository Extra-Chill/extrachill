<?php
/**
 * Artist Taxonomy Integration for Products
 *
 * Extends the existing 'artist' taxonomy (registered by the theme on posts) to also
 * apply to WooCommerce products. Enables artist-specific product archives at
 * /artist/{slug}/ using shared taxonomy terms that link to artist profiles on Blog ID 4.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add product post type to the existing artist taxonomy.
 *
 * The theme registers 'artist' taxonomy on 'post' type. This extends it to
 * also apply to WooCommerce products, enabling shared artist terms across
 * blog posts and products.
 */
function extrachill_shop_extend_artist_taxonomy_to_products() {
	if ( ! taxonomy_exists( 'artist' ) ) {
		return;
	}

	register_taxonomy_for_object_type( 'artist', 'product' );
}
add_action( 'init', 'extrachill_shop_extend_artist_taxonomy_to_products', 20 );

/**
 * Get the artist store URL for a given artist profile ID.
 *
 * @param int $artist_profile_id Artist profile post ID from Blog ID 4.
 * @return string|false Store URL or false if artist not found.
 */
function extrachill_shop_get_artist_store_url( $artist_profile_id ) {
	if ( empty( $artist_profile_id ) ) {
		return false;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$artist_post = get_post( $artist_profile_id );
		if ( ! $artist_post || 'artist_profile' !== $artist_post->post_type ) {
			return false;
		}
		$slug = $artist_post->post_name;
	} finally {
		restore_current_blog();
	}

	$term = get_term_by( 'slug', $slug, 'artist' );
	if ( $term && ! is_wp_error( $term ) ) {
		return get_term_link( $term );
	}

	return home_url( '/artist/' . $slug . '/' );
}

/**
 * Check if viewing an artist taxonomy archive for products.
 *
 * @return bool True if on artist product archive.
 */
function extrachill_shop_is_artist_store() {
	return is_tax( 'artist' ) && is_post_type_archive( 'product' );
}

/**
 * Get the current artist term on archive pages.
 *
 * @return WP_Term|false Current artist term or false.
 */
function extrachill_shop_get_current_artist_term() {
	if ( ! is_tax( 'artist' ) ) {
		return false;
	}

	$term = get_queried_object();
	if ( $term instanceof WP_Term && 'artist' === $term->taxonomy ) {
		return $term;
	}

	return false;
}
