<?php
/**
 * Artist Order Notifications
 *
 * Sends email notifications to artists when orders containing their products
 * are placed. All roster members for each artist receive the notification.
 *
 * Mail dispatch is delegated to `ec_send_email()` (extrachill-multisite),
 * which wraps the `datamachine/send-email` ability and auto-routes the send
 * through the SMTP-configured site via `mail_site_id`. This file therefore
 * does NOT call `wp_mail()` and does NOT wrap the send in `switch_to_blog()`
 * — the wrapper handles SMTP context internally.
 *
 * NOTE on `switch_to_blog()` usage in this file: the only legitimate switch
 * is to the ARTIST blog to look up roster members + artist post title. That
 * switch is scoped tightly around the lookup, and is explicitly NOT used
 * to influence mail routing. See inline comments at each switch site.
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
 * Resolution flow (note the explicit separation between data-context
 * `switch_to_blog()` and mail-context routing):
 *
 *   1. Switch to the artist blog ONCE to fetch artist-blog data
 *      (roster member emails + artist post title), then immediately
 *      restore. This switch exists purely for READ access to artist
 *      blog data — it has nothing to do with which site sends mail.
 *   2. Build the email body using order data on the current (shop)
 *      blog — orders live here, so no switch is needed.
 *   3. Dispatch via `ec_send_email()`. The wrapper auto-supplies
 *      `mail_site_id = ec_mail_site_id()` which switches to the
 *      SMTP-configured site INSIDE the ability — callers must NOT
 *      wrap the send in their own `switch_to_blog()`.
 *
 * @param WC_Order $order       WooCommerce order object.
 * @param int      $artist_id   Artist profile ID.
 * @param array    $payout_data Artist payout data from _artist_payouts meta.
 */
function extrachill_shop_send_artist_order_notification( $order, $artist_id, $payout_data ) {
	// Step 1: artist-blog DATA lookup (NOT mail routing).
	$artist_data = extrachill_shop_get_artist_notification_data( $artist_id );
	$recipients  = $artist_data['recipients'];
	$artist_name = $artist_data['name'];

	if ( empty( $recipients ) ) {
		return;
	}

	$order_number = $order->get_order_number();

	$subject = sprintf( 'New Order #%s - %s', $order_number, $artist_name );

	// Step 2: build email body using order data (current shop-blog context is fine).
	$body_html = extrachill_shop_build_order_notification_body(
		$order,
		$artist_name,
		$payout_data
	);

	$preheader = sprintf(
		'New order #%s for %s — view details in Shop Manager.',
		$order_number,
		$artist_name
	);

	// Step 3: dispatch. `ec_send_email()` injects `mail_site_id` itself —
	// do NOT wrap this call in `switch_to_blog()`.
	foreach ( $recipients as $email ) {
		ec_send_email(
			array(
				'to'       => $email,
				'subject'  => $subject,
				'template' => 'extrachill/branded',
				'context'  => array(
					'subject_html'   => esc_html( $subject ),
					'preheader'      => $preheader,
					'recipient_name' => $artist_name,
					'body_html'      => $body_html,
					'cta_url'        => extrachill_shop_get_shop_manager_url(),
					'cta_label'      => 'View Order in Shop Manager',
				),
			)
		);
	}
}

/**
 * Look up artist-blog data needed for the notification.
 *
 * Performs a single `switch_to_blog()` to the artist blog to read:
 *   - Roster member emails via `ec_get_linked_members()`
 *   - Artist post title via `get_post()`
 *
 * Both reads live on the artist blog, so they're batched into one
 * switch/restore pair. This switch is purely for DATA access — it
 * does NOT affect mail routing (the `ec_send_email()` call happens
 * outside this scope and resolves its own mail site).
 *
 * @param int $artist_id Artist profile ID.
 * @return array{recipients: string[], name: string}
 */
function extrachill_shop_get_artist_notification_data( $artist_id ) {
	$result = array(
		'recipients' => array(),
		'name'       => 'Artist',
	);

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return $result;
	}

	// DATA-CONTEXT switch: read roster + post title from the artist blog.
	// NOT a mail-routing switch — `ec_send_email()` handles SMTP routing.
	switch_to_blog( $artist_blog_id );
	try {
		if ( function_exists( 'ec_get_linked_members' ) ) {
			$members = ec_get_linked_members( $artist_id );
			$emails  = array();
			foreach ( $members as $user ) {
				if ( ! empty( $user->user_email ) ) {
					$emails[] = $user->user_email;
				}
			}
			$result['recipients'] = array_values( array_unique( $emails ) );
		}

		$artist_post = get_post( $artist_id );
		if ( $artist_post && '' !== (string) $artist_post->post_title ) {
			$result['name'] = $artist_post->post_title;
		}
	} finally {
		restore_current_blog();
	}

	return $result;
}

/**
 * Build HTML body for the order notification email.
 *
 * Returns the inner body HTML only — the surrounding `<html><body>` chrome,
 * header, footer, greeting, and CTA button are supplied by the
 * `extrachill/branded` template. This function emits the order-specific
 * content (items table + ship-to block + payout line) that goes into
 * `context.body_html`.
 *
 * @param WC_Order $order       WooCommerce order object.
 * @param string   $artist_name Artist display name.
 * @param array    $payout_data Artist payout data.
 * @return string HTML body fragment.
 */
function extrachill_shop_build_order_notification_body( $order, $artist_name, $payout_data ) {
	$order_number  = $order->get_order_number();
	$customer_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$artist_payout = number_format( floatval( $payout_data['artist_payout'] ?? 0 ), 2 );

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

	$html = sprintf(
		'<p>You have a new order for <strong>%s</strong> (Order <strong>#%s</strong>).</p>',
		esc_html( $artist_name ),
		esc_html( $order_number )
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

	return $html;
}

/**
 * Get Shop Manager URL on artist site.
 *
 * Reads the artist subsite URL via `ec_get_site_url()` — no
 * `switch_to_blog()` needed because the helper resolves cross-site
 * URLs without changing the current blog context.
 *
 * @return string Shop Manager URL.
 */
function extrachill_shop_get_shop_manager_url() {
	if ( ! function_exists( 'ec_get_site_url' ) ) {
		return 'https://artist.extrachill.com/manage-shop/';
	}

	return ec_get_site_url( 'artist' ) . '/manage-shop/';
}
