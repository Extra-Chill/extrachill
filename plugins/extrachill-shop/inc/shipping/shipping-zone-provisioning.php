<?php
/**
 * Shipping Zone Provisioning
 *
 * Shipping configuration is code-owned: checkout is US-only and the rate is
 * computed by the plugin's own `extrachill_artist_shipping` method. Without a
 * zone carrying that method, WooCommerce offers no shipping method and
 * physical products cannot check out.
 *
 * This ensures a "United States" zone (country location `US`) exists with the
 * artist shipping method attached and enabled. It is idempotent: an existing
 * US zone is adopted, an existing method instance is reused (and re-enabled if
 * disabled), so re-running never duplicates zones or methods.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'extrachill_shop_maybe_provision_shipping_zone', 20 );

/**
 * Option storing the provisioned shipping zone schema version.
 */
const EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION_OPTION = 'extrachill_shop_shipping_zone_version';

/**
 * Current shipping zone schema version. Bump to re-run provisioning.
 */
const EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION = '1';

/**
 * Shipping method ID attached to the US zone.
 */
const EXTRACHILL_SHOP_SHIPPING_METHOD_ID = 'extrachill_artist_shipping';

/**
 * Provision the US shipping zone once per schema version, on the Shop site.
 *
 * @return void
 */
function extrachill_shop_maybe_provision_shipping_zone() {
	if ( EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION === get_option( EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION_OPTION ) ) {
		return;
	}

	if ( function_exists( 'extrachill_shop_is_shop_site' ) && ! extrachill_shop_is_shop_site() ) {
		return;
	}

	if ( ! class_exists( 'WC_Shipping_Zone' ) || ! class_exists( 'WC_Shipping_Zones' ) ) {
		return;
	}

	$result = extrachill_shop_ensure_shipping_zone();
	if ( ! is_wp_error( $result ) ) {
		update_option( EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION_OPTION, EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION );
	}
}

/**
 * Ensure the US zone exists with the artist shipping method attached and enabled.
 *
 * @return array|WP_Error Array with `zone_id`, `instance_id`, `zone_created`,
 *                        `method_added` and `method_enabled`, or WP_Error.
 */
function extrachill_shop_ensure_shipping_zone() {
	$result = array(
		'zone_id'        => 0,
		'instance_id'    => 0,
		'zone_created'   => false,
		'method_added'   => false,
		'method_enabled' => false,
	);

	$zone = extrachill_shop_find_us_shipping_zone();
	if ( ! $zone ) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( __( 'United States', 'extrachill-shop' ) );
		$zone->set_zone_order( 0 );
		$zone->add_location( 'US', 'country' );
		$zone->save();

		if ( ! $zone->get_id() ) {
			return new WP_Error( 'shipping_zone_save_failed', __( 'Could not create the United States shipping zone.', 'extrachill-shop' ) );
		}

		$result['zone_created'] = true;
	}

	$result['zone_id'] = (int) $zone->get_id();

	foreach ( $zone->get_shipping_methods( false ) as $instance_id => $method ) {
		if ( EXTRACHILL_SHOP_SHIPPING_METHOD_ID !== $method->id ) {
			continue;
		}

		$result['instance_id'] = (int) $instance_id;

		if ( 'yes' !== $method->enabled ) {
			extrachill_shop_enable_shipping_method_instance( (int) $instance_id, $result['zone_id'] );
			$result['method_enabled'] = true;
		}

		return $result;
	}

	$instance_id = (int) $zone->add_shipping_method( EXTRACHILL_SHOP_SHIPPING_METHOD_ID );
	if ( ! $instance_id ) {
		return new WP_Error( 'shipping_method_add_failed', __( 'Could not attach the artist shipping method to the United States zone.', 'extrachill-shop' ) );
	}

	// New zone method rows default to is_enabled = 1.
	$result['instance_id']  = $instance_id;
	$result['method_added'] = true;

	return $result;
}

/**
 * Find an existing zone that covers the US as a country location.
 *
 * @return WC_Shipping_Zone|null
 */
function extrachill_shop_find_us_shipping_zone() {
	foreach ( WC_Shipping_Zones::get_zones() as $zone_data ) {
		foreach ( $zone_data['zone_locations'] ?? array() as $location ) {
			if ( 'country' === $location->type && 'US' === $location->code ) {
				$zone = WC_Shipping_Zones::get_zone( (int) $zone_data['zone_id'] );
				return $zone ? $zone : null;
			}
		}
	}

	return null;
}

/**
 * Enable a disabled zone method instance, as WooCommerce's own toggle does.
 *
 * @param int $instance_id Method instance ID.
 * @param int $zone_id     Zone ID.
 * @return void
 */
function extrachill_shop_enable_shipping_method_instance( $instance_id, $zone_id ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce exposes no API for toggling zone method status.
	$updated = $wpdb->update(
		"{$wpdb->prefix}woocommerce_shipping_zone_methods",
		array( 'is_enabled' => 1 ),
		array( 'instance_id' => absint( $instance_id ) )
	);

	if ( $updated ) {
		do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance_id, EXTRACHILL_SHOP_SHIPPING_METHOD_ID, $zone_id, 1 );
	}

	if ( class_exists( 'WC_Cache_Helper' ) ) {
		WC_Cache_Helper::get_transient_version( 'shipping', true );
	}
}
