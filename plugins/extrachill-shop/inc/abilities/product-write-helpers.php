<?php
declare(strict_types=1);
/**
 * Product Write Helpers
 *
 * Shared business logic for the shop product write abilities
 * (create / update / delete). These functions own the WooCommerce
 * product write surface: variation setup, simple↔variable conversion,
 * the pa_size attribute-taxonomy bootstrap, publish/status validation
 * (including the Stripe Connect readiness gate), image ordering, and
 * the artist-ownership permission helpers.
 *
 * This is the canonical home for product write logic. The REST routes in
 * extrachill-api are thin shims that delegate to the abilities, which in
 * turn call these helpers. Nothing here may depend on extrachill-api.
 *
 * All functions assume they run within the shop blog context (the
 * abilities are reached via route-affinity middleware that switches to
 * the shop site before dispatch).
 *
 * @package ExtraChillShop
 * @since   0.8.0
 */

defined( 'ABSPATH' ) || exit;

// ─── Permission / ownership helpers ──────────────────────────────────────────

/**
 * Check if a user has any artist profiles they can manage.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return bool True if the user has manageable artists.
 */
function extrachill_shop_user_has_artists( $user_id = null ): bool {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return false;
	}

	$artists = ec_get_artists_for_user( $user_id );
	return ! empty( $artists );
}

/**
 * Get a user's artist profile IDs.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return array Array of artist profile IDs.
 */
function extrachill_shop_get_user_artist_ids( $user_id = null ): array {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	return ec_get_artists_for_user( $user_id );
}

/**
 * Check if a user can manage a specific artist.
 *
 * @param int      $artist_id Artist profile ID.
 * @param int|null $user_id   User ID (defaults to current user).
 * @return bool True if the user can manage the artist.
 */
function extrachill_shop_user_can_manage_artist( $artist_id, $user_id = null ): bool {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) ) {
		return user_can( $user_id, 'manage_options' );
	}

	return ec_can_manage_artist( $user_id, $artist_id );
}

// ─── Status / publish validation ─────────────────────────────────────────────

/**
 * Set product status with publish validation.
 *
 * @param int    $product_id Product ID.
 * @param string $status     Product status (draft|publish).
 * @return true|WP_Error
 */
function extrachill_shop_product_set_status( $product_id, $status ) {
	$status = sanitize_key( $status );
	if ( ! in_array( $status, array( 'draft', 'publish' ), true ) ) {
		return new WP_Error( 'invalid_status', 'Invalid status.', array( 'status' => 400 ) );
	}

	if ( 'publish' === $status ) {
		$can_publish = extrachill_shop_product_can_publish( $product_id );
		if ( is_wp_error( $can_publish ) ) {
			return $can_publish;
		}
	}

	wp_update_post(
		array(
			'ID'          => $product_id,
			'post_status' => $status,
		)
	);

	return true;
}

/**
 * Determine if a product can be published.
 *
 * Requires a featured image, an artist association, and an artist whose
 * Stripe Connect account can receive payments.
 *
 * @param int $product_id Product ID.
 * @return true|WP_Error
 */
function extrachill_shop_product_can_publish( $product_id ) {
	$product_post = get_post( $product_id );
	if ( ! $product_post || 'product' !== $product_post->post_type ) {
		return new WP_Error( 'product_not_found', 'Product not found.', array( 'status' => 404 ) );
	}

	$featured_id = (int) get_post_thumbnail_id( $product_id );
	if ( ! $featured_id ) {
		return new WP_Error( 'product_image_required', 'Products must have an image to publish.', array( 'status' => 400 ) );
	}

	$artist_id = absint( get_post_meta( $product_id, '_artist_profile_id', true ) );
	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist', 'Product is missing an artist association.', array( 'status' => 400 ) );
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error( 'dependency_missing', 'Multisite plugin is not active.', array( 'status' => 500 ) );
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'configuration_error', 'Artist blog is not configured.', array( 'status' => 500 ) );
	}

	$can_receive_payments = false;
	$stripe_account_id    = '';
	$stripe_status        = '';
	try {
		switch_to_blog( $artist_blog_id );
		$stripe_account_id    = (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true );
		$stripe_status        = (string) get_post_meta( $artist_id, '_stripe_connect_status', true );
		$can_receive_payments = ( 'active' === $stripe_status );
	} finally {
		restore_current_blog();
	}

	if ( ! $can_receive_payments && $stripe_account_id && function_exists( 'extrachill_shop_get_account_status' ) ) {
		$status = extrachill_shop_get_account_status( $stripe_account_id );
		if ( ! empty( $status['success'] ) && ! empty( $status['can_receive_payments'] ) ) {
			$can_receive_payments = true;

			$safe_status = isset( $status['status'] ) ? (string) $status['status'] : '';
			if ( $safe_status ) {
				try {
					switch_to_blog( $artist_blog_id );
					update_post_meta( $artist_id, '_stripe_connect_status', $safe_status );
					update_post_meta( $artist_id, '_stripe_connect_onboarding_complete', ! empty( $status['details_submitted'] ) ? '1' : '0' );
				} finally {
					restore_current_blog();
				}
			}
		}
	}

	if ( ! $can_receive_payments ) {
		return new WP_Error( 'stripe_not_ready', 'Connect Stripe before products can go live.', array( 'status' => 400 ) );
	}

	return true;
}

