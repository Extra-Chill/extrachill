<?php
/**
 * Artist Product Meta System
 *
 * Manages the relationship between WooCommerce products and artist profiles via
 * _artist_profile_id post meta. Provides helper functions for querying products
 * by artist and syncing taxonomy terms with meta values.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the artist profile ID associated with a product.
 *
 * @param int $product_id WooCommerce product ID.
 * @return int|false Artist profile ID or false if not set.
 */
function extrachill_shop_get_product_artist_id( $product_id ) {
	$artist_id = get_post_meta( $product_id, '_artist_profile_id', true );
	return $artist_id ? (int) $artist_id : false;
}

/**
 * Set the artist profile ID for a product.
 *
 * Also syncs the artist taxonomy term to match the artist profile slug.
 *
 * @param int $product_id WooCommerce product ID.
 * @param int $artist_profile_id Artist profile post ID from Blog ID 4.
 * @return bool True on success, false on failure.
 */
function extrachill_shop_set_product_artist( $product_id, $artist_profile_id ) {
	if ( empty( $product_id ) || empty( $artist_profile_id ) ) {
		return false;
	}

	$updated = update_post_meta( $product_id, '_artist_profile_id', (int) $artist_profile_id );

	if ( $updated ) {
		extrachill_shop_sync_product_artist_taxonomy( $product_id, $artist_profile_id );
	}

	return (bool) $updated;
}

/**
 * Remove artist association from a product.
 *
 * @param int $product_id WooCommerce product ID.
 * @return bool True on success.
 */
function extrachill_shop_remove_product_artist( $product_id ) {
	delete_post_meta( $product_id, '_artist_profile_id' );
	wp_set_object_terms( $product_id, array(), 'artist' );
	return true;
}

/**
 * Sync the artist taxonomy term with the product's artist profile.
 *
 * Ensures the product has the correct artist taxonomy term based on
 * the artist profile slug from Blog ID 4.
 *
 * @param int $product_id WooCommerce product ID.
 * @param int $artist_profile_id Artist profile post ID from Blog ID 4.
 * @return bool True on success, false on failure.
 */
function extrachill_shop_sync_product_artist_taxonomy( $product_id, $artist_profile_id ) {
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
		$artist_slug = $artist_post->post_name;
		$artist_name = $artist_post->post_title;
	} finally {
		restore_current_blog();
	}

	$term = get_term_by( 'slug', $artist_slug, 'artist' );

	if ( ! $term ) {
		$term_result = wp_insert_term( $artist_name, 'artist', array( 'slug' => $artist_slug ) );
		if ( is_wp_error( $term_result ) ) {
			return false;
		}
		$term_id = $term_result['term_id'];
	} else {
		$term_id = $term->term_id;
	}

	wp_set_object_terms( $product_id, array( $term_id ), 'artist' );

	return true;
}

/**
 * Get all products for a specific artist.
 *
 * @param int   $artist_profile_id Artist profile post ID from Blog ID 4.
 * @param array $args Optional. Additional query arguments.
 * @return array Array of product posts.
 */
function extrachill_shop_get_products_for_artist( $artist_profile_id, $args = array() ) {
	$default_args = array(
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'meta_query'     => array(
			array(
				'key'     => '_artist_profile_id',
				'value'   => (int) $artist_profile_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
		),
	);

	$query_args = wp_parse_args( $args, $default_args );
	$query      = new WP_Query( $query_args );

	return $query->posts;
}

/**
 * Get product count for a specific artist.
 *
 * @param int    $artist_profile_id Artist profile post ID from Blog ID 4.
 * @param string $status Optional. Post status to filter by. Default 'publish'.
 * @return int Product count.
 */
function extrachill_shop_get_artist_product_count( $artist_profile_id, $status = 'publish' ) {
	$query = new WP_Query(
		array(
			'post_type'      => 'product',
			'post_status'    => $status,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array(
				array(
					'key'     => '_artist_profile_id',
					'value'   => (int) $artist_profile_id,
					'compare' => '=',
					'type'    => 'NUMERIC',
				),
			),
		)
	);

	return $query->found_posts;
}

/**
 * Check if a product belongs to a specific artist.
 *
 * @param int $product_id WooCommerce product ID.
 * @param int $artist_profile_id Artist profile post ID.
 * @return bool True if product belongs to artist.
 */
function extrachill_shop_product_belongs_to_artist( $product_id, $artist_profile_id ) {
	$product_artist_id = extrachill_shop_get_product_artist_id( $product_id );
	return $product_artist_id && $product_artist_id === (int) $artist_profile_id;
}

/**
 * Get all artist profile IDs that have products in the shop.
 *
 * @return array Array of artist profile IDs.
 */
function extrachill_shop_get_artists_with_products() {
	global $wpdb;

	$artist_ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT pm.meta_value 
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
			WHERE pm.meta_key = %s
			AND p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_value != ''",
			'_artist_profile_id',
			'product',
			'publish'
		)
	);

	return array_map( 'intval', $artist_ids );
}

