<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller storefronts: one `?seller=type:id` URL shape, seller filters, public identity. */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-taxonomy.php';

final class SellerStorefrontTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_site_transients'] = array();
		$GLOBALS['test_owner_calls']     = array();
		$GLOBALS['test_user_id']         = 0;
		unset( $GLOBALS['test_owner_callback'] );
	}

	public function test_every_seller_type_gets_a_seller_keyed_storefront(): void {
		foreach ( array( 'artist' => 12, 'venue' => 1524, 'promoter' => 8 ) as $type => $id ) {
			$this->assertSame(
				'https://shop.extrachill.com/?seller=' . $type . ':' . $id,
				extrachill_shop_get_seller_store_url( array( 'type' => $type, 'id' => $id ) )
			);
		}
		$this->assertSame( '', extrachill_shop_get_seller_store_url( array( 'type' => 'label', 'id' => 3 ) ) );
		$this->assertSame( '', extrachill_shop_get_seller_store_url( array( 'type' => 'venue', 'id' => 0 ) ) );
		$this->assertStringNotContainsString( '?artist=', extrachill_shop_get_seller_store_url( array( 'type' => 'artist', 'id' => 12 ) ) );
	}

	public function test_requested_storefront_seller_parses_strictly(): void {
		$_GET['seller'] = 'venue:1524';
		$this->assertSame( array( 'type' => 'venue', 'id' => 1524 ), extrachill_shop_get_requested_storefront_seller() );
		$_GET['seller'] = 'venue:abc';
		$this->assertNull( extrachill_shop_get_requested_storefront_seller() );
		unset( $_GET['seller'] );
		$this->assertNull( extrachill_shop_get_requested_storefront_seller() );
	}

	public function test_products_meta_query_matches_how_each_seller_is_stored(): void {
		$artist = extrachill_shop_seller_products_meta_query( array( 'type' => 'artist', 'id' => 12 ) );
		$this->assertSame( '_artist_profile_id', $artist[0]['key'] );
		$this->assertSame( 12, $artist[0]['value'] );

		$venue = extrachill_shop_seller_products_meta_query( array( 'type' => 'venue', 'id' => 1524 ) );
		$this->assertSame( array( '_extrachill_shop_seller_type', 'venue' ), array( $venue[0]['key'], $venue[0]['value'] ) );
		$this->assertSame( array( '_extrachill_shop_seller_id', 1524 ), array( $venue[1]['key'], $venue[1]['value'] ) );
	}

	public function test_public_venue_identity_resolves_anonymously_and_is_cached(): void {
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
			return array(
				'type'                => 'venue',
				'id'                  => 1524,
				'name'                => 'Lo-Fi Brewing',
				'url'                 => 'https://events.extrachill.com/venue/lo-fi-brewing/',
				'manage_url'          => 'https://events.extrachill.com/venue-settings/?venue_id=1524',
				'can_manage_finances' => false,
			);
		};

		$expected = array(
			'type' => 'venue',
			'id'   => 1524,
			'name' => 'Lo-Fi Brewing',
			'url'  => 'https://events.extrachill.com/venue/lo-fi-brewing/',
		);
		$this->assertSame( $expected, extrachill_shop_get_public_seller( array( 'type' => 'venue', 'id' => 1524 ) ) );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/get-commerce-seller/run', $GLOBALS['test_owner_calls'][0]['route'] );
		$this->assertSame( $expected, $GLOBALS['test_site_transients']['ec_shop_public_seller_venue_1524'] );
	}

	public function test_unknown_seller_has_no_public_identity(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return new WP_Error( 'commerce_seller_not_found', 'Seller not found.' );
		};
		$this->assertNull( extrachill_shop_get_public_seller( array( 'type' => 'promoter', 'id' => 99 ) ) );
		$this->assertArrayNotHasKey( 'ec_shop_public_seller_promoter_99', $GLOBALS['test_site_transients'] );
	}
}
