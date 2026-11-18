<?php
// phpcs:disable -- Test doubles and WordPress stubs.
/** Commerce fees resolve global → seller → event overrides (#1698). */
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return in_array( $capability, $GLOBALS['operator_caps'] ?? array(), true );
	}
}
require_once dirname( __DIR__ ) . '/inc/abilities/commerce-fee-overrides.php';

final class CommerceFeeOverrideTest extends TestCase {
	private const VENUE  = array( 'type' => 'venue', 'id' => 9911 );
	private const ARTIST = array( 'type' => 'artist', 'id' => 12 );
	private const EVENT  = 101;
	private const ZERO   = array(
		'merch_percent'                     => 0,
		'ticket_percent'                    => 0,
		'ticket_buyer_fee_per_ticket_minor' => 0,
		'ticket_buyer_fee_flat_minor'       => 0,
		'ticket_buyer_fee_percent'          => 0,
		'processing_percent'                => 0,
		'processing_fixed_minor'            => 0,
	);

	protected function setUp(): void {
		$GLOBALS['test_site_options'] = array();
		$GLOBALS['test_blog_options'] = array();
		$GLOBALS['test_post_meta']    = array();
		$GLOBALS['test_post_types']   = array( self::EVENT => 'data_machine_events', 202 => 'post' );
		$GLOBALS['test_blog_stack']   = array();
		$GLOBALS['operator_caps']     = array( 'manage_network_options' );
		$GLOBALS['test_owner_calls']  = array();
		unset( $GLOBALS['test_filters']['ec_commerce_fee_settings_resolved'] );
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
			if ( str_contains( $route, 'get-event-ticket-offer' ) ) {
				return array( 'event_id' => self::EVENT, 'seller' => self::VENUE );
			}
			if ( str_contains( $route, 'get-commerce-seller' ) ) {
				return array( 'type' => 'venue', 'id' => 9911, 'name' => 'Lo-Fi' );
			}
			return new WP_Error( 'unexpected_owner_call', $route );
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['test_filters']['ec_commerce_fee_settings_resolved'], $GLOBALS['test_owner_callback'] );
	}

	private function seller_override( array $seller, array $values ): void {
		$GLOBALS['test_blog_options'][3][ EC_COMMERCE_FEE_SELLER_OPTION_PREFIX . $seller['type'] . ':' . $seller['id'] ] = $values;
	}

	private function event_override( array $values ): void {
		$GLOBALS['test_post_meta'][ self::EVENT ][ EC_COMMERCE_FEE_EVENT_META ] = $values;
	}

	/** Global settings variants, from the shipped defaults to a custom setup. */
	public static function globals(): array {
		return array(
			'defaults' => array( array() ),
			'custom'   => array( array( 'merch_percent' => 12.5, 'ticket_percent' => 2, 'ticket_buyer_fee_flat_minor' => 100, 'ticket_buyer_fee_percent' => 3, 'processing_percent' => 3.1 ) ),
			'zero'     => array( self::ZERO ),
		);
	}

	/** @dataProvider globals */
	public function test_no_override_is_identical_to_the_global_settings( array $global ): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = $global;
		$expected = ec_commerce_fee_global_settings();
		foreach ( array( array(), array( 'seller' => self::VENUE ), array( 'kind' => 'extrachill_ticket', 'seller' => self::VENUE, 'event_id' => self::EVENT ), array( 'seller' => null, 'event_id' => 0 ) ) as $context ) {
			$this->assertSame( $expected, ec_commerce_fee_settings( $context ) );
		}
		foreach ( array( 'account', 'application_express', '' ) as $payer ) {
			foreach ( array( array( 1000, 1 ), array( 2000, 4 ), array( 99, 10 ) ) as list( $subtotal, $quantity ) ) {
				$this->assertSame(
					extrachill_commerce_ticket_fee( $subtotal, $quantity, $expected, $payer ),
					extrachill_commerce_fee( 'extrachill_ticket', $subtotal, self::VENUE, $quantity, $payer, array( 'event_id' => self::EVENT ) )
				);
			}
			$this->assertSame( extrachill_commerce_merch_fee( 2500, 3000, self::VENUE, $payer, 500 ), extrachill_commerce_merch_fee( 2500, 3000, self::VENUE, $payer, 500 ) );
		}
		$this->assertSame( extrachill_shop_seller_fee_rates(), extrachill_shop_seller_fee_rates( self::VENUE ) );
		$this->assertSame( array(), $GLOBALS['test_blog_stack'], 'Every switch_to_blog() is restored.' );
	}

	public function test_overrides_layer_global_then_seller_then_event_field_by_field(): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'merch_percent' => 10, 'ticket_percent' => 0, 'ticket_buyer_fee_flat_minor' => 50 );
		$this->seller_override( self::VENUE, array( 'merch_percent' => 20, 'ticket_percent' => 5, 'ticket_buyer_fee_per_ticket_minor' => 200 ) );
		$this->event_override( array( 'ticket_percent' => 1 ) );

		$global = ec_commerce_fee_global_settings();
		$seller = ec_commerce_fee_settings( array( 'seller' => self::VENUE ) );
		$this->assertSame( array( 20.0, 5.0, 200, 50 ), array( $seller['merch_percent'], $seller['ticket_percent'], $seller['ticket_buyer_fee_per_ticket_minor'], $seller['ticket_buyer_fee_flat_minor'] ) );
		$this->assertSame( array_diff_key( $global, array_flip( array( 'merch_percent', 'ticket_percent', 'ticket_buyer_fee_per_ticket_minor' ) ) ), array_diff_key( $seller, array_flip( array( 'merch_percent', 'ticket_percent', 'ticket_buyer_fee_per_ticket_minor' ) ) ) );

		$event = ec_commerce_fee_settings( array( 'seller' => self::VENUE, 'event_id' => self::EVENT ) );
		$this->assertSame( 1.0, $event['ticket_percent'], 'The event wins over the seller.' );
		$this->assertSame( 200, $event['ticket_buyer_fee_per_ticket_minor'], 'Fields the event does not set come from the seller.' );
		$this->assertSame( 50, $event['ticket_buyer_fee_flat_minor'], '...and then from the global settings.' );
		$this->assertSame( array_keys( $global ), array_keys( $event ) );

		// Event without seller: global → event. Another seller is not affected.
		$this->assertSame( array_merge( $global, array( 'ticket_percent' => 1.0 ) ), ec_commerce_fee_settings( array( 'event_id' => self::EVENT ) ) );
		$this->assertSame( $global, ec_commerce_fee_settings( array( 'seller' => self::ARTIST ) ) );
	}

	public function test_overrides_use_the_global_sanitizer(): void {
		$this->assertSame(
			array( 'merch_percent' => 100.0, 'ticket_buyer_fee_flat_minor' => 125, 'processing_percent' => 50.0, 'processing_fixed_minor' => 0 ),
			ec_commerce_fee_sanitize_override( array( 'merch_percent' => '250', 'ticket_percent' => 'abc', 'ticket_buyer_fee_flat_minor' => '124.6', 'ticket_processing_percent' => 99, 'processing_fixed_minor' => -4, 'unknown' => 5 ) )
		);
		$this->assertSame( array(), ec_commerce_fee_sanitize_override( 'garbage' ) );
		$this->event_override( array( 'merch_percent' => 'x', 'ticket_percent' => 500 ) );
		$this->assertSame( array( 'ticket_percent' => 100.0 ), ec_commerce_fee_get_override( 'event', self::EVENT ) );
	}

	public function test_resolved_filter_runs_last_and_is_sanitized(): void {
		$this->event_override( array( 'ticket_percent' => 4 ) );
		add_filter(
			'ec_commerce_fee_settings_resolved',
			static function ( $settings, $context, $layers ) {
				$GLOBALS['fee_filter_seen'] = array( $context, $layers );
				$settings['merch_percent']  = -5;
				$settings['ticket_percent'] = 'nonsense';
				return $settings;
			},
			10,
			3
		);
		$resolved = ec_commerce_fee_settings( array( 'kind' => 'extrachill_ticket', 'event_id' => self::EVENT ) );
		$this->assertSame( 0.0, $resolved['merch_percent'], 'Filtered values are clamped.' );
		$this->assertSame( 4.0, $resolved['ticket_percent'], 'An invalid filtered value keeps the resolved one.' );
		$this->assertSame( array( array( 'kind' => 'extrachill_ticket', 'event_id' => self::EVENT ), array( 'event' => array( 'ticket_percent' => 4.0 ) ) ), $GLOBALS['fee_filter_seen'] );
	}

	public function test_a_filter_returning_garbage_keeps_the_resolved_settings(): void {
		$this->event_override( array( 'ticket_percent' => 4 ) );
		add_filter( 'ec_commerce_fee_settings_resolved', static fn() => 'broken' );
		$this->assertSame( array_merge( ec_commerce_fee_global_settings(), array( 'ticket_percent' => 4.0 ) ), ec_commerce_fee_settings( array( 'event_id' => self::EVENT ) ) );
	}

	public function test_zero_fee_event_takes_nothing(): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_percent' => 5, 'ticket_buyer_fee_flat_minor' => 100 );
		$this->event_override( self::ZERO );
		foreach ( array( 'account', 'application_express', '' ) as $payer ) {
			foreach ( array( array( 1000, 1 ), array( 8000, 4 ) ) as list( $subtotal, $quantity ) ) {
				$this->assertSame(
					array( 'buyer_fee' => 0, 'application_fee' => 0 ),
					extrachill_commerce_fee( 'extrachill_ticket', $subtotal, self::VENUE, $quantity, $payer, array( 'event_id' => self::EVENT ) )
				);
			}
		}
		// Other shows keep the global fees.
		$this->assertNotSame( 0, extrachill_commerce_fee( 'extrachill_ticket', 1000, self::VENUE, 1 )['application_fee'] );
	}

	public function test_merch_and_seller_rates_use_the_seller_override(): void {
		$this->seller_override( self::ARTIST, array( 'merch_percent' => 0, 'processing_percent' => 0, 'processing_fixed_minor' => 0 ) );
		$this->assertSame( array( 'buyer_fee' => 0, 'application_fee' => 0 ), extrachill_commerce_fee( 'extrachill_merch', 2500, self::ARTIST ) );
		$parts = extrachill_commerce_merch_fee( 2500, 3000, self::ARTIST, 'application_express', 500 );
		$this->assertSame( array( 0, 0, 500, 500 ), array( $parts['commission'], $parts['processing'], $parts['shipping_hold'], $parts['application_fee'] ), 'The shipping hold is not a fee rate.' );
		$this->assertSame( 250, extrachill_commerce_fee( 'extrachill_merch', 2500, self::VENUE )['application_fee'], 'Other sellers keep the global 10%.' );

		$rates = extrachill_shop_seller_fee_rates( self::ARTIST );
		$this->assertSame( 0.0, $rates['merch_percent'] );
		$this->assertSame( 'Merch fee: 0% of the item price (shipping not included) + card processing on the whole order.', $rates['merch_summary'] );
		$this->assertSame( 10.0, extrachill_shop_seller_fee_rates()['merch_percent'] );
	}

	public function test_stripe_status_reports_the_sellers_effective_rates(): void {
		$this->seller_override( self::ARTIST, array( 'merch_percent' => 7 ) );
		$this->assertSame( 7.0, extrachill_shop_ability_stripe_status( array( 'seller' => self::ARTIST ) )['fees']['merch_percent'] );
	}

	public function test_update_is_partial_null_inherits_and_empty_deletes(): void {
		$this->assertSame( array( 'merch_percent' => 5.0, 'ticket_percent' => 0.0 ), ec_commerce_fee_update_override( 'seller', self::VENUE, array( 'ticket_percent' => 0, 'merch_percent' => 5 ) ) );
		$this->assertSame( array( 'merch_percent' => 6.0, 'ticket_percent' => 0.0 ), ec_commerce_fee_update_override( 'seller', self::VENUE, array( 'merch_percent' => 6 ) ) );
		$this->assertSame( array( 'merch_percent' => 6.0 ), ec_commerce_fee_update_override( 'seller', self::VENUE, array( 'ticket_percent' => null ) ) );
		$this->assertSame( array(), ec_commerce_fee_update_override( 'seller', self::VENUE, array( 'merch_percent' => null ) ) );
		$this->assertArrayNotHasKey( EC_COMMERCE_FEE_SELLER_OPTION_PREFIX . 'venue:9911', $GLOBALS['test_blog_options'][3] ?? array(), 'An empty override is deleted, not stored.' );

		ec_commerce_fee_update_override( 'event', self::EVENT, array( 'ticket_buyer_fee_per_ticket_minor' => 0 ) );
		$this->assertSame( array( 'ticket_buyer_fee_per_ticket_minor' => 0 ), $GLOBALS['test_post_meta'][ self::EVENT ][ EC_COMMERCE_FEE_EVENT_META ] );
		$this->assertTrue( ec_commerce_fee_delete_override( 'event', self::EVENT ) );
		$this->assertSame( array(), ec_commerce_fee_get_override( 'event', self::EVENT ) );
		$this->assertFalse( ec_commerce_fee_update_override( 'seller', array( 'type' => 'label', 'id' => 1 ), array( 'merch_percent' => 1 ) ) );
		$this->assertFalse( ec_commerce_fee_delete_override( 'product', 5 ) );
		$this->assertSame( array(), $GLOBALS['test_blog_stack'] );
	}

	public function test_abilities_are_registered_for_network_admins_only(): void {
		$GLOBALS['test_abilities'] = array();
		extrachill_shop_register_fee_override_abilities();
		foreach ( array( 'extrachill/get-commerce-fee-override', 'extrachill/set-commerce-fee-override', 'extrachill/clear-commerce-fee-override' ) as $name ) {
			$this->assertSame( 'extrachill_shop_fee_override_permission', $GLOBALS['test_abilities'][ $name ]['permission_callback'] ?? null, $name );
			$this->assertFalse( $GLOBALS['test_abilities'][ $name ]['input_schema']['additionalProperties'] );
		}
		$fees = $GLOBALS['test_abilities']['extrachill/set-commerce-fee-override']['input_schema']['properties']['fees'];
		$this->assertSame( array_keys( ec_commerce_fee_fields() ), array_keys( $fees['properties'] ) );
		$this->assertSame( 50.0, $fees['properties']['processing_percent']['maximum'] );

		$this->assertTrue( extrachill_shop_fee_override_permission() );
		foreach ( array( array(), array( 'manage_woocommerce' ), array( 'manage_options' ) ) as $caps ) {
			$GLOBALS['operator_caps'] = $caps;
			$this->assertSame( 'forbidden', extrachill_shop_fee_override_permission()->get_error_code(), 'Shop operators and sellers cannot set fees.' );
		}
	}

	public function test_set_get_and_clear_an_event_override(): void {
		$result = extrachill_shop_ability_set_fee_override( array( 'scope' => 'event', 'event_id' => self::EVENT, 'fees' => self::ZERO ) );
		$this->assertSame( 'event', $result['scope'] );
		$this->assertSame( self::EVENT, $result['event_id'] );
		$this->assertSame( self::VENUE, $result['seller'], 'Resolved with the ticket seller checkout uses.' );
		$this->assertSame( ec_commerce_fee_sanitize_override( self::ZERO ), $result['override'] );
		$this->assertCount( 7, $result['override'], 'A zero is an override, not "inherit".' );
		$this->assertSame( ec_commerce_fee_sanitize( self::ZERO, array() ), $result['resolved'] );
		$this->assertSame( ec_commerce_fee_global_settings(), $result['global'] );

		$this->assertSame( $result, extrachill_shop_ability_get_fee_override( array( 'scope' => 'event', 'event_id' => self::EVENT ) ) );

		$cleared = extrachill_shop_ability_clear_fee_override( array( 'scope' => 'event', 'event_id' => self::EVENT ) );
		$this->assertSame( array(), $cleared['override'] );
		$this->assertSame( $cleared['global'], $cleared['resolved'], 'Cleared: every field inherits.' );
	}

	public function test_set_a_seller_override_partially(): void {
		extrachill_shop_ability_set_fee_override( array( 'scope' => 'seller', 'seller' => self::VENUE, 'fees' => array( 'ticket_percent' => 2.5 ) ) );
		$result = extrachill_shop_ability_set_fee_override( array( 'scope' => 'seller', 'seller' => self::VENUE, 'fees' => array( 'merch_percent' => 8 ) ) );
		$this->assertSame( array( 'merch_percent' => 8.0, 'ticket_percent' => 2.5 ), $result['override'] );
		$this->assertSame( 2.5, $result['resolved']['ticket_percent'] );
		$this->assertSame( null, $result['event_id'] );
	}

	public function test_set_rejects_bad_targets_and_fees(): void {
		$cases = array(
			'invalid_fee_override_scope'  => array( 'scope' => 'product', 'fees' => array( 'merch_percent' => 1 ) ),
			'invalid_fee_override_target' => array( 'scope' => 'event', 'seller' => self::VENUE, 'fees' => array( 'merch_percent' => 1 ) ),
			'event_not_found'             => array( 'scope' => 'event', 'event_id' => 202, 'fees' => array( 'merch_percent' => 1 ) ),
			'invalid_seller'              => array( 'scope' => 'seller', 'seller' => array( 'type' => 'venue', 'id' => '5' ), 'fees' => array( 'merch_percent' => 1 ) ),
			'invalid_fee_override'        => array( 'scope' => 'seller', 'seller' => self::VENUE, 'fees' => array( 'bogus' => 1 ) ),
		);
		foreach ( $cases as $code => $input ) {
			$this->assertSame( $code, extrachill_shop_ability_set_fee_override( $input )->get_error_code(), $code );
		}
		$this->assertSame( 'invalid_fee_override', extrachill_shop_ability_set_fee_override( array( 'scope' => 'seller', 'seller' => self::VENUE, 'fees' => array( 'merch_percent' => -1 ) ) )->get_error_code() );
		$this->assertSame( 'invalid_fee_override', extrachill_shop_ability_set_fee_override( array( 'scope' => 'seller', 'seller' => self::VENUE, 'fees' => array() ) )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_post_meta'] );
		$this->assertSame( array(), $GLOBALS['test_blog_options'] );
	}

	public function test_unknown_seller_is_not_stored(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'commerce_seller_not_found', 'Seller not found.' );
		};
		$this->assertSame( 'commerce_seller_not_found', extrachill_shop_ability_set_fee_override( array( 'scope' => 'seller', 'seller' => array( 'type' => 'promoter', 'id' => 77 ), 'fees' => array( 'merch_percent' => 1 ) ) )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_blog_options'] );
	}

	public function test_network_payments_page_edits_the_global_settings_only(): void {
		$page = file_get_contents( dirname( __DIR__, 2 ) . '/extrachill-network/admin/network-payments-settings.php' );
		$this->assertStringContainsString( 'ec_commerce_fee_sanitize( $submitted_fees, ec_commerce_fee_global_settings() )', $page );
		$this->assertStringNotContainsString( 'ec_commerce_fee_settings(', $page, 'Overrides and filters never leak into the saved global setting.' );
	}
}
