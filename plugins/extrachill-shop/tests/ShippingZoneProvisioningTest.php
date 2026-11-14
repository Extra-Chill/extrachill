<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** US shipping zone provisioning: first run creates, re-runs are no-ops. */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		$GLOBALS['test_actions'][] = array( $hook, $args );
	}
}

/** In-memory shipping zone store shared by the WC stubs. */
final class ShippingZoneTestStore {
	public static $zones        = array();
	public static $next_zone    = 1;
	public static $next_method  = 1;
	public static $zone_saves   = 0;
	public static $method_adds  = 0;
	public static $allowed      = array( 'extrachill_artist_shipping', 'flat_rate' );

	public static function reset() {
		self::$zones       = array();
		self::$next_zone   = 1;
		self::$next_method = 1;
		self::$zone_saves  = 0;
		self::$method_adds = 0;
	}
}

if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
	class WC_Shipping_Zone {
		private $id        = null;
		private $name      = '';
		private $order     = 0;
		private $locations = array();

		public function __construct( $zone = null ) {
			if ( is_numeric( $zone ) && isset( ShippingZoneTestStore::$zones[ $zone ] ) ) {
				$row             = ShippingZoneTestStore::$zones[ $zone ];
				$this->id        = (int) $zone;
				$this->name      = $row['name'];
				$this->order     = $row['order'];
				$this->locations = $row['locations'];
			}
		}
		public function get_id() { return $this->id; }
		public function set_zone_name( $name ) { $this->name = $name; }
		public function set_zone_order( $order ) { $this->order = $order; }
		public function add_location( $code, $type ) {
			$this->locations[] = (object) array( 'code' => $code, 'type' => $type );
		}
		public function save() {
			++ShippingZoneTestStore::$zone_saves;
			if ( null === $this->id ) {
				$this->id = ShippingZoneTestStore::$next_zone++;
				ShippingZoneTestStore::$zones[ $this->id ]['methods'] = array();
			}
			ShippingZoneTestStore::$zones[ $this->id ]['name']      = $this->name;
			ShippingZoneTestStore::$zones[ $this->id ]['order']     = $this->order;
			ShippingZoneTestStore::$zones[ $this->id ]['locations'] = $this->locations;
			return $this->id;
		}
		public function get_zone_locations() { return $this->locations; }
		public function get_shipping_methods( $enabled_only = false ) {
			$methods = array();
			foreach ( ShippingZoneTestStore::$zones[ $this->id ]['methods'] ?? array() as $instance_id => $row ) {
				if ( $enabled_only && ! $row['is_enabled'] ) {
					continue;
				}
				$methods[ $instance_id ] = (object) array(
					'id'          => $row['method_id'],
					'instance_id' => $instance_id,
					'enabled'     => $row['is_enabled'] ? 'yes' : 'no',
				);
			}
			return $methods;
		}
		public function add_shipping_method( $type ) {
			if ( null === $this->id ) {
				$this->save();
			}
			if ( ! in_array( $type, ShippingZoneTestStore::$allowed, true ) ) {
				return 0;
			}
			++ShippingZoneTestStore::$method_adds;
			$instance_id = ShippingZoneTestStore::$next_method++;
			ShippingZoneTestStore::$zones[ $this->id ]['methods'][ $instance_id ] = array(
				'method_id'  => $type,
				'is_enabled' => 1,
			);
			return $instance_id;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
	class WC_Shipping_Zones {
		public static function get_zones() {
			$zones = array();
			foreach ( ShippingZoneTestStore::$zones as $id => $row ) {
				$zones[ $id ] = array(
					'zone_id'        => $id,
					'zone_name'      => $row['name'],
					'zone_locations' => $row['locations'],
				);
			}
			return $zones;
		}
		public static function get_zone( $zone_id ) {
			return isset( ShippingZoneTestStore::$zones[ $zone_id ] ) ? new WC_Shipping_Zone( $zone_id ) : false;
		}
	}
}

/** Minimal $wpdb double for the enable toggle. */
final class ShippingZoneTestWpdb {
	public $prefix  = 'wp_3_';
	public $updates = array();
	public function update( $table, $data, $where ) {
		$this->updates[] = compact( 'table', 'data', 'where' );
		foreach ( ShippingZoneTestStore::$zones as $zone_id => $row ) {
			if ( isset( $row['methods'][ $where['instance_id'] ] ) ) {
				ShippingZoneTestStore::$zones[ $zone_id ]['methods'][ $where['instance_id'] ]['is_enabled'] = $data['is_enabled'];
				return 1;
			}
		}
		return 0;
	}
}

require_once dirname( __DIR__ ) . '/inc/shipping/shipping-zone-provisioning.php';

final class ShippingZoneProvisioningTest extends TestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		ShippingZoneTestStore::reset();
		$this->previous_wpdb     = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['test_options'] = array();
		$GLOBALS['test_actions'] = array();
		$GLOBALS['wpdb']         = new ShippingZoneTestWpdb();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
	}

	private function us_zone_methods(): array {
		$this->assertCount( 1, ShippingZoneTestStore::$zones );
		return array_values( reset( ShippingZoneTestStore::$zones )['methods'] );
	}

	public function test_first_run_creates_us_zone_with_enabled_artist_shipping(): void {
		extrachill_shop_maybe_provision_shipping_zone();

		$this->assertCount( 1, ShippingZoneTestStore::$zones );
		$zone = reset( ShippingZoneTestStore::$zones );
		$this->assertSame( 'United States', $zone['name'] );
		$this->assertEquals( array( (object) array( 'code' => 'US', 'type' => 'country' ) ), $zone['locations'] );
		$this->assertSame(
			array( array( 'method_id' => 'extrachill_artist_shipping', 'is_enabled' => 1 ) ),
			$this->us_zone_methods()
		);
		$this->assertSame( '1', get_option( EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION_OPTION ) );
	}

	public function test_second_run_is_a_no_op(): void {
		extrachill_shop_maybe_provision_shipping_zone();
		$saves = ShippingZoneTestStore::$zone_saves;

		extrachill_shop_maybe_provision_shipping_zone();

		$this->assertSame( $saves, ShippingZoneTestStore::$zone_saves );
		$this->assertSame( 1, ShippingZoneTestStore::$method_adds );
		$this->assertCount( 1, $this->us_zone_methods() );
	}

	public function test_ensure_is_idempotent_even_without_version_guard(): void {
		$first = extrachill_shop_ensure_shipping_zone();
		$this->assertTrue( $first['zone_created'] );
		$this->assertTrue( $first['method_added'] );

		$second = extrachill_shop_ensure_shipping_zone();
		$this->assertFalse( $second['zone_created'] );
		$this->assertFalse( $second['method_added'] );
		$this->assertFalse( $second['method_enabled'] );
		$this->assertSame( $first['zone_id'], $second['zone_id'] );
		$this->assertSame( $first['instance_id'], $second['instance_id'] );
		$this->assertCount( 1, $this->us_zone_methods() );
		$this->assertSame( array(), $GLOBALS['wpdb']->updates );
	}

	public function test_adopts_existing_us_zone_and_reenables_disabled_method(): void {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'Domestic' );
		$zone->add_location( 'US', 'country' );
		$zone->save();
		$zone->add_shipping_method( 'flat_rate' );
		$instance_id = $zone->add_shipping_method( 'extrachill_artist_shipping' );
		ShippingZoneTestStore::$zones[ $zone->get_id() ]['methods'][ $instance_id ]['is_enabled'] = 0;

		$result = extrachill_shop_ensure_shipping_zone();

		$this->assertFalse( $result['zone_created'] );
		$this->assertFalse( $result['method_added'] );
		$this->assertTrue( $result['method_enabled'] );
		$this->assertSame( $instance_id, $result['instance_id'] );
		$this->assertCount( 2, $this->us_zone_methods() );
		$this->assertSame( 1, ShippingZoneTestStore::$zones[ $zone->get_id() ]['methods'][ $instance_id ]['is_enabled'] );
		$this->assertSame( 'wp_3_woocommerce_shipping_zone_methods', $GLOBALS['wpdb']->updates[0]['table'] );
	}

	public function test_failed_method_attach_does_not_mark_provisioned(): void {
		ShippingZoneTestStore::$allowed = array( 'flat_rate' );
		try {
			extrachill_shop_maybe_provision_shipping_zone();
			$this->assertFalse( get_option( EXTRACHILL_SHOP_SHIPPING_ZONE_VERSION_OPTION ) );
		} finally {
			ShippingZoneTestStore::$allowed = array( 'extrachill_artist_shipping', 'flat_rate' );
		}
	}
}
