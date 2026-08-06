<?php
/**
 * Tests for Shop-owned state migration and architecture boundaries.
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
		$GLOBALS['test_owner_callback'] = static function () {
			return array( 'receipt' => array( 'operation_id' => 'migrated' ) );
		};
	}

	public function test_stripe_migration_is_dry_run_then_idempotent(): void {
		$legacy = array( 'account_id' => 'acct_legacy', 'status' => 'active', 'onboarding_complete' => true );

		$this->assertSame( 'pending', extrachill_shop_migrate_stripe_record( 9, $legacy, false ) );
		$this->assertFalse( extrachill_shop_get_stripe_account_record( 9 ) );
		$this->assertSame( 'imported', extrachill_shop_migrate_stripe_record( 9, $legacy, true ) );
		$this->assertSame( 'unchanged', extrachill_shop_migrate_stripe_record( 9, $legacy, true ) );
		$this->assertSame( 'acct_legacy', extrachill_shop_get_stripe_account_record( 9 )['account_id'] );
	}

	public function test_stripe_migration_rejects_conflicting_replay(): void {
		extrachill_shop_migrate_stripe_record( 9, array( 'account_id' => 'acct_one' ), true );

		$this->assertSame( 'conflict', extrachill_shop_migrate_stripe_record( 9, array( 'account_id' => 'acct_two' ), true ) );
		$this->assertSame( 'acct_one', extrachill_shop_get_stripe_account_record( 9 )['account_id'] );
	}

	public function test_paid_boost_migration_uses_owner_contract(): void {
		$order = new PriorityBoostTestOrder( 44, array() );
		$item  = new PriorityBoostTestItem( 10, 101, '', 'Legacy event' );

		$this->assertSame( 'pending', extrachill_shop_migrate_priority_boost_item( $order, $item, false ) );
		$this->assertSame( 'migrated', extrachill_shop_migrate_priority_boost_item( $order, $item, true ) );
		$this->assertSame( 'unchanged', extrachill_shop_migrate_priority_boost_item( $order, $item, true ) );
		$this->assertSame( '101', $GLOBALS['test_owner_calls'][0]['args']['body']['input']['event'] );
	}

	public function test_canonical_artist_identity_uses_artist_read_contract(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return array( 'id' => 9, 'name' => 'Canonical Artist', 'slug' => 'canonical-artist' );
		};

		$artist = extrachill_shop_get_canonical_artist( 9 );

		$this->assertSame( 9, $artist['id'] );
		$this->assertSame( 'artist', $GLOBALS['test_owner_calls'][0]['site'] );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/artist-get/run', $GLOBALS['test_owner_calls'][0]['route'] );
	}

	public function test_public_stripe_status_omits_raw_account_reference(): void {
		extrachill_shop_set_stripe_account_record( 12, array( 'account_id' => 'acct_sensitive' ) );
		$GLOBALS['test_stripe_status'] = array(
			'success'              => true,
			'status'               => 'active',
			'can_receive_payments' => true,
			'charges_enabled'      => true,
			'payouts_enabled'      => true,
			'details_submitted'    => true,
		);

		$result = extrachill_shop_ability_stripe_status( array( 'artist_id' => 12 ) );

		$this->assertArrayNotHasKey( 'account_id', $result );
		$this->assertTrue( $result['can_receive_payments'] );
	}

	public function test_live_paths_contain_no_direct_artist_or_events_writes(): void {
		$root  = dirname( __DIR__ );
		$files = array(
			$root . '/inc/stripe/stripe-connect.php',
			$root . '/inc/abilities/shop-stripe-status.php',
			$root . '/inc/abilities/shop-stripe-dashboard-link.php',
			$root . '/inc/products/priority-boost.php',
		);
		$source = '';
		foreach ( $files as $file ) {
			$source .= file_get_contents( $file );
		}

		$this->assertStringNotContainsString( 'switch_to_blog(', $source );
		$this->assertStringNotContainsString( 'update_post_meta( $event_id', $source );
		$this->assertStringNotContainsString( "'_stripe_connect_account_id'", $source );
		$this->assertStringNotContainsString( "wp_cache_delete( 'extrachill_priority_event_ids'", $source );

		$artist_products = file_get_contents( $root . '/inc/core/artist-product-meta.php' );
		$this->assertStringNotContainsString( 'wp_set_object_terms(', $artist_products );
	}
}
