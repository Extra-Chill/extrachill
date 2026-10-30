<?php
/**
 * Priority Boost WooCommerce Integration
 *
 * Adds event URL field and checkout validation for the priority boost product.
 * Grants priority status through the Events-owned idempotent ability.
 *
 * @package ExtraChillShop
 * @since 0.6.0
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/core/priority-boost-service-authority.php';

add_action( 'woocommerce_before_add_to_cart_button', 'extrachill_shop_add_event_url_field' );
add_filter( 'woocommerce_add_to_cart_validation', 'extrachill_shop_validate_priority_boost_add_to_cart', 10, 3 );
add_filter( 'woocommerce_add_cart_item_data', 'extrachill_shop_save_event_to_cart', 10, 3 );

add_action( 'woocommerce_check_cart_items', 'extrachill_shop_validate_event_cart' );
add_filter( 'woocommerce_get_item_data', 'extrachill_shop_display_event_in_cart', 10, 2 );

add_action( 'woocommerce_checkout_create_order_line_item', 'extrachill_shop_add_event_to_order_item', 10, 4 );
add_action( 'woocommerce_payment_complete', 'extrachill_shop_auto_complete_priority_boost_order', 20 );
add_action( 'woocommerce_order_status_completed', 'extrachill_shop_handle_priority_boost_purchase', 10 );

/**
 * Check whether a product is the configured priority boost product.
 *
 * @param int $product_id Product ID.
 * @return bool
 */
function extrachill_shop_is_priority_boost_product_id( $product_id ) {
	$priority_boost_product_id = function_exists( 'extrachill_shop_get_priority_boost_product_id' )
		? absint( extrachill_shop_get_priority_boost_product_id() )
		: 0;

	return $priority_boost_product_id && absint( $product_id ) === $priority_boost_product_id;
}

/** Read the submitted event URL from WooCommerce's add-to-cart form. */
function extrachill_shop_get_posted_event_url() {
	// WooCommerce owns this public guest-capable add-to-cart request lifecycle.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing
	if ( empty( $_POST['priority_boost_event_url'] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing
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

	$events_host = function_exists( 'ec_get_site_url' )
		? wp_parse_url( ec_get_site_url( 'events' ), PHP_URL_HOST )
		: '';

	if ( empty( $parsed['host'] ) || empty( $events_host ) || $events_host !== $parsed['host'] ) {
		return new WP_Error( 'invalid_domain', 'URL must be from the events site.' );
	}

	if ( empty( $parsed['path'] ) || ! preg_match( '#^/event/([^/]+)/?$#', $parsed['path'], $matches ) ) {
		return new WP_Error( 'invalid_path', 'URL must be an event page' );
	}

	$slug = sanitize_title( $matches[1] );
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
		return new WP_Error( 'events_runtime_unavailable', 'Events validation is unavailable.' );
	}

	$events = ec_cross_site_rest_request_http(
		'events',
		'GET',
		'/wp/v2/data_machine_events',
		array(
			'query' => array(
				'slug'    => $slug,
				'status'  => 'publish',
				'_fields' => 'id,slug,title',
			),
		)
	);
	if ( is_wp_error( $events ) ) {
		return $events;
	}
	if ( empty( $events[0]['id'] ) || ( $events[0]['slug'] ?? '' ) !== $slug ) {
		return new WP_Error( 'event_not_found', 'Event not found' );
	}

	return array(
		'id'        => absint( $events[0]['id'] ),
		'reference' => $slug,
		'title'     => wp_strip_all_tags( (string) ( $events[0]['title']['rendered'] ?? $slug ) ),
	);
}

/** Render the event URL field for the priority boost product. */
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

/**
 * Validate a priority boost add-to-cart request.
 *
 * @param bool $passed Existing validation state.
 * @param int  $product_id Product ID.
 * @param int  $quantity Requested quantity.
 * @return bool
 */
function extrachill_shop_validate_priority_boost_add_to_cart( $passed, $product_id, $quantity ) {
	unset( $quantity );
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

/**
 * Attach canonical event data to a priority boost cart item.
 *
 * @param array $cart_item_data Cart item data.
 * @param int   $product_id Product ID.
 * @param int   $variation_id Variation ID.
 * @return array
 */
function extrachill_shop_save_event_to_cart( $cart_item_data, $product_id, $variation_id ) {
	unset( $variation_id );
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

	$cart_item_data['priority_boost_event_id']        = $event_data['id'];
	$cart_item_data['priority_boost_event_reference'] = $event_data['reference'];
	$cart_item_data['priority_boost_event_title']     = $event_data['title'];
	$cart_item_data['priority_boost_event_url']       = $event_url;

	return $cart_item_data;
}

/**
 * Add the selected event title to WooCommerce cart display data.
 *
 * @param array $item_data Existing display data.
 * @param array $cart_item Cart item data.
 * @return array
 */
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

/** Validate that every priority boost cart item has a canonical event. */
function extrachill_shop_validate_event_cart() {
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['product_id'] ) || ! extrachill_shop_is_priority_boost_product_id( $cart_item['product_id'] ) ) {
			continue;
		}

		if ( empty( $cart_item['priority_boost_event_id'] ) ) {
			wc_add_notice( __( 'Please provide an event URL for the Priority Boost.', 'extrachill-shop' ), 'error' );
			continue;
		}

		$event_reference = sanitize_title( (string) ( $cart_item['priority_boost_event_reference'] ?? '' ) );
		if ( ! $event_reference ) {
			wc_add_notice( __( 'Invalid event for Priority Boost.', 'extrachill-shop' ), 'error' );
		}
	}
}

