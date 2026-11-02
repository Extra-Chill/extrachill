<?php
/**
 * Tests for typed sellers in product ownership, authorization, and checkout.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['test_post_meta'][ $post_id ][ $key ] ?? '';
	}
	function update_post_meta( $post_id, $key, $value ) {
		$GLOBALS['test_post_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
	function delete_post_meta( $post_id, $key ) {
		unset( $GLOBALS['test_post_meta'][ $post_id ][ $key ] );
		return true;
	}
}
require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
require_once dirname( __DIR__ ) . '/inc/core/artist-product-meta.php';
require_once dirname( __DIR__ ) . '/inc/stripe/checkout-handler.php';

final class SellerCommerceTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_post_meta']      = array();
		$GLOBALS['test_owner_calls']    = array();
		$GLOBALS['test_user_id']        = 5;
		$GLOBALS['test_events']         = array(
			'venue:1524'  => array(
				'name'                => 'Lo-Fi Brewing',
				'can_manage_finances' => true,
			),
			'promoter:88' => array(
				'name'                => 'Real South',
				'can_manage_finances' => false,
			),
		);
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
			if ( 'events' === $site && str_contains( $route, 'extrachill/get-commerce-seller' ) ) {
				$input = $args['query']['input'];
				$known = $GLOBALS['test_events'][ $input['type'] . ':' . $input['id'] ] ?? null;
				return $known ? array_merge( $input, array( 'url' => 'https://events.extrachill.com/x/' ), $known ) : new WP_Error( 'commerce_seller_not_found', 'Seller not found.' );
			}
			return new WP_Error( 'unexpected_owner_call', $route );
		};
	}

	public function test_seller_input_accepts_typed_seller_or_legacy_artist(): void {
		$this->assertSame(
			array(
				'type' => 'artist',
				'id'   => 9,
			),
			extrachill_shop_seller_from_input( array( 'artist_id' => 9 ) )
		);
		$this->assertSame(
			array(
				'type' => 'venue',
				'id'   => 1524,
			),
			extrachill_shop_seller_from_input(
				array(
					'seller' => array(
						'type' => 'venue',
						'id'   => 1524,
					),
				)
			)
		);
		$this->assertSame( 'missing_seller', extrachill_shop_seller_from_input( array() )->get_error_code() );
		$this->assertSame(
			'invalid_seller',
			extrachill_shop_seller_from_input(
				array(
					'seller' => array(
						'type' => 'label',
						'id'   => 1,
					),
				)
			)->get_error_code()
		);
	}

	public function test_venue_and_promoter_authority_comes_from_events_owner(): void {
		$this->assertTrue(
			extrachill_shop_current_user_can_manage_seller(
				array(
					'type' => 'venue',
					'id'   => 1524,
				)
			)
		);
		$this->assertSame(
			'cannot_manage_seller',
			extrachill_shop_current_user_can_manage_seller(
				array(
					'type' => 'promoter',
					'id'   => 88,
				)
			)->get_error_code()
		);
		$this->assertInstanceOf(
			WP_Error::class,
			extrachill_shop_current_user_can_manage_seller(
				array(
					'type' => 'venue',
					'id'   => 4040,
				)
			)
		);
		$this->assertSame( 'events', $GLOBALS['test_owner_calls'][0]['site'] );
		$this->assertSame( 5, $GLOBALS['test_owner_calls'][0]['args']['user_id'] );
	}

	public function test_product_seller_storage_round_trips_and_keeps_artist_meta(): void {
		$this->assertTrue(
			extrachill_shop_set_product_seller(
				10,
				array(
					'type' => 'artist',
					'id'   => 9,
				)
			)
		);
		$this->assertSame( array( '_artist_profile_id' => 9 ), $GLOBALS['test_post_meta'][10] );
		$this->assertSame(
			array(
				'type' => 'artist',
				'id'   => 9,
			),
			extrachill_shop_get_product_seller( 10 )
		);

		$this->assertTrue(
			extrachill_shop_set_product_seller(
				10,
				array(
					'type' => 'venue',
					'id'   => 1524,
				)
			)
		);
		$this->assertArrayNotHasKey( '_artist_profile_id', $GLOBALS['test_post_meta'][10] );
		$this->assertSame(
			array(
				'type' => 'venue',
				'id'   => 1524,
			),
			extrachill_shop_get_product_seller( 10 )
		);

		$this->assertFalse(
			extrachill_shop_set_product_seller(
				10,
				array(
					'type' => 'venue',
					'id'   => '1524',
				)
			)
		);
		$this->assertFalse( extrachill_shop_get_product_seller( 11 ) );
	}

	public function test_checkout_groups_and_charges_artist_exactly_as_before_and_venue_separately(): void {
		update_post_meta( 1, '_artist_profile_id', 9 );
		extrachill_shop_set_product_seller(
			2,
			array(
				'type' => 'venue',
				'id'   => 1524,
			)
		);
		extrachill_shop_set_stripe_account_record( 9, array( 'account_id' => 'acct_artist' ) );
		extrachill_shop_set_seller_stripe_account_record(
			array(
				'type' => 'venue',
				'id'   => 1524,
			),
			array( 'account_id' => 'acct_venue' )
		);

		$grouped = extrachill_shop_group_cart_by_artist(
			array(
				'a' => array(
					'product_id' => 1,
					'line_total' => 20.0,
				),
				'b' => array(
					'product_id' => 2,
					'line_total' => 30.0,
				),
				'c' => array(
					'product_id' => 3,
					'line_total' => 5.0,
				),
			)
		);
		$this->assertSame( array( 9, 'venue:1524', 0 ), array_keys( $grouped ) );
		$this->assertSame( array( 'artist_id', 'items', 'total' ), array_keys( $grouped[9] ) );
		$this->assertSame(
			array(
				'type' => 'venue',
				'id'   => 1524,
			),
			$grouped['venue:1524']['seller']
		);

		$charges = extrachill_shop_calculate_artist_charges( $grouped );
		$this->assertSame( array( 9, 'venue:1524' ), array_keys( $charges ) );
		$this->assertSame(
			array(
				'artist_id'       => 9,
				'stripe_account'  => 'acct_artist',
				'total'           => 20.0,
				'application_fee' => 2.0,
				'artist_payout'   => 18.0,
				'items'           => $grouped[9]['items'],
			),
			$charges[9]
		);
		$this->assertSame( 'acct_venue', $charges['venue:1524']['stripe_account'] );
		$this->assertSame( 27.0, $charges['venue:1524']['artist_payout'] );
		$this->assertSame( 0, $charges['venue:1524']['artist_id'] );
	}
}
