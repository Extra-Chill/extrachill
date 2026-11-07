<?php
/**
 * Seller discovery for orders, independent of payment processing.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

// These hooks run after line items have been persisted (classic and Store API).
add_action( 'woocommerce_checkout_order_created', 'extrachill_shop_index_order_sellers' );
add_action( 'woocommerce_store_api_checkout_order_processed', 'extrachill_shop_index_order_sellers' );
add_action( 'woocommerce_saved_order_items', 'extrachill_shop_index_order_sellers' );
add_action( 'woocommerce_after_order_object_save', 'extrachill_shop_index_order_sellers' );
add_action( 'init', 'extrachill_shop_schedule_order_seller_backfill' );
add_action( 'extrachill_shop_backfill_order_sellers', 'extrachill_shop_backfill_order_sellers' );
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'extrachill_shop_order_seller_cpt_query', 10, 2 );

/** Translate the seller constraint for the post-backed order data store. */
function extrachill_shop_order_seller_cpt_query( $query, $vars ) {
	if ( ! empty( $vars['extrachill_order_seller'] ) ) {
		$query['meta_query'][] = array(
			'key'   => '_extrachill_order_seller',
			'value' => $vars['extrachill_order_seller'],
		);
	}
	return $query;
}

/** Write one distinct scalar meta row per payout seller. Safe to replay. */
function extrachill_shop_index_order_sellers( $order ) {
	$order = is_object( $order ) ? $order : wc_get_order( $order );
	if ( ! $order ) {
		return;
	}
	$sellers = array();
	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$seller = extrachill_shop_get_product_payout_seller( $item->get_product_id() );
		if ( $seller ) {
			$sellers[ $seller['type'] . ':' . $seller['id'] ] = true;
		}
	}
	$existing = $order->get_meta( '_extrachill_order_seller', false );
	$existing = array_map( static function ( $meta ) { return $meta->value;
	}, $existing );
	$desired  = array_keys( $sellers );
	sort( $existing );
	sort( $desired );
	if ( $existing !== $desired ) {
		$order->delete_meta_data( '_extrachill_order_seller' );
		foreach ( $desired as $seller_key ) {
			$order->add_meta_data( '_extrachill_order_seller', $seller_key, false );
		}
		$order->save_meta_data();
	}
}

/** Schedule the versioned, bounded migration without requiring WP-CLI. */
function extrachill_shop_schedule_order_seller_backfill() {
	if ( function_exists( 'wc_get_orders' ) && ! get_option( 'extrachill_shop_order_seller_index_complete' )
		&& ! wp_next_scheduled( 'extrachill_shop_backfill_order_sellers' ) ) {
		wp_schedule_single_event( time() + 10, 'extrachill_shop_backfill_order_sellers' );
	}
}

/** Resume in stable ID order; only advance the checkpoint after a full batch. */
function extrachill_shop_backfill_order_sellers() {
	if ( ! function_exists( 'wc_get_orders' ) || get_option( 'extrachill_shop_order_seller_index_complete' ) ) {
		return;
	}
	$page   = max( 1, (int) get_option( 'extrachill_shop_order_seller_index_page', 1 ) );
	$orders = wc_get_orders( array(
		'type'    => 'shop_order',
		'limit'   => 100,
		'paged'   => $page,
		'orderby' => 'ID',
		'order'   => 'ASC',
		'status'  => array_keys( wc_get_order_statuses() ),
	) );
	foreach ( $orders as $order ) {
		extrachill_shop_index_order_sellers( $order );
	}
	if ( count( $orders ) < 100 ) {
		update_option( 'extrachill_shop_order_seller_index_complete', 1, false );
	} else {
		update_option( 'extrachill_shop_order_seller_index_page', $page + 1, false );
		extrachill_shop_schedule_order_seller_backfill();
	}
}
