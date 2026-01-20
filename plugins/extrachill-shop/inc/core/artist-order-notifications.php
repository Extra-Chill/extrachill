<?php
/**
 * Artist Order Notifications
 *
 * Sends email notifications to artists when orders containing their products
 * are placed. All roster members for each artist receive the notification.
 *
 * @package ExtraChillShop
 * @since 0.4.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_order_status_processing', 'extrachill_shop_notify_artists_of_order', 10, 1 );

/**
 * Notify artists when an order containing their products is placed.
 *
 * @param int $order_id WooCommerce order ID.
 */
function extrachill_shop_notify_artists_of_order( $order_id ) {
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	$payouts = $order->get_meta( '_artist_payouts' );
	if ( empty( $payouts ) || ! is_array( $payouts ) ) {
		return;
	}

	foreach ( $payouts as $artist_id => $payout_data ) {
		if ( 0 === (int) $artist_id ) {
			continue;
		}

		extrachill_shop_send_artist_order_notification( $order, $artist_id, $payout_data );
	}
}

/**
 * Send order notification email to an artist's roster members.
 *
 * @param WC_Order $order       WooCommerce order object.
 * @param int      $artist_id   Artist profile ID.
 * @param array    $payout_data Artist payout data from _artist_payouts meta.
 */
function extrachill_shop_send_artist_order_notification( $order, $artist_id, $payout_data ) {
	$recipients = extrachill_shop_get_artist_notification_recipients( $artist_id );
	if ( empty( $recipients ) ) {
		return;
	}

	$artist_name  = extrachill_shop_get_artist_name( $artist_id );
	$order_number = $order->get_order_number();

	$subject = sprintf( 'New Order #%s - %s', $order_number, $artist_name );

	$message = extrachill_shop_build_order_notification_email(
		$order,
		$artist_id,
		$artist_name,
		$payout_data
	);

	$headers = array(
		'Content-Type: text/html; charset=UTF-8',
		'From: Extra Chill Shop <shop@extrachill.com>',
	);

	foreach ( $recipients as $email ) {
		wp_mail( $email, $subject, $message, $headers );
	}
}

/**
 * Get email addresses for all roster members of an artist.
 *
 * @param int $artist_id Artist profile ID.
 * @return array Array of email addresses.
 */
function extrachill_shop_get_artist_notification_recipients( $artist_id ) {
	$emails = array();

	if ( ! function_exists( 'ec_get_linked_members' ) ) {
		return $emails;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return $emails;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$members = ec_get_linked_members( $artist_id );
		foreach ( $members as $user ) {
			if ( ! empty( $user->user_email ) ) {
				$emails[] = $user->user_email;
			}
		}
	} finally {
		restore_current_blog();
	}

	return array_unique( $emails );
}

/**
 * Get artist name from artist profile.
 *
 * @param int $artist_id Artist profile ID.
 * @return string Artist name or 'Artist'.
 */
function extrachill_shop_get_artist_name( $artist_id ) {
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return 'Artist';
	}

	switch_to_blog( $artist_blog_id );
	try {
		$artist_post = get_post( $artist_id );
		return $artist_post ? $artist_post->post_title : 'Artist';
	} finally {
		restore_current_blog();
	}
}

/**
 * Build HTML email content for order notification.
 *
 * @param WC_Order $order       WooCommerce order object.
 * @param int      $artist_id   Artist profile ID.
 * @param string   $artist_name Artist display name.
 * @param array    $payout_data Artist payout data.
 * @return string HTML email content.
 */
function extrachill_shop_build_order_notification_email( $order, $artist_id, $artist_name, $payout_data ) {
	$order_number     = $order->get_order_number();
	$customer_name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$artist_payout    = number_format( floatval( $payout_data['artist_payout'] ?? 0 ), 2 );
	$shop_manager_url = extrachill_shop_get_shop_manager_url();

	$shipping = $order->get_address( 'shipping' );
	$billing  = $order->get_address( 'billing' );
	$address  = ! empty( $shipping['address_1'] ) ? $shipping : $billing;

	$items_html = '';
	if ( ! empty( $payout_data['items'] ) && is_array( $payout_data['items'] ) ) {
		foreach ( $payout_data['items'] as $item_data ) {
			$product_id = $item_data['product_id'] ?? 0;
			$product    = wc_get_product( $product_id );
			$name       = $product ? esc_html( $product->get_name() ) : 'Unknown Product';
			$qty        = intval( $item_data['quantity'] ?? 1 );
			$total      = number_format( floatval( $item_data['line_total'] ?? 0 ), 2 );

			$items_html .= sprintf(
				'<tr><td style="padding: 8px; border-bottom: 1px solid #eee;">%s</td><td style="padding: 8px; border-bottom: 1px solid #eee; text-align: center;">%d</td><td style="padding: 8px; border-bottom: 1px solid #eee; text-align: right;">$%s</td></tr>',
				$name,
				$qty,
				$total
			);
		}
	}

	$address_lines = array_filter(
		array(
			$address['address_1'] ?? '',
			$address['address_2'] ?? '',
			trim( ( $address['city'] ?? '' ) . ', ' . ( $address['state'] ?? '' ) . ' ' . ( $address['postcode'] ?? '' ) ),
			$address['country'] ?? '',
		)
	);
	$address_html  = implode( '<br>', array_map( 'esc_html', $address_lines ) );

	$html = '<html><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">';

	$html .= sprintf(
		'<h2 style="color: #000; margin-bottom: 20px;">New Order #%s</h2>',
		esc_html( $order_number )
	);

	$html .= sprintf(
		'<p>Hey! You have a new order for <strong>%s</strong>.</p>',
		esc_html( $artist_name )
	);

	$html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Items</h3>';
	$html .= '<table style="width: 100%; border-collapse: collapse;">';
	$html .= '<thead><tr style="background: #f5f5f5;"><th style="padding: 8px; text-align: left;">Product</th><th style="padding: 8px; text-align: center;">Qty</th><th style="padding: 8px; text-align: right;">Total</th></tr></thead>';
	$html .= '<tbody>' . $items_html . '</tbody>';
	$html .= '</table>';

	$html .= sprintf(
		'<p style="margin-top: 15px; font-size: 16px;"><strong>Your Payout:</strong> $%s</p>',
		$artist_payout
	);

	$html .= '<h3 style="margin-top: 30px; margin-bottom: 10px;">Ship To</h3>';
	$html .= sprintf(
		'<p style="margin: 0;"><strong>%s</strong><br>%s</p>',
		esc_html( $customer_name ),
		$address_html
	);

	$html .= sprintf(
		'<p style="margin-top: 30px;"><a href="%s" style="display: inline-block; padding: 12px 24px; background: #000; color: #fff; text-decoration: none; border-radius: 4px;">View Order in Shop Manager</a></p>',
		esc_url( $shop_manager_url )
	);

	$html .= '<p style="margin-top: 40px; color: #666;">Much love,<br>Extra Chill Shop</p>';

	$html .= '</body></html>';

	return $html;
}

/**
 * Get Shop Manager URL on artist site.
 *
 * @return string Shop Manager URL.
 */
function extrachill_shop_get_shop_manager_url() {
	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return 'https://artist.extrachill.com/manage-shop/';
	}

	return ec_get_site_url( 'artist' ) . '/manage-shop/';
}
