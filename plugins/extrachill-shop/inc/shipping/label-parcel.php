<?php
/**
 * Label parcels and the label cost against the shipping hold.
 *
 * A label is bought for the parcel the seller confirms in the shop manager.
 * The form is prefilled from the order's products: their WooCommerce weights
 * summed (times quantity) and the largest length, width and height of any
 * item. Products without a weight or dimensions fall back to the default
 * parcel (10×8×4 in, 1 lb).
 *
 * The buyer's shipping charge, held in the application fee, pays for the
 * label (see shipping-recovery.php). When the cheapest rate costs more than
 * that hold, a seller cannot buy the label through Extra Chill: they ship it
 * themselves and the hold is paid out to them when they mark the order
 * shipped. A shop manager (`manage_woocommerce`) may buy it anyway after
 * confirming the overage, and Extra Chill absorbs the difference.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** Largest parcel weight a USPS label accepts, in ounces (70 lb). */
const EXTRACHILL_SHOP_PARCEL_MAX_OZ = 1120;

/** Largest single parcel side accepted, in inches. */
const EXTRACHILL_SHOP_PARCEL_MAX_IN = 108;

/**
 * Default parcel when products carry no weight or dimensions.
 *
 * Standard size for most merch items (t-shirts, small goods).
 *
 * @return array{weight: float, mass_unit: string, length: float, width: float, height: float, distance_unit: string}
 */
function extrachill_shop_get_default_parcel() {
	return array(
		'weight'        => 16.0,
		'mass_unit'     => 'oz',
		'length'        => 10.0,
		'width'         => 8.0,
		'height'        => 4.0,
		'distance_unit' => 'in',
	);
}

/**
 * Convert a WooCommerce product weight to ounces.
 *
 * @param float  $weight Weight in the store unit.
 * @param string $unit   WooCommerce weight unit (kg, g, lbs, oz).
 * @return float
 */
function extrachill_shop_weight_to_oz( $weight, $unit ) {
	$factors = array(
		'kg'  => 35.27396195,
		'g'   => 0.03527396195,
		'lbs' => 16.0,
		'lb'  => 16.0,
		'oz'  => 1.0,
	);
	return (float) $weight * ( $factors[ strtolower( (string) $unit ) ] ?? $factors['kg'] );
}

/**
 * Convert a WooCommerce product dimension to inches.
 *
 * @param float  $length Length in the store unit.
 * @param string $unit   WooCommerce dimension unit (m, cm, mm, in, yd).
 * @return float
 */
function extrachill_shop_dimension_to_in( $length, $unit ) {
	$factors = array(
		'm'  => 39.37007874,
		'cm' => 0.3937007874,
		'mm' => 0.03937007874,
		'in' => 1.0,
		'yd' => 36.0,
	);
	return (float) $length * ( $factors[ strtolower( (string) $unit ) ] ?? $factors['cm'] );
}

/**
 * Default parcel for an order, from its products' weights and dimensions.
 *
 * Weight is the sum of each item's weight times its quantity; items without
 * a weight add nothing, and an order with no weights uses the default weight.
 * Dimensions are the largest length, width and height of any item with all
 * three set, else the default box.
 *
 * @param WC_Order $order Order.
 * @return array Parcel (oz, in).
 */
function extrachill_shop_order_default_parcel( $order ) {
	$parcel      = extrachill_shop_get_default_parcel();
	$weight_unit = (string) get_option( 'woocommerce_weight_unit', 'kg' );
	$dim_unit    = (string) get_option( 'woocommerce_dimension_unit', 'cm' );
	$weight_oz   = 0.0;
	$dims        = null;

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! method_exists( $item, 'get_product_id' ) ) {
			continue;
		}
		$product_id = method_exists( $item, 'get_variation_id' ) && $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
		$product    = $product_id && function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			continue;
		}
		$quantity = max( 1, (int) $item->get_quantity() );
		$weight   = (float) $product->get_weight();
		if ( $weight > 0 ) {
			$weight_oz += extrachill_shop_weight_to_oz( $weight, $weight_unit ) * $quantity;
		}
		$item_dims = array(
			(float) $product->get_length(),
			(float) $product->get_width(),
			(float) $product->get_height(),
		);
		if ( min( $item_dims ) > 0 ) {
			$item_dims = array_map( static fn( $d ) => extrachill_shop_dimension_to_in( $d, $dim_unit ), $item_dims );
			$dims      = null === $dims ? $item_dims : array_map( 'max', $dims, $item_dims );
		}
	}

	if ( $weight_oz > 0 ) {
		$parcel['weight'] = min( (float) EXTRACHILL_SHOP_PARCEL_MAX_OZ, ceil( $weight_oz * 10 ) / 10 );
	}
	if ( null !== $dims ) {
		list( $parcel['length'], $parcel['width'], $parcel['height'] ) = array_map( static fn( $d ) => min( (float) EXTRACHILL_SHOP_PARCEL_MAX_IN, ceil( $d * 10 ) / 10 ), $dims );
	}
	return $parcel;
}

