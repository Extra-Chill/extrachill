<?php
/**
 * Checkout Shipping Integration
 *
 * Custom WooCommerce shipping calculation based on unique artists in cart.
 * Charges $5 per artist with US-only shipping restriction.
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register custom shipping method.
 */
add_filter( 'woocommerce_shipping_methods', 'extrachill_shop_register_shipping_method' );

function extrachill_shop_register_shipping_method( $methods ) {
	$methods['extrachill_artist_shipping'] = 'ExtraChill_Artist_Shipping_Method';
	return $methods;
}

/**
 * Load shipping method class.
 */
add_action( 'woocommerce_shipping_init', 'extrachill_shop_shipping_method_init' );

function extrachill_shop_shipping_method_init() {
	if ( class_exists( 'ExtraChill_Artist_Shipping_Method' ) ) {
		return;
	}

	class ExtraChill_Artist_Shipping_Method extends WC_Shipping_Method {

		public function __construct( $instance_id = 0 ) {
			$this->id                 = 'extrachill_artist_shipping';
			$this->instance_id        = absint( $instance_id );
			$this->method_title       = __( 'Artist Shipping', 'extrachill-shop' );
			$this->method_description = __( 'Flat rate shipping per artist in cart.', 'extrachill-shop' );
			$this->supports           = array( 'shipping-zones', 'instance-settings' );
			$this->enabled            = 'yes';

			$this->init();
		}

		public function init() {
			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->get_option( 'title', __( 'Shipping', 'extrachill-shop' ) );

			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
		}

		public function init_form_fields() {
			$this->instance_form_fields = array(
				'title' => array(
					'title'       => __( 'Method Title', 'extrachill-shop' ),
					'type'        => 'text',
					'description' => __( 'Title shown to customers.', 'extrachill-shop' ),
					'default'     => __( 'Shipping', 'extrachill-shop' ),
				),
			);
		}

		public function calculate_shipping( $package = array() ) {
			// If all products ship free, no charge
			if ( extrachill_shop_cart_ships_free() ) {
				$this->add_rate(
					array(
						'id'       => $this->get_rate_id(),
						'label'    => __( 'Free Shipping', 'extrachill-shop' ),
						'cost'     => 0,
						'calc_tax' => 'per_order',
					)
				);
				return;
			}

			// Standard calculation for orders with shippable products
			$artist_count    = extrachill_shop_get_cart_artist_count();
			$rate_per_artist = extrachill_shop_get_flat_rate_per_artist();
			$total_cost      = $artist_count * $rate_per_artist;

			if ( $artist_count > 1 ) {
				$label = sprintf(
					__( 'Shipping ($%1$s × %2$d artists)', 'extrachill-shop' ),
					number_format( $rate_per_artist, 0 ),
					$artist_count
				);
			} else {
				$label = __( 'Shipping', 'extrachill-shop' );
			}

			$this->add_rate(
				array(
					'id'       => $this->get_rate_id(),
					'label'    => $label,
					'cost'     => $total_cost,
					'calc_tax' => 'per_order',
				)
			);
		}
	}
}

/**
 * Check if cart contains only "ships free" products.
 *
 * @return bool True if all cart products have ships_free meta.
 */
function extrachill_shop_cart_ships_free() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}

	$cart_contents = WC()->cart->get_cart();
	if ( empty( $cart_contents ) ) {
		return false;
	}

	foreach ( $cart_contents as $cart_item ) {
		$product_id = $cart_item['product_id'] ?? 0;
		if ( ! $product_id ) {
			continue;
		}

		if ( '1' !== get_post_meta( $product_id, '_ships_free', true ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Get count of unique artists in the current cart.
 *
 * @return int Number of unique artists.
 */
function extrachill_shop_get_cart_artist_count() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return 0;
	}

	$artist_ids = array();

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product_id = $cart_item['product_id'] ?? 0;
		if ( ! $product_id ) {
			continue;
		}

		$artist_id = extrachill_shop_get_product_artist_id( $product_id );
		if ( $artist_id ) {
			$artist_ids[ $artist_id ] = true;
		}
	}

	$count = count( $artist_ids );

	return max( 1, $count );
}

/**
 * Get unique artist IDs from cart.
 *
 * @return array Array of unique artist profile IDs.
 */
function extrachill_shop_get_cart_artist_ids() {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return array();
	}

	$artist_ids = array();

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		$product_id = $cart_item['product_id'] ?? 0;
		if ( ! $product_id ) {
			continue;
		}

		$artist_id = extrachill_shop_get_product_artist_id( $product_id );
		if ( $artist_id ) {
			$artist_ids[ $artist_id ] = true;
		}
	}

	return array_keys( $artist_ids );
}

/**
 * Validate shipping address is US-only at checkout.
 */
add_action( 'woocommerce_after_checkout_validation', 'extrachill_shop_validate_us_shipping', 10, 2 );

function extrachill_shop_validate_us_shipping( $data, $errors ) {
	$shipping_country = $data['shipping_country'] ?? $data['billing_country'] ?? '';

	if ( ! empty( $shipping_country ) && 'US' !== $shipping_country ) {
		$errors->add(
			'shipping_country',
			__( 'Sorry, we currently only ship to addresses within the United States.', 'extrachill-shop' )
		);
	}
}

/**
 * Restrict shipping to US only in available countries.
 */
add_filter( 'woocommerce_countries_allowed_countries', 'extrachill_shop_restrict_shipping_countries' );

function extrachill_shop_restrict_shipping_countries( $countries ) {
	return array( 'US' => $countries['US'] ?? 'United States' );
}

/**
 * Restrict shipping zones to US only.
 */
add_filter( 'woocommerce_shipping_countries', 'extrachill_shop_restrict_shipping_countries' );

/**
 * Store artist IDs on the order for fulfillment routing.
 */
add_action( 'woocommerce_checkout_create_order', 'extrachill_shop_store_order_artist_ids', 10, 2 );

function extrachill_shop_store_order_artist_ids( $order, $data ) {
	$artist_ids = extrachill_shop_get_cart_artist_ids();
	if ( ! empty( $artist_ids ) ) {
		$order->update_meta_data( '_order_artist_ids', $artist_ids );
	}
}

/**
 * Get artist IDs from an order.
 *
 * @param int|WC_Order $order Order ID or object.
 * @return array Array of artist profile IDs.
 */
function extrachill_shop_get_order_artist_ids( $order ) {
	if ( is_numeric( $order ) ) {
		$order = wc_get_order( $order );
	}

	if ( ! $order ) {
		return array();
	}

	$artist_ids = $order->get_meta( '_order_artist_ids' );
	return is_array( $artist_ids ) ? $artist_ids : array();
}
