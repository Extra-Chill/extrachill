<?php
/**
 * Extra Chill shipping method.
 *
 * Loaded on `woocommerce_shipping_init`, once WC_Shipping_Method exists
 * (see extrachill_shop_shipping_method_init()).
 *
 * @package ExtraChill\Shop
 */

defined( 'ABSPATH' ) || exit;

/**
 * One flat rate per order, free when every item ships free.
 */
class ExtraChill_Artist_Shipping_Method extends WC_Shipping_Method {

	/**
	 * Constructor.
	 *
	 * @param int $instance_id Shipping zone instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'extrachill_artist_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Artist Shipping', 'extrachill-shop' );
		$this->method_description = __( 'Flat rate shipping per artist in cart.', 'extrachill-shop' );
		$this->supports           = array( 'shipping-zones', 'instance-settings' );
		$this->enabled            = 'yes';

		$this->init();
	}

	/**
	 * Load settings and hook the admin save handler.
	 */
	public function init() {
		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option( 'title', __( 'Shipping', 'extrachill-shop' ) );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Instance settings fields.
	 */
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

	/**
	 * Add the order's shipping rate.
	 *
	 * @param array $package Shipping package.
	 */
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
