<?php
/**
 * Operator abilities for running the Shop.
 *
 * Cross-seller reads and recovery actions for shop operators (anyone with
 * `manage_woocommerce` on the Shop site). Seller-scoped abilities
 * (`shop-list-orders`, `shop-refund-order`, `shop-stripe-status`, ...) stay the
 * seller-facing surface; these cover what only an operator does:
 *
 * - extrachill/shop-list-sellers           Every seller with products or a Stripe record.
 * - extrachill/shop-operator-list-orders   Orders across sellers, optionally one seller.
 * - extrachill/shop-get-order              One order with its seller, payment and boost state.
 * - extrachill/shop-replay-webhook         Re-fetch a Stripe event and route it again.
 * - extrachill/shop-retry-priority-boost   Re-run ungranted Priority Boost grants on an order.
 *
 * Surfaced by `wp extrachill shop` (extrachill-cli).
 *
 * @package ExtraChillShop
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_init', 'extrachill_shop_register_operator_abilities' );

/** Order statuses an operator can filter on (WooCommerce slugs without `wc-`). */
const EXTRACHILL_SHOP_OPERATOR_ORDER_STATUSES = array( 'any', 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );

/**
 * Register the operator abilities.
 */
function extrachill_shop_register_operator_abilities(): void {
	$read = array(
		'show_in_rest' => true,
		'annotations'  => array(
			'readonly'    => true,
			'idempotent'  => true,
			'destructive' => false,
		),
	);

	wp_register_ability(
		'extrachill/shop-list-sellers',
		array(
			'label'               => __( 'List Shop Sellers', 'extrachill-shop' ),
			'description'         => __( 'List every seller that has Shop products or a Stripe Connect record, with cached payment and ship-from state.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'type' => array(
						'type'        => 'string',
						'enum'        => array( 'artist', 'venue', 'promoter' ),
						'description' => 'Only list sellers of this type.',
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sellers' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_list_sellers',
			'permission_callback' => 'extrachill_shop_operator_permission',
			'meta'                => $read,
		)
	);

	wp_register_ability(
		'extrachill/shop-operator-list-orders',
		array(
			'label'               => __( 'List Shop Orders (operator)', 'extrachill-shop' ),
			'description'         => __( 'List orders across all sellers, optionally filtered by seller and WooCommerce status.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'seller'   => extrachill_shop_seller_input_schema(),
					'status'   => array(
						'type'    => 'string',
						'enum'    => EXTRACHILL_SHOP_OPERATOR_ORDER_STATUSES,
						'default' => 'any',
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'orders'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'total'       => array( 'type' => 'integer' ),
					'total_pages' => array( 'type' => 'integer' ),
					'page'        => array( 'type' => 'integer' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_operator_list_orders',
			'permission_callback' => 'extrachill_shop_operator_permission',
			'meta'                => $read,
		)
	);

	wp_register_ability(
		'extrachill/shop-get-order',
		array(
			'label'               => __( 'Get Shop Order', 'extrachill-shop' ),
			'description'         => __( 'Read one order with its seller, Stripe references, refunds and Priority Boost receipts.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'   => array( 'id' ),
			),
			'output_schema'       => array( 'type' => 'object' ),
			'execute_callback'    => 'extrachill_shop_ability_get_order',
			'permission_callback' => 'extrachill_shop_operator_permission',
			'meta'                => $read,
		)
	);

	wp_register_ability(
		'extrachill/shop-replay-webhook',
		array(
			'label'               => __( 'Replay Stripe Webhook', 'extrachill-shop' ),
			'description'         => __( 'Fetch a Stripe event from the Stripe API and route it through the Shop webhook router again. Handlers are idempotent.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'event_id' => array(
						'type'    => 'string',
						'pattern' => '^evt_[A-Za-z0-9]+$',
					),
					'account'  => array(
						'type'        => 'string',
						'pattern'     => '^acct_[A-Za-z0-9]+$',
						'description' => 'Connected account the event belongs to (Connect events only).',
					),
				),
				'required'   => array( 'event_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'event_id' => array( 'type' => 'string' ),
					'type'     => array( 'type' => 'string' ),
					'account'  => array( 'type' => 'string' ),
					'livemode' => array( 'type' => 'boolean' ),
					'routed'   => array( 'type' => 'boolean' ),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_replay_webhook',
			'permission_callback' => 'extrachill_shop_operator_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);

	wp_register_ability(
		'extrachill/shop-retry-priority-boost',
		array(
			'label'               => __( 'Retry Priority Boost', 'extrachill-shop' ),
			'description'         => __( 'Grant every Priority Boost item on a paid order that has no receipt yet. Receipted items are skipped; the Events owner is idempotent per item.', 'extrachill-shop' ),
			'category'            => 'extrachill-shop',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'   => array( 'order_id' ),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'order_id' => array( 'type' => 'integer' ),
					'items'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
				),
			),
			'execute_callback'    => 'extrachill_shop_ability_retry_priority_boost',
			'permission_callback' => 'extrachill_shop_operator_permission',
			'meta'                => array(
				'show_in_rest' => true,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Operators are Shop managers.
 *
 * @return bool
 */
function extrachill_shop_operator_permission(): bool {
	return current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce shop manager capability.
}

/**
 * List every seller with products or a Stripe record.
 *
 * @param array $input Ability input.
 * @return array
 */
function extrachill_shop_ability_list_sellers( array $input = array() ): array {
	global $wpdb;

	$sellers  = array();
	$products = array();

	$product_ids = get_posts(
		array(
			'post_type'      => 'product',
			'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Operator listing, bounded by catalogue size.
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_artist_profile_id',
					'compare' => 'EXISTS',
				),
				array(
					'key'     => '_extrachill_shop_seller_type',
					'compare' => 'EXISTS',
				),
			),
		)
	);
	foreach ( $product_ids as $product_id ) {
		$seller = extrachill_shop_get_product_seller( (int) $product_id );
		if ( $seller ) {
			$key              = extrachill_shop_seller_ref_key( $seller );
			$sellers[ $key ]  = $seller;
			$products[ $key ] = ( $products[ $key ] ?? 0 ) + 1;
		}
	}

	$prefix       = 'extrachill_shop_stripe_seller_';
	$option_names = $wpdb->get_col(
		$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' )
	);
	foreach ( (array) $option_names as $option_name ) {
		$seller = extrachill_shop_parse_seller_key( substr( (string) $option_name, strlen( $prefix ) ) );
		if ( $seller ) {
			$sellers[ extrachill_shop_seller_ref_key( $seller ) ] = $seller;
		}
	}

	$type = (string) ( $input['type'] ?? '' );
	$rows = array();
	foreach ( $sellers as $key => $seller ) {
		if ( '' !== $type && $type !== $seller['type'] ) {
			continue;
		}
		$record    = extrachill_shop_get_seller_stripe_account_record( $seller );
		$canonical = extrachill_shop_get_canonical_seller( $seller );
		$rows[]    = array(
			'seller'              => $seller,
			'key'                 => $key,
			'name'                => is_wp_error( $canonical ) ? '' : (string) $canonical['name'],
			'platform'            => 'artist' === $seller['type'] && defined( 'EC_PLATFORM_ARTIST_ID' ) && EC_PLATFORM_ARTIST_ID === $seller['id'],
			'products'            => $products[ $key ] ?? 0,
			'stripe_account'      => $record ? (string) $record['account_id'] : '',
			'stripe_status'       => $record ? (string) $record['status'] : '',
			'onboarding_complete' => $record ? ! empty( $record['onboarding_complete'] ) : false,
			'ship_from'           => false !== extrachill_shop_get_seller_shipping_address( $seller ),
		);
	}
	usort(
		$rows,
		static function ( array $a, array $b ): int {
			return array( $a['seller']['type'], $a['seller']['id'] ) <=> array( $b['seller']['type'], $b['seller']['id'] );
		}
	);

	return array( 'sellers' => $rows );
}

/**
 * List orders across sellers.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_operator_list_orders( array $input ): array|WP_Error {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}

	$status = (string) ( $input['status'] ?? 'any' );
	if ( ! in_array( $status, EXTRACHILL_SHOP_OPERATOR_ORDER_STATUSES, true ) ) {
		return new WP_Error( 'invalid_status', 'Unknown order status.', array( 'status' => 400 ) );
	}
	$page     = max( 1, (int) ( $input['page'] ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) );
	$args     = array(
		'type'     => 'shop_order',
		'limit'    => $per_page,
		'paged'    => $page,
		'paginate' => true,
		'orderby'  => 'date',
		'order'    => 'DESC',
		'status'   => 'any' === $status ? array_keys( wc_get_order_statuses() ) : array( 'wc-' . $status ),
	);

	if ( isset( $input['seller'] ) ) {
		$seller = extrachill_shop_validate_seller_ref( $input['seller'] );
		if ( is_wp_error( $seller ) ) {
			return $seller;
		}
		// Same seller index shop-list-orders reads (inc/core/order-seller-index.php).
		$seller_key = extrachill_shop_seller_ref_key( $seller );
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed seller lookup.
			$args['meta_query'] = array(
				array(
					'key'   => '_extrachill_order_seller',
					'value' => $seller_key,
				),
			);
		} else {
			$args['extrachill_order_seller'] = $seller_key;
		}
	}

	$result = wc_get_orders( $args );
	$orders = array();
	foreach ( $result->orders as $order ) {
		$seller   = extrachill_shop_get_order_seller( $order );
		$orders[] = array(
			'id'           => (int) $order->get_id(),
			'number'       => (string) $order->get_order_number(),
			'status'       => (string) $order->get_status(),
			'date_created' => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
			'seller'       => $seller ? extrachill_shop_seller_ref_key( $seller ) : '',
			'customer'     => (string) $order->get_billing_email(),
			'total'        => (float) $order->get_total(),
			'currency'     => (string) $order->get_currency(),
		);
	}

	return array(
		'orders'      => $orders,
		'total'       => (int) $result->total,
		'total_pages' => (int) $result->max_num_pages,
		'page'        => $page,
	);
}

/**
 * The seller an order belongs to.
 *
 * Reads the checkout-time `_extrachill_seller` record, falling back to the
 * first `_extrachill_order_seller` index row. Null for platform orders.
 *
 * @param object $order WooCommerce order.
 * @return array|null
 */
function extrachill_shop_get_order_seller( $order ): ?array {
	$seller = extrachill_shop_validate_seller_ref( $order->get_meta( '_extrachill_seller', true ) );
	if ( ! is_wp_error( $seller ) ) {
		return $seller;
	}
	return extrachill_shop_parse_seller_key( $order->get_meta( '_extrachill_order_seller', true ) );
}

/**
 * Read one order for an operator.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_get_order( array $input ): array|WP_Error {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'woocommerce_missing', 'WooCommerce is not available.', array( 'status' => 500 ) );
	}
	$order = wc_get_order( (int) ( $input['id'] ?? 0 ) );
	if ( ! $order || 'shop_order' !== $order->get_type() ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$items = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$row = array(
			'item_id'    => (int) $item->get_id(),
			'product_id' => (int) $item->get_product_id(),
			'name'       => (string) $item->get_name(),
			'quantity'   => (int) $item->get_quantity(),
			'total'      => (float) $item->get_total(),
		);
		if ( extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) ) {
			$row['priority_boost'] = array(
				'event'   => (string) $item->get_meta( 'priority_boost_event_reference', true ),
				'title'   => (string) $item->get_meta( 'priority_boost_event_title', true ),
				'granted' => (bool) $order->get_meta( '_extrachill_priority_boost_receipt_' . $item->get_id(), true ),
			);
		}
		$items[] = $row;
	}

	$refunds = array();
	foreach ( $order->get_refunds() as $refund ) {
		$refunds[] = array(
			'id'     => (int) $refund->get_id(),
			'amount' => (float) $refund->get_amount(),
			'reason' => (string) $refund->get_reason(),
			'date'   => $refund->get_date_created() ? $refund->get_date_created()->format( 'c' ) : '',
		);
	}

	$seller = extrachill_shop_get_order_seller( $order );
	return array(
		'id'               => (int) $order->get_id(),
		'number'           => (string) $order->get_order_number(),
		'status'           => (string) $order->get_status(),
		'date_created'     => $order->get_date_created() ? $order->get_date_created()->format( 'c' ) : '',
		'date_paid'        => $order->get_date_paid() ? $order->get_date_paid()->format( 'c' ) : '',
		'seller'           => $seller,
		'customer'         => array(
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'email' => (string) $order->get_billing_email(),
		),
		'payment_method'   => (string) $order->get_payment_method(),
		'stripe_account'   => (string) $order->get_meta( '_extrachill_stripe_account', true ),
		'checkout_session' => (string) $order->get_meta( '_extrachill_checkout_session', true ),
		'payment_intent'   => (string) $order->get_meta( '_extrachill_payment_intent', true ),
		'currency'         => (string) $order->get_currency(),
		'total'            => (float) $order->get_total(),
		'shipping_total'   => (float) $order->get_shipping_total(),
		'total_refunded'   => (float) $order->get_total_refunded(),
		'items'            => $items,
		'refunds'          => $refunds,
	);
}

/**
 * Re-fetch a Stripe event and route it again.
 *
 * The event is read from the Stripe API with the platform secret key, so it
 * is authenticated the same way a signed delivery is. For Connect events the
 * account is the one the event was read from.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_replay_webhook( array $input ): array|WP_Error {
	$event_id = (string) ( $input['event_id'] ?? '' );
	$account  = (string) ( $input['account'] ?? '' );
	if ( ! preg_match( '/^evt_[A-Za-z0-9]+$/', $event_id ) || ( '' !== $account && ! preg_match( '/^acct_[A-Za-z0-9]+$/', $account ) ) ) {
		return new WP_Error( 'invalid_event', 'A Stripe event ID (evt_...) and optional account (acct_...) are required.', array( 'status' => 400 ) );
	}
	if ( ! extrachill_shop_stripe_init() ) {
		return new WP_Error( 'stripe_not_configured', 'Stripe is not configured.', array( 'status' => 500 ) );
	}

	try {
		$event = \Stripe\Event::retrieve( $event_id, '' !== $account ? array( 'stripe_account' => $account ) : array() );
	} catch ( \Exception $e ) {
		return new WP_Error( 'stripe_event_not_found', 'Stripe could not return that event: ' . $e->getMessage(), array( 'status' => 404 ) );
	}
	if ( '' !== $account ) {
		$event->account = $account;
	}

	$result = extrachill_shop_route_webhook_event( $event );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return array(
		'event_id' => (string) $event->id,
		'type'     => (string) $event->type,
		'account'  => (string) ( $event->account ?? '' ),
		'livemode' => (bool) $event->livemode,
		'routed'   => true,
	);
}

/**
 * Grant every unreceipted Priority Boost item on an order.
 *
 * Uses the same grant, receipt key and order notes as the purchase handler
 * (`extrachill_shop_handle_priority_boost_purchase()`), and reports each item.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_ability_retry_priority_boost( array $input ): array|WP_Error {
	$order_id = (int) ( $input['order_id'] ?? 0 );
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	if ( ! $order ) {
		return new WP_Error( 'order_not_found', 'Order not found.', array( 'status' => 404 ) );
	}

	$items   = array();
	$changed = false;
	foreach ( $order->get_items() as $item ) {
		if ( ! extrachill_shop_is_priority_boost_product_id( $item->get_product_id() ) ) {
			continue;
		}
		$item_id     = (int) $item->get_id();
		$receipt_key = '_extrachill_priority_boost_receipt_' . $item_id;
		$event_title = (string) $item->get_meta( 'priority_boost_event_title', true );
		$row         = array(
			'item_id' => $item_id,
			'event'   => $event_title,
		);

		if ( $order->get_meta( $receipt_key, true ) ) {
			$items[] = $row + array( 'result' => 'already_granted' );
			continue;
		}

		$result = extrachill_shop_grant_event_priority_boost( $order, $item );
		if ( is_wp_error( $result ) ) {
			$order->add_order_note(
				sprintf(
					/* translators: %s: stable owner error code */
					__( 'Priority boost pending: Events owner returned %s.', 'extrachill-shop' ),
					$result->get_error_code()
				)
			);
			$items[] = $row + array(
				'result' => 'error',
				'error'  => $result->get_error_code(),
			);
			$changed = true;
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
		$items[] = $row + array( 'result' => 'granted' );
		$changed = true;
	}

	if ( $changed ) {
		$order->save();
	}
	if ( ! $items ) {
		return new WP_Error( 'no_priority_boost', 'This order has no Priority Boost items.', array( 'status' => 404 ) );
	}

	return array(
		'order_id' => $order_id,
		'items'    => $items,
	);
}
