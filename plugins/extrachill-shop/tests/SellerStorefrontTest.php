<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** Seller storefronts: `/<type>/<slug>/` paths, slug resolution, legacy `?seller=` redirects. */
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/shop-manager.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-taxonomy.php';
require_once dirname( __DIR__ ) . '/inc/core/seller-storefront.php';

final class SellerStorefrontTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_site_transients'] = array();
		$GLOBALS['test_owner_calls']     = array();
		$GLOBALS['test_query_vars']      = array();
		$GLOBALS['test_artist_slugs']    = array();
		$GLOBALS['test_user_id']         = 0;
		unset( $GLOBALS['test_owner_callback'] );
	}

	private function lofi_owner(): void {
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
			$input = $args['query']['input'] ?? array();
			if ( 'venue' !== ( $input['type'] ?? '' ) || ( 1524 !== ( $input['id'] ?? 0 ) && 'lo-fi-brewing' !== ( $input['slug'] ?? '' ) ) ) {
				return new WP_Error( 'commerce_seller_not_found', 'Seller not found.' );
			}
			return array(
				'type'                => 'venue',
				'id'                  => 1524,
				'name'                => 'Lo-Fi Brewing',
				'slug'                => 'lo-fi-brewing',
				'url'                 => 'https://events.extrachill.com/venue/lo-fi-brewing/',
				'manage_url'          => 'https://events.extrachill.com/venue-settings/?venue_id=1524',
				'can_manage_finances' => false,
			);
		};
	}

	public function test_storefront_urls_are_type_and_slug_paths(): void {
		foreach ( array( 'artist' => 'extra-chill', 'venue' => 'lo-fi-brewing', 'promoter' => 'real-south' ) as $type => $slug ) {
			$this->assertSame(
				'https://shop.extrachill.com/' . $type . '/' . $slug . '/',
				extrachill_shop_get_seller_store_url( array( 'type' => $type, 'id' => 7, 'slug' => $slug ) )
			);
		}
		$this->assertSame( '', extrachill_shop_get_seller_store_url( array( 'type' => 'label', 'id' => 3, 'slug' => 'x' ) ) );
	}

	public function test_slug_is_filled_from_public_identity_when_missing(): void {
		$this->lofi_owner();
		$this->assertSame(
			'https://shop.extrachill.com/venue/lo-fi-brewing/',
			extrachill_shop_get_seller_store_url( array( 'type' => 'venue', 'id' => 1524 ) )
		);
		$this->assertArrayHasKey( 'ec_shop_public_seller_v2_venue_1524', $GLOBALS['test_site_transients'] );
		$this->assertSame( '', extrachill_shop_get_seller_store_url( array( 'type' => 'venue', 'id' => 9 ) ) );
	}

	public function test_storefront_path_resolves_venue_slug_through_events_and_caches(): void {
		$this->lofi_owner();
		$GLOBALS['test_query_vars'] = array( 'ec_shop_seller_type' => 'venue', 'ec_shop_seller_slug' => 'lo-fi-brewing' );

		$this->assertSame( array( 'type' => 'venue', 'id' => 1524 ), extrachill_shop_get_requested_storefront_seller() );
		$this->assertSame( '/wp-abilities/v1/abilities/extrachill/get-commerce-seller/run', $GLOBALS['test_owner_calls'][0]['route'] );
		$this->assertSame( array( 'type' => 'venue', 'slug' => 'lo-fi-brewing' ), $GLOBALS['test_owner_calls'][0]['args']['query']['input'] );
		$this->assertSame( array( 'type' => 'venue', 'id' => 1524 ), $GLOBALS['test_site_transients'][ 'ec_shop_seller_slug_venue_' . md5( 'lo-fi-brewing' ) ] );
	}

	public function test_storefront_path_resolves_artist_profile_slug(): void {
		$GLOBALS['test_artist_slugs']['extra-chill'] = array( 'id' => 12114, 'permalink' => 'https://artist.extrachill.com/artists/extra-chill' );
		$this->assertSame( array( 'type' => 'artist', 'id' => 12114 ), extrachill_shop_resolve_storefront_slug( 'artist', 'extra-chill' ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
	}

	public function test_unknown_slug_resolves_to_nothing_and_caches_the_miss(): void {
		$this->lofi_owner();
		$this->assertNull( extrachill_shop_resolve_storefront_slug( 'promoter', 'nobody' ) );
		$this->assertSame( 'none', $GLOBALS['test_site_transients'][ 'ec_shop_seller_slug_promoter_' . md5( 'nobody' ) ] );
		$this->assertNull( extrachill_shop_resolve_storefront_slug( 'label', 'lo-fi-brewing' ) );
	}

	public function test_legacy_seller_links_redirect_to_paths(): void {
		$this->lofi_owner();
		$this->assertSame( 'https://shop.extrachill.com/venue/lo-fi-brewing/', extrachill_shop_storefront_legacy_redirect_url( array( 'seller' => 'venue:1524', 'paged' => '3' ) ) );
		$this->assertSame( 'https://shop.extrachill.com/venue/lo-fi-brewing/?sort=price-asc', extrachill_shop_storefront_legacy_redirect_url( array( 'seller' => 'venue:1524', 'sort' => 'price-asc' ) ) );
		$this->assertSame( 'https://shop.extrachill.com/?sort=random', extrachill_shop_storefront_legacy_redirect_url( array( 'seller' => '', 'sort' => 'random' ) ) );
		$this->assertSame( '', extrachill_shop_storefront_legacy_redirect_url( array( 'seller' => 'venue:999' ) ) );
		$this->assertSame( '', extrachill_shop_storefront_legacy_redirect_url( array( 'sort' => 'random' ) ) );
	}

	public function test_products_meta_query_matches_how_each_seller_is_stored(): void {
		$artist = extrachill_shop_seller_products_meta_query( array( 'type' => 'artist', 'id' => 12 ) );
		$this->assertSame( array( '_artist_profile_id', 12 ), array( $artist[0]['key'], $artist[0]['value'] ) );
		$venue = extrachill_shop_seller_products_meta_query( array( 'type' => 'venue', 'id' => 1524 ) );
		$this->assertSame( array( '_extrachill_shop_seller_type', 'venue' ), array( $venue[0]['key'], $venue[0]['value'] ) );
		$this->assertSame( array( '_extrachill_shop_seller_id', 1524 ), array( $venue[1]['key'], $venue[1]['value'] ) );
	}

	public function test_public_identity_carries_slug_and_is_cached(): void {
		$this->lofi_owner();
		$this->assertSame(
			array( 'type' => 'venue', 'id' => 1524, 'name' => 'Lo-Fi Brewing', 'slug' => 'lo-fi-brewing', 'url' => 'https://events.extrachill.com/venue/lo-fi-brewing/' ),
			extrachill_shop_get_public_seller( array( 'type' => 'venue', 'id' => 1524 ) )
		);
	}
}
