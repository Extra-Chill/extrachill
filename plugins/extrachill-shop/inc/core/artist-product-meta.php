<?php
/**
 * Product Seller Meta
 *
 * Every seller-owned product stores exactly one typed seller in two scalar
 * meta keys: `_extrachill_shop_seller_type` (artist|venue|promoter) and
 * `_extrachill_shop_seller_id`. Products without them are platform products.
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the seller that owns a product.
 *
 * @param int $product_id WooCommerce product ID.
 * @return array|false Seller reference { type, id } or false for platform products.
 */
function extrachill_shop_get_product_seller( $product_id ) {
	$type = (string) get_post_meta( $product_id, '_extrachill_shop_seller_type', true );
	$id   = (int) get_post_meta( $product_id, '_extrachill_shop_seller_id', true );
	if ( ! in_array( $type, array( 'artist', 'venue', 'promoter' ), true ) || $id < 1 ) {
		return false;
	}
	return array(
		'type' => $type,
		'id'   => $id,
	);
}

/**
 * Assign a product to a seller.
 *
 * @param int   $product_id WooCommerce product ID.
 * @param array $seller     Seller reference.
 * @return bool
 */
function extrachill_shop_set_product_seller( $product_id, $seller ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( empty( $product_id ) || is_wp_error( $seller ) ) {
		return false;
	}

	update_post_meta( $product_id, '_extrachill_shop_seller_type', $seller['type'] );
	update_post_meta( $product_id, '_extrachill_shop_seller_id', $seller['id'] );
	return extrachill_shop_get_product_seller( $product_id ) === $seller;
}

/**
 * Make a product a platform product (no seller).
 *
 * @param int $product_id WooCommerce product ID.
 */
function extrachill_shop_clear_product_seller( $product_id ) {
	delete_post_meta( $product_id, '_extrachill_shop_seller_type' );
	delete_post_meta( $product_id, '_extrachill_shop_seller_id' );
}

/**
 * Every seller with published products.
 *
 * @return array<int, array{type: string, id: int}>
 */
function extrachill_shop_get_sellers_with_products(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct seller pairs for the public filter.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT t.meta_value AS seller_type, i.meta_value AS seller_id
			FROM {$wpdb->postmeta} t
			INNER JOIN {$wpdb->postmeta} i ON i.post_id = t.post_id AND i.meta_key = %s
			INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id
			WHERE t.meta_key = %s
			AND t.meta_value IN ( 'artist', 'venue', 'promoter' )
			AND p.post_type = %s
			AND p.post_status = %s",
			'_extrachill_shop_seller_id',
			'_extrachill_shop_seller_type',
			'product',
			'publish'
		),
		ARRAY_A
	);

	$sellers = array();
	foreach ( (array) $rows as $row ) {
		$id = (int) ( $row['seller_id'] ?? 0 );
		if ( $id > 0 ) {
			$sellers[] = array(
				'type' => (string) $row['seller_type'],
				'id'   => $id,
			);
		}
	}
	return $sellers;
}

/**
 * Add the seller field to the product edit screen.
 *
 * Only people who can edit products in wp-admin reach this screen; sellers
 * manage their products in the shop manager.
 */
function extrachill_shop_add_seller_meta_box() {
	add_meta_box(
		'extrachill_product_seller',
		__( 'Seller', 'extrachill-shop' ),
		'extrachill_shop_render_seller_meta_box',
		'product',
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes', 'extrachill_shop_add_seller_meta_box' );

/**
 * Render the seller meta box.
 *
 * @param WP_Post $post Current product post.
 */
function extrachill_shop_render_seller_meta_box( $post ) {
	$seller = extrachill_shop_get_product_seller( $post->ID );
	$type   = $seller ? $seller['type'] : '';

	wp_nonce_field( 'extrachill_shop_seller_meta', 'extrachill_shop_seller_nonce' );

	echo '<p><label for="extrachill_shop_seller_type">' . esc_html__( 'Seller type', 'extrachill-shop' ) . '</label><br>';
	echo '<select id="extrachill_shop_seller_type" name="extrachill_shop_seller_type" class="widefat">';
	echo '<option value="">' . esc_html__( 'None (platform product)', 'extrachill-shop' ) . '</option>';
	foreach ( array( 'artist', 'venue', 'promoter' ) as $option ) {
		echo '<option value="' . esc_attr( $option ) . '"' . selected( $type, $option, false ) . '>' . esc_html( ucfirst( $option ) ) . '</option>';
	}
	echo '</select></p>';
	echo '<p><label for="extrachill_shop_seller_id">' . esc_html__( 'Seller ID', 'extrachill-shop' ) . '</label><br>';
	echo '<input type="number" id="extrachill_shop_seller_id" name="extrachill_shop_seller_id" value="' . esc_attr( $seller ? (string) $seller['id'] : '' ) . '" class="widefat" min="0"></p>';

	if ( $seller ) {
		$public = extrachill_shop_get_public_seller( $seller );
		if ( $public ) {
			echo '<p><strong>' . esc_html__( 'Linked to:', 'extrachill-shop' ) . '</strong> ' . esc_html( $public['name'] ) . '</p>';
		}
	}
}

/**
 * Save the seller meta box.
 *
 * @param int $post_id Product post ID.
 */
function extrachill_shop_save_seller_meta_box( $post_id ) {
	if ( ! isset( $_POST['extrachill_shop_seller_nonce'] )
		|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['extrachill_shop_seller_nonce'] ) ), 'extrachill_shop_seller_meta' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	$type = isset( $_POST['extrachill_shop_seller_type'] ) ? sanitize_key( wp_unslash( $_POST['extrachill_shop_seller_type'] ) ) : '';
	$id   = isset( $_POST['extrachill_shop_seller_id'] ) ? absint( $_POST['extrachill_shop_seller_id'] ) : 0;
	if ( '' === $type || $id < 1 ) {
		extrachill_shop_clear_product_seller( $post_id );
		return;
	}

	$seller = array(
		'type' => $type,
		'id'   => $id,
	);
	if ( ! is_wp_error( extrachill_shop_get_canonical_seller( $seller ) ) ) {
		extrachill_shop_set_product_seller( $post_id, $seller );
	}
}
add_action( 'save_post_product', 'extrachill_shop_save_seller_meta_box' );