/**
 * Persist canonical event data on an order item.
 *
 * @param object $item Order item.
 * @param string $cart_item_key Cart item key.
 * @param array  $values Cart item values.
 * @param object $order Order object.
 */
function extrachill_shop_add_event_to_order_item( $item, $cart_item_key, $values, $order ) {
	unset( $cart_item_key, $order );
	if ( empty( $values['priority_boost_event_id'] ) ) {
		return;
	}

	$item->add_meta_data( 'priority_boost_event_id', absint( $values['priority_boost_event_id'] ), true );
	$item->add_meta_data( 'priority_boost_event_reference', sanitize_title( $values['priority_boost_event_reference'] ), true );
	$item->add_meta_data( 'priority_boost_event_title', sanitize_text_field( $values['priority_boost_event_title'] ), true );
}

/**
 * Auto-complete a paid order containing only virtual priority products.
 *
 * @param int $order_id Order ID.
 */
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

/**
 * Fulfill every unreceipted priority boost item on a completed order.
 *
 * @param int $order_id Order ID.
 */
function extrachill_shop_handle_priority_boost_purchase( $order_id ) {
	if ( ! $order_id ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	foreach ( $order->get_items() as $item ) {
		if ( ! extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) ) {
			continue;
		}

		$item_id = $item->get_id();

		$receipt_key = '_extrachill_priority_boost_receipt_' . $item_id;
		if ( $order->get_meta( $receipt_key, true ) ) {
			continue;
		}

		$event_title = (string) $item->get_meta( 'priority_boost_event_title', true );
		$result      = extrachill_shop_grant_event_priority_boost( $order, $item );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: stable owner error code */
					__( 'Priority boost pending: Events owner returned %s.', 'extrachill-shop' ),
					$result->get_error_code()
				)
			);
			continue;
		}

		$order->update_meta_data( $receipt_key, $result['receipt'] );
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

/**
 * Grant one purchased boost through the Events owner contract.
 *
 * @param WC_Order $order Order object.
 * @param object   $item Order item.
 * @return array|WP_Error
 */
