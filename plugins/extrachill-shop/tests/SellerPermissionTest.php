<?php
/**
 * Tests for the seller permission primitive.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName,Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/SellerCommerceTest.php';
require_once dirname( __DIR__ ) . '/inc/abilities/product-write-helpers.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-create-product.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-update-product.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-delete-product.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-get-product.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-upload-product-image.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-delete-product-image.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-list-shipping-labels.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-get-shipping-label.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-refund-order.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-onboarding-link.php';
require_once dirname( __DIR__ ) . '/inc/abilities/shop-stripe-dashboard-link.php';

final class SellerPermissionTest extends TestCase {
	private const MEMBER   = 21;
	private const OUTSIDER = 22;
	private const ADMIN    = 23;
	private const GATED    = 24;

	protected function setUp(): void {
		extrachill_shop_reset_seller_permission_cache();
		$GLOBALS['test_options']        = array();
		$GLOBALS['test_post_meta']      = array();
		$GLOBALS['test_posts']          = array();
		$GLOBALS['test_owner_calls']    = array();
		$GLOBALS['test_artist_checks']  = array();
		$GLOBALS['test_feature_checks'] = array();
		$GLOBALS['test_admins']         = array( self::ADMIN );
		$GLOBALS['test_feature_denied'] = array( self::GATED );
		$GLOBALS['test_artist_members'] = array(
			self::MEMBER => array( 9 ),
			self::GATED  => array( 9 ),
		);
		// Events reports finance authority per user: MEMBER and GATED run venue 1524 and promoter 88.
		$GLOBALS['test_owner_callback'] = static function ( $site, $method, $route, $args ) {
			if ( 'events' !== $site || ! str_contains( $route, 'extrachill/get-commerce-seller' ) ) {
				return new WP_Error( 'unexpected_owner_call', $route );
			}
			$input = $args['query']['input'];
			if ( ! in_array( $input['type'] . ':' . $input['id'], array( 'venue:1524', 'promoter:88' ), true ) ) {
				return new WP_Error( 'commerce_seller_not_found', 'Seller not found.' );
			}
			return array_merge(
				$input,
				array( 'can_manage_finances' => in_array( $args['user_id'], array( self::MEMBER, self::GATED ), true ) )
			);
		};
	}

	private function can( int $user_id, string $type, int $id ) {
		return extrachill_shop_can_manage_seller(
			$user_id,
			array(
				'type' => $type,
				'id'   => $id,
			)
		);
	}

	public function test_artist_member_allowed_and_outsider_denied(): void {
		$this->assertTrue( $this->can( self::MEMBER, 'artist', 9 ) );
		$this->assertSame( 'cannot_manage_seller', $this->can( self::OUTSIDER, 'artist', 9 )->get_error_code() );
		$this->assertSame( 'cannot_manage_seller', $this->can( self::MEMBER, 'artist', 10 )->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'], 'Artist membership is answered in-process.' );
	}

	public function test_venue_finance_authority_allowed_and_denied(): void {
		$this->assertTrue( $this->can( self::MEMBER, 'venue', 1524 ) );
		$this->assertSame( 'cannot_manage_seller', $this->can( self::OUTSIDER, 'venue', 1524 )->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $this->can( self::MEMBER, 'venue', 4040 ) );
		$this->assertSame( self::MEMBER, $GLOBALS['test_owner_calls'][0]['args']['user_id'], 'Events is asked as the user being checked.' );
		$this->assertSame( self::OUTSIDER, $GLOBALS['test_owner_calls'][1]['args']['user_id'] );
	}

	public function test_promoter_finance_authority_allowed_and_denied(): void {
		$this->assertTrue( $this->can( self::MEMBER, 'promoter', 88 ) );
		$this->assertSame( 'cannot_manage_seller', $this->can( self::OUTSIDER, 'promoter', 88 )->get_error_code() );
	}

	public function test_owner_answer_for_a_different_seller_is_refused(): void {
		$GLOBALS['test_owner_callback'] = static function () {
			return array(
				'type'                => 'venue',
				'id'                  => 1,
				'can_manage_finances' => true,
			);
		};
		$this->assertSame( 'seller_not_found', $this->can( self::MEMBER, 'venue', 1524 )->get_error_code() );
	}

	public function test_admin_manages_every_seller_without_owner_calls(): void {
		$this->assertTrue( $this->can( self::ADMIN, 'artist', 9 ) );
		$this->assertTrue( $this->can( self::ADMIN, 'venue', 4040 ) );
		$this->assertTrue( $this->can( self::ADMIN, 'promoter', 88 ) );
		$this->assertSame( array(), $GLOBALS['test_owner_calls'] );
		$this->assertSame( array(), $GLOBALS['test_artist_checks'] );
	}

	public function test_feature_gate_is_enforced_for_members(): void {
		foreach ( array( array( 'artist', 9 ), array( 'venue', 1524 ), array( 'promoter', 88 ) ) as $ref ) {
			$this->assertSame( 'shop_unavailable', $this->can( self::GATED, $ref[0], $ref[1] )->get_error_code() );
		}
		$this->assertSame( array(), $GLOBALS['test_owner_calls'], 'Membership is not checked when the gate is closed.' );
		$this->assertContains( array( 'shop', self::GATED ), $GLOBALS['test_feature_checks'] );
	}

	public function test_logged_out_and_invalid_refs_are_refused(): void {
		$this->assertSame( 'rest_not_logged_in', $this->can( 0, 'artist', 9 )->get_error_code() );
		$this->assertSame( 'invalid_seller', $this->can( self::ADMIN, 'label', 9 )->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, extrachill_shop_can_manage_seller( self::ADMIN, null ) );
	}

	public function test_results_are_cached_per_request(): void {
		$this->can( self::MEMBER, 'venue', 1524 );
		$this->can( self::MEMBER, 'venue', 1524 );
		$this->can( self::MEMBER, 'artist', 9 );
		$this->can( self::MEMBER, 'artist', 9 );
		$this->assertCount( 1, $GLOBALS['test_owner_calls'] );
		$this->assertCount( 1, $GLOBALS['test_artist_checks'] );

		extrachill_shop_reset_seller_permission_cache();
		$this->can( self::MEMBER, 'venue', 1524 );
		$this->assertCount( 2, $GLOBALS['test_owner_calls'] );
	}

	public function test_seller_input_callback_uses_current_user(): void {
		$input                   = array(
			'seller' => array(
				'type' => 'venue',
				'id'   => 1524,
			),
		);
		$GLOBALS['test_user_id'] = self::MEMBER;
		$this->assertTrue( extrachill_shop_authorize_seller_input( $input ) );
		$GLOBALS['test_user_id'] = self::OUTSIDER;
		$this->assertSame( 'cannot_manage_seller', extrachill_shop_authorize_seller_input( $input )->get_error_code() );
		$this->assertSame( 'missing_seller', extrachill_shop_authorize_seller_input( array( 'artist_id' => 9 ) )->get_error_code() );
	}

	public function test_product_input_follows_the_products_seller(): void {
		extrachill_shop_set_product_seller(
			501,
			array(
				'type' => 'venue',
				'id'   => 1524,
			)
		);
		$GLOBALS['test_user_id'] = self::MEMBER;
		$this->assertTrue( extrachill_shop_authorize_product_input( array( 'id' => 501 ) ) );
		$GLOBALS['test_user_id'] = self::OUTSIDER;
		$this->assertSame( 'cannot_manage_seller', extrachill_shop_authorize_product_input( array( 'id' => 501 ) )->get_error_code() );
		$GLOBALS['test_user_id'] = self::ADMIN;
		$this->assertTrue( extrachill_shop_authorize_product_input( array( 'id' => 501 ) ) );
	}

	public function test_product_input_moving_to_another_seller_needs_both(): void {
		extrachill_shop_set_product_seller(
			502,
			array(
				'type' => 'artist',
				'id'   => 9,
			)
		);
		$GLOBALS['test_user_id'] = self::MEMBER;
		$this->assertTrue(
			extrachill_shop_authorize_product_input(
				array(
					'id'     => 502,
					'seller' => array(
						'type' => 'promoter',
						'id'   => 88,
					),
				)
			)
		);
		$this->assertSame(
			'cannot_manage_seller',
			extrachill_shop_authorize_product_input(
				array(
					'id'     => 502,
					'seller' => array(
						'type' => 'artist',
						'id'   => 10,
					),
				)
			)->get_error_code()
		);
	}

	public function test_product_input_refuses_missing_and_platform_products(): void {
		$GLOBALS['test_user_id']    = self::ADMIN;
		$GLOBALS['test_posts'][503] = null;
		$GLOBALS['test_posts'][504] = (object) array(
			'ID'        => 504,
			'post_type' => 'page',
		);
		$this->assertSame( 'product_not_found', extrachill_shop_authorize_product_input( array( 'id' => 503 ) )->get_error_code() );
		$this->assertSame( 'product_not_found', extrachill_shop_authorize_product_input( array( 'id' => 504 ) )->get_error_code() );
		$this->assertSame( 'product_has_no_seller', extrachill_shop_authorize_product_input( array( 'id' => 505 ) )->get_error_code() );
	}

	/**
	 * Every seller-scoped Shop ability authorizes through the primitive.
	 */
	public function test_every_seller_ability_uses_the_primitive(): void {
		$GLOBALS['test_abilities'] = array();
		foreach ( array(
			'create_product',
			'update_product',
			'delete_product',
			'get_product',
			'upload_product_image',
			'delete_product_image',
			'list_shipping_labels',
			'get_shipping_label',
			'refund_order',
			'stripe_onboarding_link',
			'stripe_dashboard_link',
			'stripe_status',
		) as $name ) {
			call_user_func( 'extrachill_shop_register_' . $name . '_ability' );
		}
		$this->assertCount( 12, $GLOBALS['test_abilities'] );
		foreach ( $GLOBALS['test_abilities'] as $name => $ability ) {
			$this->assertContains(
				$ability['permission_callback'],
				array( 'extrachill_shop_authorize_seller_input', 'extrachill_shop_authorize_product_input' ),
				$name
			);
			$this->assertArrayNotHasKey( 'artist_id', $ability['input_schema']['properties'] ?? array(), $name );
		}
	}

	/**
	 * Source-level guard: no Shop ability or API shop route keeps a private admin bypass.
	 */
	public function test_no_inline_admin_bypasses_remain(): void {
		$files = array_merge(
			glob( dirname( __DIR__ ) . '/inc/abilities/*.php' ),
			glob( dirname( __DIR__, 2 ) . '/extrachill-api/inc/routes/shop/*.php' )
		);
		$this->assertNotEmpty( $files );
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$this->assertStringNotContainsString( "'manage_options'", $source, basename( $file ) );
			$this->assertDoesNotMatchRegularExpression( '/extrachill_api_shop_user_|extrachill_shop_current_user_can_manage_/', $source, basename( $file ) );
		}
	}
}