// ─── Image ordering ──────────────────────────────────────────────────────────

/**
 * Persist image order for a WooCommerce product.
 *
 * The first attachment becomes the featured image; the rest become the
 * gallery (max 5 total). Every attachment must already be a child of the
 * product.
 *
 * @param int   $product_id Product ID.
 * @param array $image_ids  Ordered attachment IDs (max 5).
 * @return true|WP_Error
 */
function extrachill_shop_product_set_image_order( $product_id, $image_ids ) {
	if ( ! is_array( $image_ids ) ) {
		return new WP_Error( 'invalid_image_ids', 'Invalid image_ids.', array( 'status' => 400 ) );
	}

	$image_ids = array_values( array_filter( array_map( 'absint', $image_ids ) ) );
	$image_ids = array_slice( $image_ids, 0, 5 );

	if ( empty( $image_ids ) ) {
		return new WP_Error( 'image_required', 'Products must have at least one image.', array( 'status' => 400 ) );
	}

	foreach ( $image_ids as $attachment_id ) {
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type || (int) $attachment->post_parent !== (int) $product_id ) {
			return new WP_Error( 'invalid_image_ids', 'Invalid image_ids.', array( 'status' => 400 ) );
		}
	}

	$featured_id = array_shift( $image_ids );
	set_post_thumbnail( $product_id, $featured_id );

	if ( empty( $image_ids ) ) {
		delete_post_meta( $product_id, '_product_image_gallery' );
		return true;
	}

	update_post_meta( $product_id, '_product_image_gallery', implode( ',', $image_ids ) );
	return true;
}

// ─── Variation / size attribute management ───────────────────────────────────

/**
 * Set up product variations for sizes.
 *
 * Converts a simple product to variable if needed, creates/updates the
 * pa_size attribute and a variation per size, then prunes removed sizes.
 *
 * @param int        $product_id Product ID.
 * @param array      $sizes      Array of size data: [ ['name' => 'S', 'stock' => 10], ... ].
 * @param float|null $price      Regular price to apply to variations.
 * @param float|null $sale_price Sale price to apply to variations (optional).
 * @return true|WP_Error
 */