function extrachill_shop_grant_event_priority_boost( $order, $item ) {
	if ( ! is_callable( array( $order, 'is_paid' ) ) || ! $order->is_paid() ) {
		return new WP_Error( 'priority_boost_order_not_paid', __( 'The order payment has not been verified.', 'extrachill-shop' ) );
	}

	$event_reference = sanitize_title( (string) $item->get_meta( 'priority_boost_event_reference', true ) );
	if ( ! $event_reference ) {
		$event_reference = (string) absint( $item->get_meta( 'priority_boost_event_id', true ) );
	}
	if ( ! $event_reference ) {
		return new WP_Error( 'priority_boost_event_required', __( 'The order item has no canonical event reference.', 'extrachill-shop' ) );
	}

	$order_id = absint( $order->get_id() );
	$item_id  = absint( $item->get_id() );
	$result   = extrachill_shop_execute_priority_boost_owner_ability(
		array(
			'event'              => $event_reference,
			'external_reference' => 'shop-order:' . $order_id,
			'idempotency_key'    => 'shop-order:' . $order_id . ':item:' . $item_id,
		)
	);

	return is_wp_error( $result ) ? $result : extrachill_shop_validate_priority_boost_owner_response( $result, $event_reference );
}

/**
 * Validate and project the exact Events-owned priority boost response.
 *
 * @param mixed  $result Owner response.
 * @param string $event_reference Requested canonical event reference.
 * @return array|WP_Error Validated response or a stable error.
 */
function extrachill_shop_validate_priority_boost_owner_response( $result, $event_reference ) {
	$top_level               = array( 'success', 'replayed', 'existing_priority_preserved', 'event', 'receipt' );
	$event                   = is_array( $result ) && isset( $result['event'] ) && is_array( $result['event'] ) ? $result['event'] : array();
	$receipt                 = is_array( $result ) && isset( $result['receipt'] ) && is_array( $result['receipt'] ) ? $result['receipt'] : array();
	$event_matches_reference = ctype_digit( $event_reference )
		? isset( $event['post_id'] ) && (int) $event_reference === $event['post_id']
		: isset( $event['slug'] ) && $event_reference === $event['slug'];

	if (
		! is_array( $result )
		|| array() !== array_diff( array_keys( $result ), $top_level )
		|| array() !== array_diff( $top_level, array_keys( $result ) )
		|| true !== $result['success']
		|| ! is_bool( $result['replayed'] )
		|| ! is_bool( $result['existing_priority_preserved'] )
		|| array() !== array_diff( array_keys( $event ), array( 'post_id', 'title', 'slug', 'priority' ) )
		|| array() !== array_diff( array( 'post_id', 'title', 'slug', 'priority' ), array_keys( $event ) )
		|| ! is_int( $event['post_id'] )
		|| $event['post_id'] < 1
		|| ! is_string( $event['title'] )
		|| ! is_string( $event['slug'] )
		|| '' === $event['slug']
		|| true !== $event['priority']
		|| ! $event_matches_reference
		|| array() !== array_diff( array_keys( $receipt ), array( 'operation_id', 'actor_id', 'granted_at' ) )
		|| array() !== array_diff( array( 'operation_id', 'actor_id', 'granted_at' ), array_keys( $receipt ) )
		|| ! is_string( $receipt['operation_id'] )
		|| '' === $receipt['operation_id']
		|| ! is_int( $receipt['actor_id'] )
		|| $receipt['actor_id'] < 0
		|| ! is_string( $receipt['granted_at'] )
		|| '' === $receipt['granted_at']
	) {
		return new WP_Error( 'priority_boost_owner_response_invalid', __( 'The Events owner returned an invalid response.', 'extrachill-shop' ) );
	}

	return array(
		'success'                     => true,
		'replayed'                    => $result['replayed'],
		'existing_priority_preserved' => $result['existing_priority_preserved'],
		'event'                       => $event,
		'receipt'                     => $receipt,
	);
}
