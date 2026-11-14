<?php
/**
 * Checkout Shipping Integration
 *
 * Custom WooCommerce shipping method: one flat rate per order (a cart holds a
 * single seller), free when every item ships free, US-only.
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

			// A cart holds one seller (extrachill_shop_single_seller()), so it
			// ships as one parcel at the flat rate.
			$this->add_rate(
				array(
					'id'       => $this->get_rate_id(),
					'label'    => __( 'Shipping', 'extrachill-shop' ),
					'cost'     => extrachill_shop_get_flat_rate_per_artist(),
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