function extrachill_shop_setup_product_variations( $product_id, $sizes, $price = null, $sale_price = null ) {
	if ( empty( $sizes ) ) {
		extrachill_shop_convert_to_simple_product( $product_id );
		return true;
	}

	extrachill_shop_ensure_size_attribute();

	$type_result = wp_set_object_terms( $product_id, 'variable', 'product_type' );
	if ( is_wp_error( $type_result ) ) {
		return $type_result;
	}

	$size_slugs = array();
	foreach ( $sizes as $size_data ) {
		$size_name = $size_data['name'];
		$term      = get_term_by( 'name', $size_name, 'pa_size' );
		if ( ! $term ) {
			$result = wp_insert_term( $size_name, 'pa_size' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$term = get_term( $result['term_id'], 'pa_size' );
		}
		if ( $term ) {
			$size_slugs[] = $term->slug;
		}
	}

	$size_terms_result = wp_set_object_terms( $product_id, $size_slugs, 'pa_size' );
	if ( is_wp_error( $size_terms_result ) ) {
		return $size_terms_result;
	}

	$product_attributes = array(
		'pa_size' => array(
			'name'         => 'pa_size',
			'value'        => '',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 1,
			'is_taxonomy'  => 1,
		),
	);
	update_post_meta( $product_id, '_product_attributes', $product_attributes );

	$existing_variations = get_posts(
		array(
			'post_type'   => 'product_variation',
			'post_parent' => $product_id,
			'post_status' => array( 'publish', 'private', 'draft' ),
			'numberposts' => -1,
		)
	);

	$existing_by_size = array();
	foreach ( $existing_variations as $var ) {
		$size_attr                      = get_post_meta( $var->ID, 'attribute_pa_size', true );
		$existing_by_size[ $size_attr ] = $var->ID;
	}

	$updated_size_slugs = array();
	$menu_order         = 0;

	foreach ( $sizes as $size_data ) {
		$size_name  = $size_data['name'];
		$size_stock = absint( $size_data['stock'] );
		$term       = get_term_by( 'name', $size_name, 'pa_size' );
		if ( ! $term ) {
			continue;
		}

		$size_slug            = $term->slug;
		$updated_size_slugs[] = $size_slug;

		if ( isset( $existing_by_size[ $size_slug ] ) ) {
			$variation_id = $existing_by_size[ $size_slug ];
			$updated      = wp_update_post(
				array(
					'ID'          => $variation_id,
					'post_status' => 'publish',
					'menu_order'  => $menu_order,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		} else {
			$variation_id = wp_insert_post(
				array(
					'post_type'   => 'product_variation',
					'post_parent' => $product_id,
					'post_status' => 'publish',
					'post_title'  => $size_name,
					'menu_order'  => $menu_order,
				),
				true
			);
			if ( is_wp_error( $variation_id ) ) {
				return $variation_id;
			}
			update_post_meta( $variation_id, 'attribute_pa_size', $size_slug );
		}

		if ( $price !== null ) {
			update_post_meta( $variation_id, '_regular_price', (string) $price );
			$effective_price = $price;

			if ( is_numeric( $sale_price ) && (float) $sale_price > 0 && (float) $sale_price < (float) $price ) {
				update_post_meta( $variation_id, '_sale_price', (string) $sale_price );
				$effective_price = $sale_price;
			} else {
				delete_post_meta( $variation_id, '_sale_price' );
			}

			update_post_meta( $variation_id, '_price', (string) $effective_price );
		}

		update_post_meta( $variation_id, '_manage_stock', 'yes' );
		update_post_meta( $variation_id, '_stock', (string) $size_stock );
		update_post_meta( $variation_id, '_stock_status', $size_stock > 0 ? 'instock' : 'outofstock' );

		++$menu_order;
	}

	foreach ( $existing_by_size as $size_slug => $variation_id ) {
		if ( ! in_array( $size_slug, $updated_size_slugs, true ) ) {
			wp_delete_post( $variation_id, true );
		}
	}

	delete_transient( 'wc_product_children_' . $product_id );
	delete_transient( 'wc_var_prices_' . $product_id );

	return true;
}

/**
 * Convert a variable product back to a simple product.
 *
 * Deletes all variations and resets the product type and attributes.
 *
 * @param int $product_id Product ID.
 * @return void
 */
function extrachill_shop_convert_to_simple_product( $product_id ): void {
	$variations = get_posts(
		array(
			'post_type'   => 'product_variation',
			'post_parent' => $product_id,
			'post_status' => array( 'publish', 'private', 'draft' ),
			'numberposts' => -1,
			'fields'      => 'ids',
		)
	);

	foreach ( $variations as $variation_id ) {
		wp_delete_post( $variation_id, true );
	}

	wp_set_object_terms( $product_id, 'simple', 'product_type' );
	delete_post_meta( $product_id, '_product_attributes' );

	if ( taxonomy_exists( 'pa_size' ) ) {
		$term_ids = wp_get_object_terms( $product_id, 'pa_size', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $term_ids ) && ! empty( $term_ids ) ) {
			wp_remove_object_terms( $product_id, array_map( 'intval', $term_ids ), 'pa_size' );
		}
	}

	delete_transient( 'wc_product_children_' . $product_id );
}

/**
 * Ensure the pa_size attribute taxonomy exists.
 *
 * Registers the WooCommerce global "size" attribute (inserting the
 * woocommerce_attribute_taxonomies row if missing) and the pa_size
 * taxonomy at runtime.
 *
 * @return void
 */
function extrachill_shop_ensure_size_attribute(): void {
	if ( taxonomy_exists( 'pa_size' ) ) {
		return;
	}

	global $wpdb;

	$attribute_exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
			'size'
		)
	);

	if ( ! $attribute_exists ) {
		$attribute_data = array(
			'attribute_name'    => 'size',
			'attribute_label'   => 'Size',
			'attribute_type'    => 'select',
			'attribute_orderby' => 'menu_order',
			'attribute_public'  => 0,
		);

		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_attribute_taxonomies',
			$attribute_data,
			array( '%s', '%s', '%s', '%s', '%d' )
		);
	}

	delete_transient( 'wc_attribute_taxonomies' );

	register_taxonomy(
		'pa_size',
		'product',
		array(
			'label'        => 'Size',
			'public'       => true,
			'hierarchical' => false,
			'show_ui'      => true,
			'query_var'    => true,
			'rewrite'      => array( 'slug' => 'size' ),
		)
	);
}
