<?php
/**
 * Priority Boost WooCommerce Integration
 *
 * Adds event URL field and checkout validation for the priority boost product.
 * Grants priority status on order completion by setting event meta on the events site.
 *
 * @package ExtraChillShop
 * @since 0.6.0
 */

defined( 'ABSPATH' ) || exit;

add_action( 'woocommerce_before_add_to_cart_button', 'extrachill_shop_add_event_url_field' );
add_filter( 'woocommerce_add_to_cart_validation', 'extrachill_shop_validate_priority_boost_add_to_cart', 10, 3 );
add_filter( 'woocommerce_add_cart_item_data', 'extrachill_shop_save_event_to_cart', 10, 3 );

add_action( 'woocommerce_check_cart_items', 'extrachill_shop_validate_event_cart' );
add_filter( 'woocommerce_get_item_data', 'extrachill_shop_display_event_in_cart', 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', 'extrachill_shop_add_event_to_order_item', 10, 4 );
add_action( 'woocommerce_payment_complete', 'extrachill_shop_auto_complete_priority_boost_order', 20 );
add_action( 'woocommerce_order_status_completed', 'extrachill_shop_handle_priority_boost_purchase', 10 );

function extrachill_shop_is_priority_boost_product_id( $product_id ) {
	$priority_boost_product_id = function_exists( 'extrachill_shop_get_priority_boost_product_id' )
		? absint( extrachill_shop_get_priority_boost_product_id() )
		: 0;

	return $priority_boost_product_id && absint( $product_id ) === $priority_boost_product_id;
}

function extrachill_shop_get_posted_event_url() {
	if ( empty( $_POST['priority_boost_event_url'] ) ) {
		return '';
	}

	return (string) esc_url_raw( wp_unslash( $_POST['priority_boost_event_url'] ) );
}

/**
 * Parse and validate an event URL from events.extrachill.com.
 *
 * @param string $url The event URL to parse.
 * @return array|WP_Error Event data array on success, WP_Error on failure.
 */
function extrachill_shop_parse_event_url( $url ) {
	$parsed = wp_parse_url( $url );

	if ( empty( $parsed['host'] ) || 'events.extrachill.com' !== $parsed['host'] ) {
		return new WP_Error( 'invalid_domain', 'URL must be from events.extrachill.com' );
	}

	if ( empty( $parsed['path'] ) || ! preg_match( '#^/event/([^/]+)/?$#', $parsed['path'], $matches ) ) {
		return new WP_Error( 'invalid_path', 'URL must be an event page' );
	}

	$slug = sanitize_title( $matches[1] );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error( 'missing_dependency', 'Required function ec_get_blog_id() not available.' );
	}

	$events_blog_id = ec_get_blog_id( 'events' );
	if ( ! $events_blog_id ) {
		return new WP_Error( 'invalid_blog', 'Events blog not configured.' );
	}

	switch_to_blog( $events_blog_id );
	try {
		$event = get_page_by_path( $slug, OBJECT, 'data_machine_events' );
		if ( ! $event ) {
			return new WP_Error( 'event_not_found', 'Event not found' );
		}

		$event_date = get_post_meta( $event->ID, '_event_date', true );
		if ( $event_date && $event_date < gmdate( 'Y-m-d' ) ) {
			return new WP_Error( 'event_past', 'Cannot boost past events' );
		}

		return array(
			'id'    => $event->ID,
			'title' => $event->post_title,
			'date'  => $event_date,
		);
	} finally {
		restore_current_blog();
	}
}

function extrachill_shop_add_event_url_field() {
	global $product;
	if ( ! $product ) {
		return;
	}

	if ( ! extrachill_shop_is_priority_boost_product_id( $product->get_id() ) ) {
		return;
	}

	?>
	<div class="priority-boost-event-field">
		<label for="priority_boost_event_url">
			<?php esc_html_e( 'Event URL', 'extrachill-shop' ); ?> <abbr>*</abbr>
		</label>
		<input type="url"
			name="priority_boost_event_url"
			id="priority_boost_event_url"
			placeholder="https://events.extrachill.com/event/..."
			required>
		<p class="description">
			<?php esc_html_e( 'Paste the URL of the event you want to boost.', 'extrachill-shop' ); ?>
		</p>
	</div>
	<?php
}

function extrachill_shop_validate_priority_boost_add_to_cart( $passed, $product_id, $quantity ) {
	if ( ! extrachill_shop_is_priority_boost_product_id( $product_id ) ) {
		return $passed;
	}

	$event_url = extrachill_shop_get_posted_event_url();
	if ( empty( $event_url ) ) {
		wc_add_notice( __( 'Please enter an event URL for the Priority Boost.', 'extrachill-shop' ), 'error' );
		return false;
	}

	$event_data = extrachill_shop_parse_event_url( $event_url );
	if ( is_wp_error( $event_data ) ) {
		wc_add_notice( $event_data->get_error_message(), 'error' );
		return false;
	}

	return $passed;
}