/**
 * Admin: Add artist profile ID field to product edit screen.
 *
 * Adds a meta box for associating products with artist profiles.
 * Admin-only feature for now.
 */
function extrachill_shop_add_artist_meta_box() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	add_meta_box(
		'extrachill_artist_product',
		__( 'Artist Association', 'extrachill-shop' ),
		'extrachill_shop_render_artist_meta_box',
		'product',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'extrachill_shop_add_artist_meta_box' );

/**
 * Render the artist meta box content.
 *
 * @param WP_Post $post Current product post.
 */
function extrachill_shop_render_artist_meta_box( $post ) {
	$artist_id = extrachill_shop_get_product_artist_id( $post->ID );

	wp_nonce_field( 'extrachill_shop_artist_meta', 'extrachill_shop_artist_nonce' );

	echo '<p>';
	echo '<label for="extrachill_artist_profile_id">' . esc_html__( 'Artist Profile ID:', 'extrachill-shop' ) . '</label><br>';
	echo '<input type="number" id="extrachill_artist_profile_id" name="extrachill_artist_profile_id" value="' . esc_attr( $artist_id ? $artist_id : '' ) . '" class="widefat" min="0">';
	echo '</p>';
	echo '<p class="description">' . esc_html__( 'Enter the artist profile post ID from artist.extrachill.com.', 'extrachill-shop' ) . '</p>';

	if ( defined( 'EC_PLATFORM_ARTIST_ID' ) ) {
		echo '<p class="description"><strong>' . esc_html__( 'Tip:', 'extrachill-shop' ) . '</strong> ';
		/* translators: %d is the platform artist ID */
		echo sprintf( esc_html__( 'Use %d for Extra Chill platform products.', 'extrachill-shop' ), EC_PLATFORM_ARTIST_ID );
		echo '</p>';
	}

	if ( $artist_id ) {
		$artist_data = extrachill_shop_get_artist_profile_by_slug_via_id( $artist_id );
		if ( $artist_data ) {
			echo '<p><strong>' . esc_html__( 'Linked to:', 'extrachill-shop' ) . '</strong> ' . esc_html( $artist_data['name'] ) . '</p>';
		}
	}
}

/**
 * Save the artist meta box data.
 *
 * @param int $post_id Product post ID.
 */
function extrachill_shop_save_artist_meta_box( $post_id ) {
	if ( ! isset( $_POST['extrachill_shop_artist_nonce'] ) ) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['extrachill_shop_artist_nonce'] ) ), 'extrachill_shop_artist_meta' ) ) {
		return;
	}

	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( isset( $_POST['extrachill_artist_profile_id'] ) && '' !== $_POST['extrachill_artist_profile_id'] ) {
		$artist_id = absint( $_POST['extrachill_artist_profile_id'] );
		if ( $artist_id > 0 ) {
			extrachill_shop_set_product_artist( $post_id, $artist_id );
		} else {
			extrachill_shop_remove_product_artist( $post_id );
		}
	}
}
add_action( 'save_post_product', 'extrachill_shop_save_artist_meta_box' );

/**
 * Get total product count for all artists owned by a user.
 *
 * Cross-site query: gets user's artist IDs (network-wide user meta),
 * then counts products on Blog ID 3 (shop) for those artists.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return int Total product count across all user's artists.
 */
function extrachill_shop_get_product_count_for_user( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id || ! function_exists( 'ec_get_artists_for_user' ) ) {
		return 0;
	}

	$user_artists = ec_get_artists_for_user( $user_id );
	if ( empty( $user_artists ) ) {
		return 0;
	}

	$shop_blog_id   = ec_get_blog_id( 'shop' );
	$current_blog   = get_current_blog_id();
	$needs_switch   = $current_blog !== $shop_blog_id;
	$total_count    = 0;

	if ( $needs_switch ) {
		switch_to_blog( $shop_blog_id );
	}

	try {
		foreach ( $user_artists as $artist_id ) {
			$total_count += extrachill_shop_get_artist_product_count( $artist_id );
		}
	} finally {
		if ( $needs_switch ) {
			restore_current_blog();
		}
	}

	return $total_count;
}

/**
 * Get artist profile data by artist profile ID.
 *
 * @param int $artist_profile_id Artist profile post ID from Blog ID 4.
 * @return array|false Artist data array or false if not found.
 */
function extrachill_shop_get_artist_profile_by_slug_via_id( $artist_profile_id ) {
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

		$profile_image_id  = get_post_thumbnail_id( $artist_post->ID );
		$profile_image_url = $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'thumbnail' ) : '';

		return array(
			'id'                => $artist_post->ID,
			'name'              => $artist_post->post_title,
			'slug'              => $artist_post->post_name,
			'bio'               => $artist_post->post_content,
			'profile_image_url' => $profile_image_url,
			'profile_url'       => home_url( '/artists/' . $artist_post->post_name . '/' ),
		);
	} finally {
		restore_current_blog();
	}
}
