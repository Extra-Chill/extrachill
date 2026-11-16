<?php
/**
 * Tests for Shop-owned commerce state and architecture boundaries.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

final class CommerceStateMigrationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_owner_calls']    = array();
		$GLOBALS['test_assertions']     = array();
		$GLOBALS['test_owner_callback'] = static function () {
			$response                            = priority_boost_owner_response();
			$response['receipt']['operation_id'] = 'migrated';
			return $response;
		};
	}

	private static function artist( int $id ): array {
		return array(
			'type' => 'artist',
			'id'   => $id,
		);
	}

	public function test_typed_seller_records_do_not_collide(): void {
		foreach ( array( 'artist', 'venue', 'promoter' ) as $type ) {
			$seller = array(
				'type' => $type,
				'id'   => 9,
			);
			$this->assertTrue( extrachill_shop_set_seller_stripe_account_record( $seller, array( 'account_id' => 'acct_' . $type ) ) );
			$this->assertSame( $seller, extrachill_shop_get_seller_stripe_account_record( $seller )['seller'] );
			$this->assertTrue( extrachill_shop_update_stripe_account_by_id( 'acct_' . $type, 'active', true ) );
			$this->assertSame( 'active', extrachill_shop_get_seller_stripe_account_record( $seller )['status'] );
		}
		$this->assertSame( 'acct_artist', extrachill_shop_get_seller_stripe_account_record( self::artist( 9 ) )['account_id'] );
	}

	public function test_artist_era_option_and_integer_index_are_ignored(): void {
		// The legacy per-artist option and integer reverse index are gone (#1401).
		update_option( 'extrachill_shop_stripe_artist_9', array( 'account_id' => 'acct_old', 'status' => 'pending' ) );
		update_option( 'extrachill_shop_stripe_account_' . hash( 'sha256', 'acct_old' ), 9 );
		$this->assertFalse( extrachill_shop_get_seller_stripe_account_record( self::artist( 9 ) ) );
		$this->assertFalse( get_option( 'extrachill_shop_stripe_seller_artist:9', false ) );
		$this->assertFalse( extrachill_shop_update_stripe_account_by_id( 'acct_old', 'active', true ) );
	}

	public function test_invalid_sellers_and_conflicting_bindings_are_rejected(): void {
		foreach ( array(
			null,
			array(),
			array(
				'type' => 'venue',
				'id'   => -1,
			),
			array(
				'type' => 'venue',
				'id'   => '9',
			),
			array(
				'type' => 'other',
				'id'   => 9,
			),
		) as $seller ) {
			$this->assertInstanceOf( WP_Error::class, extrachill_shop_validate_seller_ref( $seller ) );
			$this->assertFalse( extrachill_shop_set_seller_stripe_account_record( $seller, array( 'account_id' => 'acct_invalid' ) ) );
		}
		$this->assertTrue( extrachill_shop_set_seller_stripe_account_record( self::artist( 9 ), array( 'account_id' => 'acct_one' ) ) );
		$this->assertFalse( extrachill_shop_set_seller_stripe_account_record( array(
			'type' => 'venue',
			'id'   => 9,
		), array( 'account_id' => 'acct_one' ) ) );
		$this->assertFalse( extrachill_shop_set_seller_stripe_account_record( self::artist( 9 ), array( 'account_id' => 'acct_two' ) ) );
		$this->assertSame( 'acct_one', extrachill_shop_get_seller_stripe_account_record( self::artist( 9 ) )['account_id'] );
	}

	public function test_source_grant_is_exact_and_configuration_driven(): void {
		$secret = str_repeat( 's', 32 );
		$grant  = extrachill_shop_priority_boost_build_source_grant(
			array(
				'source_site_id' => 3,
				'target_site_id' => 7,
				'target_host'    => 'EVENTS.EXTRACHILL.COM',
				'active_key_id'  => 'current',
				'keys'           => array( 'current' => $secret ),
			)
		);

		$this->assertSame( 'extrachill.events.priority-boost', $grant['service_id'] );
		$this->assertSame( 'extrachill/events:priority-boost', $grant['scope'] );
		$this->assertSame( 'POST', $grant['method'] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/grant-event-priority-boost/run', $grant['route'] );
		$this->assertSame( 'events.extrachill.com', $grant['target_host'] );
		$this->assertSame( 'current', $grant['active_key_id'] );
		$this->assertSame( $secret, $grant['keys']['current'] );
	}

	public function test_unconfigured_or_wrong_source_grant_fails_closed(): void {
		$this->assertNull(
			extrachill_shop_priority_boost_build_source_grant(
				array(
					'source_site_id' => 3,
					'target_site_id' => 7,
					'target_host'    => 'events.extrachill.com',
					'active_key_id'  => 'missing',
					'keys'           => array( 'current' => str_repeat( 's', 32 ) ),
				)
			)
		);
		// Keys removed (e.g. an override clearing them) means no grant at all.
		add_filter( 'extrachill_shop_priority_boost_service_assertion_keys', static function () {
			return array();
		} );
		$grants = extrachill_shop_register_priority_boost_source_grant( array() );
		unset( $GLOBALS['test_filters']['extrachill_shop_priority_boost_service_assertion_keys'] );
		$this->assertSame( array(), $grants );
	}

	public function test_canonical_artist_identity_uses_artist_read_contract(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return array(
				'id'   => 9,
				'name' => 'Canonical Artist',
				'slug' => 'canonical-artist',
			);
		};

		$artist = extrachill_shop_get_canonical_artist( 9 );

		$this->assertSame( 9, $artist['id'] );
		$this->assertSame( 'artist', $GLOBALS['test_owner_calls'][0]['site'] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/artist-get/run', $GLOBALS['test_owner_calls'][0]['route'] );
	}

	public function test_public_stripe_status_omits_raw_account_reference(): void {
		extrachill_shop_set_seller_stripe_account_record( self::artist( 12 ), array( 'account_id' => 'acct_sensitive' ) );
		$GLOBALS['test_stripe_status'] = array(
			'success'              => true,
			'status'               => 'active',
			'can_receive_payments' => true,
			'charges_enabled'      => true,
			'payouts_enabled'      => true,
			'details_submitted'    => true,
		);

		$result = extrachill_shop_ability_stripe_status( array(
			'seller' => array(
				'type' => 'artist',
				'id'   => 12,
			),
		) );

		$this->assertArrayNotHasKey( 'account_id', $result );
		$this->assertTrue( $result['can_receive_payments'] );
	}

	public function test_live_paths_contain_no_direct_artist_or_events_writes(): void {
		$root   = dirname( __DIR__ );
		$files  = array(
			$root . '/inc/stripe/stripe-connect.php',
			$root . '/inc/abilities/shop-stripe-status.php',
			$root . '/inc/abilities/shop-stripe-dashboard-link.php',
			$root . '/inc/products/priority-boost.php',
		);
		$source = '';
		foreach ( $files as $file ) {
			$source .= file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source.
		}

		$this->assertStringNotContainsString( 'switch_to_blog(', $source );
		$this->assertStringNotContainsString( 'update_post_meta( $event_id', $source );
		$this->assertStringNotContainsString( "'_stripe_connect_account_id'", $source );
		$this->assertStringNotContainsString( "wp_cache_delete( 'extrachill_priority_event_ids'", $source );

		$artist_products = file_get_contents( $root . '/inc/core/artist-product-meta.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads local plugin source.
		$this->assertStringNotContainsString( 'wp_set_object_terms(', $artist_products );
	}
}