function extrachill_shop_save_event_to_cart( $cart_item_data, $product_id, $variation_id ) {
	if ( ! extrachill_shop_is_priority_boost_product_id( $product_id ) ) {
		return $cart_item_data;
	}

	$event_url = extrachill_shop_get_posted_event_url();
	if ( empty( $event_url ) ) {
		return $cart_item_data;
	}

	$event_data = extrachill_shop_parse_event_url( $event_url );
	if ( is_wp_error( $event_data ) ) {
		return $cart_item_data;
	}

	$cart_item_data['priority_boost_event_id']    = $event_data['id'];
	$cart_item_data['priority_boost_event_title'] = $event_data['title'];
	$cart_item_data['priority_boost_event_url']   = $event_url;

	return $cart_item_data;
}

function extrachill_shop_display_event_in_cart( $item_data, $cart_item ) {
	if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_priority_boost_product_id( $cart_item['product_id'] ) ) {
		return $item_data;
	}

	if ( empty( $cart_item['priority_boost_event_title'] ) ) {
		return $item_data;
	}

	$item_data[] = array(
		'key'   => __( 'Event', 'extrachill-shop' ),
		'value' => esc_html( $cart_item['priority_boost_event_title'] ),
	);

	return $item_data;
}

function extrachill_shop_validate_event_cart() {
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_priority_boost_product_id( $cart_item['product_id'] ) ) {
			continue;
		}

		if ( empty( $cart_item['priority_boost_event_id'] ) ) {
			wc_add_notice( __( 'Please provide an event URL for the Priority Boost.', 'extrachill-shop' ), 'error' );
			continue;
		}

		$event_id = absint( $cart_item['priority_boost_event_id'] );
		if ( ! $event_id ) {
			wc_add_notice( __( 'Invalid event for Priority Boost.', 'extrachill-shop' ), 'error' );
			continue;
		}

		if ( ! function_exists( 'ec_get_blog_id' ) ) {
			continue;
		}

		$events_blog_id = ec_get_blog_id( 'events' );
		if ( ! $events_blog_id ) {
			continue;
		}

		switch_to_blog( $events_blog_id );
		try {
			$event = get_post( $event_id );
			if ( ! $event || 'data_machine_events' !== $event->post_type ) {
				wc_add_notice( __( 'The selected event no longer exists.', 'extrachill-shop' ), 'error' );
			}

			$event_date = get_post_meta( $event_id, '_event_date', true );
			if ( $event_date && $event_date < gmdate( 'Y-m-d' ) ) {
				wc_add_notice( __( 'Cannot boost past events.', 'extrachill-shop' ), 'error' );
			}
		} finally {
			restore_current_blog();
		}
	}
}

function extrachill_shop_add_event_to_order_item( $item, $cart_item_key, $values, $order ) {
	if ( empty( $values['priority_boost_event_id'] ) ) {
		return;
	}

	$item->add_meta_data( 'priority_boost_event_id', absint( $values['priority_boost_event_id'] ), true );
	$item->add_meta_data( 'priority_boost_event_title', sanitize_text_field( $values['priority_boost_event_title'] ), true );
}

function extrachill_shop_auto_complete_priority_boost_order( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order || 'processing' !== $order->get_status() ) {
		return;
	}

	$items            = $order->get_items();
	$all_virtual_only = true;

	foreach ( $items as $item ) {
		$product = $item->get_product();
		if ( $product && ! $product->is_virtual() ) {
			$all_virtual_only = false;
			break;
		}
	}

	if ( ! $all_virtual_only ) {
		return;
	}

	$has_priority_boost = false;
	foreach ( $items as $item ) {
		if ( extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) ) {
			$has_priority_boost = true;
			break;
		}
	}

	if ( ! $has_priority_boost ) {
		return;
	}

	$order->update_status( 'completed', __( 'Auto-completed: Virtual products only.', 'extrachill-shop' ) );
}

function extrachill_shop_handle_priority_boost_purchase( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$events_blog_id = ec_get_blog_id( 'events' );
	if ( ! $events_blog_id ) {
		return;
	}

	foreach ( $order->get_items() as $item ) {
		if ( ! extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) ) {
			continue;
		}

		$item_id = $item->get_id();

		$idempotency_key = '_extrachill_priority_boost_processed_' . $item_id;
		if ( $order->get_meta( $idempotency_key, true ) ) {
			continue;
		}

		$event_id    = absint( $item->get_meta( 'priority_boost_event_id', true ) );
		$event_title = (string) $item->get_meta( 'priority_boost_event_title', true );

		if ( ! $event_id ) {
			continue;
		}

		switch_to_blog( $events_blog_id );
		try {
			$event = get_post( $event_id );
			if ( ! $event || 'data_machine_events' !== $event->post_type ) {
				restore_current_blog();
				continue;
			}

			update_post_meta( $event_id, '_extrachill_priority_event', true );
			update_post_meta( $event_id, '_extrachill_priority_boost_order_id', $order_id );

			wp_cache_delete( 'extrachill_priority_event_ids', 'extrachill-events' );
		} finally {
			restore_current_blog();
		}

		$order->update_meta_data( $idempotency_key, 1 );
		$order->add_order_note(
			sprintf(
				/* translators: %s: event title */
				__( 'Priority boost granted for event: %s', 'extrachill-shop' ),
				$event_title
			)
		);
	}

	$order->save();
}
