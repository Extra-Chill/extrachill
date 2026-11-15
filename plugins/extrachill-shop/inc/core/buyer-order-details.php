<?php
/**
 * Buyer Order Details
 *
 * Shows buyers who sold their order (with a route to reach the seller) and,
 * once shipped, the carrier and tracking from the canonical shipment record
 * (`_extrachill_shipment`, see docs/order-seller-record.md).
 *
 * Delivery rides WooCommerce's own customer emails instead of a separate
 * send: both "mark shipped" and label purchase move the order to
 * `completed`, which fires WooCommerce's Completed order email exactly once
 * per transition. Appending to `woocommerce_email_order_meta` puts the
 * tracking in that email and the seller in the order confirmation
 * (Processing order) email. The same block renders on the thank-you page
 * and My Account order view through `woocommerce_order_details_after_order_table`.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_email_order_meta', 'extrachill_shop_email_buyer_order_details', 20, 3 );
add_action( 'woocommerce_order_details_after_order_table', 'extrachill_shop_render_buyer_order_details', 20, 1 );

/**
 * What a buyer should see about an order's seller and shipment.
 *
 * @param WC_Order $order Order.
 * @return array{seller: array{name: string, url: string}|null, tracking: array{carrier: string, number: string, url: string}|null}
 */
function extrachill_shop_get_buyer_order_details( $order ): array {
	$details = array(
		'seller'   => null,
		'tracking' => null,
	);

	$seller = extrachill_shop_get_order_seller( $order );
	if ( null !== $seller ) {
		$public = extrachill_shop_get_public_seller( $seller );
		if ( null !== $public ) {
			// The seller's public page (artist profile, venue or promoter page)
			// is the contact route; the storefront is the fallback.
			$url               = '' !== $public['url'] ? $public['url'] : extrachill_shop_get_seller_store_url( $public );
			$details['seller'] = array(
				'name' => $public['name'],
				'url'  => $url,
			);
		}
	}

	$shipment = extrachill_shop_get_order_shipment( $order );
	if ( '' !== $shipment['tracking_number'] ) {
		$details['tracking'] = array(
			'carrier' => $shipment['carrier'],
			'number'  => $shipment['tracking_number'],
			'url'     => $shipment['tracking_url'],
		);
	}

	return $details;
}

/**
 * HTML block: seller and tracking.
 *
 * @param array $details `extrachill_shop_get_buyer_order_details()`.
 * @return string Empty when there is nothing to show.
 */
function extrachill_shop_buyer_order_details_html( array $details ): string {
	$html = '';

	if ( null !== $details['seller'] ) {
		$name  = esc_html( $details['seller']['name'] );
		$name  = '' !== $details['seller']['url'] ? '<a href="' . esc_url( $details['seller']['url'] ) . '">' . $name . '</a>' : $name;
		$html .= '<p>' . sprintf(
			/* translators: %s: seller name, linked to the seller's page. */
			esc_html__( 'Sold and shipped by %s. Questions, returns or problems with this order? Contact the seller first through their page.', 'extrachill-shop' ),
			$name
		) . '</p>';
	}

	if ( null !== $details['tracking'] ) {
		$number = esc_html( $details['tracking']['number'] );
		$number = '' !== $details['tracking']['url'] ? '<a href="' . esc_url( $details['tracking']['url'] ) . '">' . $number . '</a>' : $number;
		$html  .= '<p>' . ( '' !== $details['tracking']['carrier']
			? sprintf(
				/* translators: 1: carrier name, 2: tracking number. */
				esc_html__( 'Shipped via %1$s. Tracking number: %2$s', 'extrachill-shop' ),
				esc_html( $details['tracking']['carrier'] ),
				$number
			)
			: sprintf(
				/* translators: %s: tracking number. */
				esc_html__( 'Shipped. Tracking number: %s', 'extrachill-shop' ),
				$number
			) ) . '</p>';
	}

	if ( '' === $html ) {
		return '';
	}
	return '<section><h2>' . esc_html__( 'Seller and shipping', 'extrachill-shop' ) . '</h2>' . $html . '</section>';
}

/**
 * Plain-text block for plain-text emails.
 *
 * @param array $details `extrachill_shop_get_buyer_order_details()`.
 * @return string Empty when there is nothing to show.
 */
function extrachill_shop_buyer_order_details_text( array $details ): string {
	$lines = array();
	if ( null !== $details['seller'] ) {
		$lines[] = sprintf( 'Sold and shipped by %s. Questions, returns or problems with this order? Contact the seller first.', $details['seller']['name'] );
		if ( '' !== $details['seller']['url'] ) {
			$lines[] = 'Seller page: ' . $details['seller']['url'];
		}
	}
	if ( null !== $details['tracking'] ) {
		$lines[] = '' !== $details['tracking']['carrier']
			? sprintf( 'Shipped via %s. Tracking number: %s', $details['tracking']['carrier'], $details['tracking']['number'] )
			: sprintf( 'Shipped. Tracking number: %s', $details['tracking']['number'] );
		if ( '' !== $details['tracking']['url'] ) {
			$lines[] = 'Track your package: ' . $details['tracking']['url'];
		}
	}
	return array() === $lines ? '' : "\n" . implode( "\n", $lines ) . "\n";
}

/**
 * Add seller and tracking to customer emails (confirmation, shipped, notes).
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the email goes to the store admin.
 * @param bool     $plain_text    Whether the email is plain text.
 */
function extrachill_shop_email_buyer_order_details( $order, $sent_to_admin = false, $plain_text = false ): void {
	if ( $sent_to_admin ) {
		return;
	}
	$details = extrachill_shop_get_buyer_order_details( $order );
	if ( $plain_text ) {
		echo esc_html( extrachill_shop_buyer_order_details_text( $details ) );
		return;
	}
	echo extrachill_shop_buyer_order_details_html( $details ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
}

/**
 * Add seller and tracking to the thank-you page and My Account order view.
 *
 * @param WC_Order $order Order.
 */
function extrachill_shop_render_buyer_order_details( $order ): void {
	echo extrachill_shop_buyer_order_details_html( extrachill_shop_get_buyer_order_details( $order ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
}