/**
 * Validate a parcel entered by the seller.
 *
 * @param mixed $input `{weight, mass_unit (oz|lb), length, width, height}` in inches.
 * @return array|WP_Error Parcel (weight in the given unit, inches).
 */
function extrachill_shop_normalize_parcel( $input ) {
	if ( ! is_array( $input ) ) {
		return new WP_Error( 'invalid_parcel', 'Enter the parcel weight and dimensions.', array( 'status' => 400 ) );
	}
	$unit = strtolower( (string) ( $input['mass_unit'] ?? 'oz' ) );
	if ( 'lbs' === $unit ) {
		$unit = 'lb';
	}
	if ( ! in_array( $unit, array( 'oz', 'lb' ), true ) ) {
		return new WP_Error( 'invalid_parcel', 'Parcel weight must be in ounces or pounds.', array( 'status' => 400 ) );
	}
	$weight    = round( (float) ( $input['weight'] ?? 0 ), 2 );
	$weight_oz = 'lb' === $unit ? $weight * 16 : $weight;
	if ( $weight <= 0 || $weight_oz > EXTRACHILL_SHOP_PARCEL_MAX_OZ ) {
		return new WP_Error( 'invalid_parcel', 'Parcel weight must be more than 0 and at most 70 lb.', array( 'status' => 400 ) );
	}
	$parcel = array(
		'weight'        => $weight,
		'mass_unit'     => $unit,
		'distance_unit' => 'in',
	);
	foreach ( array( 'length', 'width', 'height' ) as $side ) {
		$value = round( (float) ( $input[ $side ] ?? 0 ), 2 );
		if ( $value <= 0 || $value > EXTRACHILL_SHOP_PARCEL_MAX_IN ) {
			return new WP_Error( 'invalid_parcel', sprintf( 'Parcel %s must be more than 0 and at most %d in.', $side, EXTRACHILL_SHOP_PARCEL_MAX_IN ), array( 'status' => 400 ) );
		}
		$parcel[ $side ] = $value;
	}
	return $parcel;
}

/**
 * Parcel in the Shippo request format (string values).
 *
 * @param array $parcel Normalized parcel.
 * @return array
 */
function extrachill_shop_shippo_parcel( array $parcel ) {
	return array(
		'length'        => (string) $parcel['length'],
		'width'         => (string) $parcel['width'],
		'height'        => (string) $parcel['height'],
		'distance_unit' => (string) $parcel['distance_unit'],
		'weight'        => (string) $parcel['weight'],
		'mass_unit'     => (string) $parcel['mass_unit'],
	);
}

/**
 * Input schema for a label parcel.
 *
 * @return array
 */
function extrachill_shop_parcel_input_schema() {
	return array(
		'type'        => 'object',
		'description' => 'Parcel to buy the label for. Defaults to the order products\' weights and dimensions.',
		'properties'  => array(
			'weight'    => array(
				'type'        => 'number',
				'description' => 'Parcel weight.',
			),
			'mass_unit' => array(
				'type'        => 'string',
				'enum'        => array( 'oz', 'lb' ),
				'description' => 'Weight unit.',
			),
			'length'    => array(
				'type'        => 'number',
				'description' => 'Length in inches.',
			),
			'width'     => array(
				'type'        => 'number',
				'description' => 'Width in inches.',
			),
			'height'    => array(
				'type'        => 'number',
				'description' => 'Height in inches.',
			),
		),
	);
}

/**
 * Whether a label that costs more than the shipping hold may be bought.
 *
 * Within the hold: always. Above it: only a shop manager, and only after
 * confirming, because Extra Chill pays the difference. A seller ships the
 * order themselves instead and the hold is paid out to them.
 *
 * @param int  $cost_minor Label cost in minor units.
 * @param int  $hold_minor Shipping hold in minor units.
 * @param bool $confirmed  Whether the caller confirmed the overage.
 * @return true|WP_Error
 */
function extrachill_shop_label_overage_allowed( $cost_minor, $hold_minor, $confirmed ) {
	if ( $cost_minor <= $hold_minor ) {
		return true;
	}
	$data = array(
		'status'        => 409,
		'cost'          => (float) ( $cost_minor / 100 ),
		'shipping_held' => (float) ( $hold_minor / 100 ),
	);
	if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce shop manager capability.
		return new WP_Error(
			'label_exceeds_shipping',
			sprintf(
				'This label costs $%s, more than the $%s shipping the buyer paid, so it can\'t be bought through Extra Chill. Ship the order yourself and mark it shipped with a tracking number; the $%s shipping is paid out to you.',
				number_format( $cost_minor / 100, 2 ),
				number_format( $hold_minor / 100, 2 ),
				number_format( $hold_minor / 100, 2 )
			),
			$data
		);
	}
	if ( ! $confirmed ) {
		return new WP_Error(
			'label_overage_unconfirmed',
			sprintf(
				'This label costs $%s, $%s more than the shipping the buyer paid. Confirm the overage to buy it; Extra Chill pays the difference.',
				number_format( $cost_minor / 100, 2 ),
				number_format( ( $cost_minor - $hold_minor ) / 100, 2 )
			),
			$data
		);
	}
	return true;
}
