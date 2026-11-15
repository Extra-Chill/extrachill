<?php
/**
 * Product Approval
 *
 * Enforces the "Product Approval Required" marketplace setting
 * (`extrachill_shop_require_approval`). When it is on, a seller who asks to
 * publish a product gets `pending` instead; a shop admin approves it by
 * publishing it in wp-admin. Admins and shop managers publish directly.
 *
 * A product that is already published stays published when its seller edits
 * it: re-review on edit is out of scope.
 *
 * Notifications:
 *  - A seller product entering `pending` emails the shop admin address.
 *  - A seller product moving `pending` → `publish` emails the seller's people
 *    through `extrachill_shop_notify_seller()`.
 *
 * @package ExtraChillShop
 * @since 0.22.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'transition_post_status', 'extrachill_shop_product_approval_transition', 10, 3 );

/**
 * Whether a user may publish seller products without review.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function extrachill_shop_user_can_publish_products( int $user_id ): bool {
	if ( $user_id < 1 ) {
		return false;
	}
	return user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce shop manager capability.
}

/**
 * Resolve the status a product actually gets when a user requests one.
 *
 * Only `publish` requests change: they become `pending` when approval is
 * required, the user cannot publish directly, and the product is not
 * already published.
 *
 * @param int    $product_id Product ID.
 * @param string $requested  Requested status.
 * @param int    $user_id    Acting user ID.
 * @return string
 */
function extrachill_shop_resolve_product_status( $product_id, string $requested, int $user_id ): string {
	if ( 'publish' !== $requested ) {
		return $requested;
	}
	if ( 'publish' === get_post_status( $product_id ) ) {
		return 'publish';
	}
	if ( ! extrachill_shop_requires_product_approval() || extrachill_shop_user_can_publish_products( $user_id ) ) {
		return 'publish';
	}
	return 'pending';
}

/**
 * Notify on approval-relevant status changes of seller products.
 *
 * @param string $new_status New post status.
 * @param string $old_status Old post status.
 * @param mixed  $post       Post object (WP_Post).
 */
function extrachill_shop_product_approval_transition( $new_status, $old_status, $post ): void {
	if ( $new_status === $old_status || ! is_object( $post ) || ! isset( $post->post_type, $post->ID ) || 'product' !== $post->post_type ) {
		return;
	}

	$product_id = (int) $post->ID;
	$seller     = extrachill_shop_get_product_seller( $product_id );
	if ( ! $seller ) {
		return;
	}

	$title = (string) ( $post->post_title ?? '' );
	if ( 'pending' === $new_status ) {
		extrachill_shop_notify_admin_of_pending_product( $product_id, $title, $seller );
	} elseif ( 'publish' === $new_status && 'pending' === $old_status ) {
		extrachill_shop_notify_seller_of_approved_product( $product_id, $title, $seller );
	}
}

/**
 * Email the shop admin address that a seller product awaits review.
 *
 * @param int    $product_id Product ID.
 * @param string $title      Product title.
 * @param array  $seller     Typed seller reference.
 */
function extrachill_shop_notify_admin_of_pending_product( int $product_id, string $title, array $seller ): void {
	$admin = (string) get_option( 'admin_email' );
	if ( '' === $admin || ! function_exists( 'ec_send_email' ) ) {
		return;
	}

	$seller_name = extrachill_shop_get_seller_display_name( $seller ) ?? ucfirst( $seller['type'] ) . ' #' . $seller['id'];
	$subject     = sprintf( 'Product awaiting review: %s', $title );
	$review_url  = admin_url( 'post.php?post=' . $product_id . '&action=edit' );

	ec_send_email(
		array(
			'to'       => $admin,
			'subject'  => $subject,
			'template' => 'extrachill/branded',
			'context'  => array(
				'subject_html' => esc_html( $subject ),
				'preheader'    => sprintf( '%s submitted a product for review.', $seller_name ),
				'body_html'    => '<p>' . esc_html( sprintf( '%1$s submitted "%2$s" to the shop. It stays hidden until you publish it.', $seller_name, $title ) ) . '</p>',
				'cta_url'      => $review_url,
				'cta_label'    => 'Review Product',
			),
		)
	);
}

/**
 * Tell a seller their product was approved and is live.
 *
 * @param int    $product_id Product ID.
 * @param string $title      Product title.
 * @param array  $seller     Typed seller reference.
 */
function extrachill_shop_notify_seller_of_approved_product( int $product_id, string $title, array $seller ): void {
	$subject = sprintf( 'Your product is live: %s', $title );

	extrachill_shop_notify_seller(
		$seller,
		array(
			'subject'   => $subject,
			'body_html' => '<p>' . esc_html( sprintf( '"%s" was approved and is now live in the Extra Chill shop.', $title ) ) . '</p>',
			'preheader' => sprintf( '"%s" was approved and is now for sale.', $title ),
			'cta_url'   => (string) get_permalink( $product_id ),
			'cta_label' => 'View Product',
		)
	);
}
