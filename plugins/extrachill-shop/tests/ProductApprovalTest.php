<?php
/**
 * Tests for the product approval setting: seller publish requests go to
 * review, admins publish directly, and both sides are emailed.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

// Reuse the suite's WordPress doubles (esc_html, ec_send_email, roster lookup).
require_once __DIR__ . '/SellerOrderNotificationsTest.php';
require_once dirname( __DIR__ ) . '/inc/abilities/product-write-helpers.php';
require_once dirname( __DIR__ ) . '/inc/core/product-approval.php';

if ( ! function_exists( 'get_post_status' ) ) {
	function get_post_status( $post_id ) {
		return isset( $GLOBALS['test_posts'][ $post_id ] ) ? $GLOBALS['test_posts'][ $post_id ]->post_status : false;
	}
}
if ( ! function_exists( 'wp_update_post' ) ) {
	// Mirrors core: changing post_status fires transition_post_status.
	function wp_update_post( $postarr ) {
		$post = $GLOBALS['test_posts'][ $postarr['ID'] ];
		$old  = $post->post_status;
		if ( isset( $postarr['post_status'] ) ) {
			$post->post_status = $postarr['post_status'];
			extrachill_shop_product_approval_transition( $post->post_status, $old, $post );
		}
		$GLOBALS['test_post_updates'][] = $postarr;
		return $postarr['ID'];
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $post_id ) {
		return $GLOBALS['test_thumbnails'][ $post_id ] ?? 0;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( $path = '' ) {
		return 'https://shop.extrachill.com/wp-admin/' . $path;
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'https://shop.extrachill.com/product/p-' . $post_id . '/';
	}
}

final class ProductApprovalTest extends TestCase {
	private const SELLER_USER = 31;
	private const ADMIN       = 32;
	private const MANAGER     = 33;
	private const PRODUCT     = 700;

	protected function setUp(): void {
		$GLOBALS['test_options']      = array(
			'admin_email'                            => 'admin@extrachill.com',
			'extrachill_shop_stripe_seller_artist:9' => array(
				'account_id' => 'acct_ready',
				'status'     => 'active',
			),
		);
		$GLOBALS['test_post_meta']    = array();
		$GLOBALS['test_post_updates'] = array();
		$GLOBALS['notification_mail'] = array();
		$GLOBALS['test_blog_stack']   = array();
		$GLOBALS['test_admins']       = array( self::ADMIN );
		$GLOBALS['test_user_caps']    = array( self::MANAGER => array( 'manage_woocommerce' ) );
		$GLOBALS['test_user_id']      = self::SELLER_USER;
		$GLOBALS['test_thumbnails']   = array( self::PRODUCT => 55 );
		$this->product( 'draft' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['test_posts'][ self::PRODUCT ], $GLOBALS['test_user_caps'] );
		$GLOBALS['test_user_id'] = 1;
	}

	private function product( string $status ): void {
		$GLOBALS['test_posts'][ self::PRODUCT ] = (object) array(
			'ID'          => self::PRODUCT,
			'post_type'   => 'product',
			'post_title'  => 'Tour Tee',
			'post_status' => $status,
		);
		extrachill_shop_set_product_seller(
			self::PRODUCT,
			array(
				'type' => 'artist',
				'id'   => 9,
			)
		);
	}

	private function status(): string {
		return get_post_status( self::PRODUCT );
	}

	private function admin_mail(): array {
		return array_values(
			array_filter(
				$GLOBALS['notification_mail'],
				static fn( $mail ) => 'admin@extrachill.com' === $mail['to']
			)
		);
	}

	public function test_seller_publish_request_becomes_pending_when_approval_is_required(): void {
		$this->assertTrue( extrachill_shop_product_set_status( self::PRODUCT, 'publish' ) );
		$this->assertSame( 'pending', $this->status() );
	}

	public function test_approval_is_required_by_default_and_by_yes(): void {
		$GLOBALS['test_options']['extrachill_shop_require_approval'] = 'yes';
		extrachill_shop_product_set_status( self::PRODUCT, 'publish' );
		$this->assertSame( 'pending', $this->status() );
	}

	public function test_seller_publishes_directly_when_approval_is_off(): void {
		$GLOBALS['test_options']['extrachill_shop_require_approval'] = 'no';
		$this->assertTrue( extrachill_shop_product_set_status( self::PRODUCT, 'publish' ) );
		$this->assertSame( 'publish', $this->status() );
		$this->assertSame( array(), $GLOBALS['notification_mail'], 'Direct publishing is not an approval.' );
	}

	public function test_admin_publishes_directly(): void {
		$GLOBALS['test_user_id'] = self::ADMIN;
		extrachill_shop_product_set_status( self::PRODUCT, 'publish' );
		$this->assertSame( 'publish', $this->status() );
	}

	public function test_shop_manager_publishes_directly(): void {
		$GLOBALS['test_user_id'] = self::MANAGER;
		extrachill_shop_product_set_status( self::PRODUCT, 'publish' );
		$this->assertSame( 'publish', $this->status() );
	}

	public function test_editing_a_published_product_keeps_it_published(): void {
		$this->product( 'publish' );
		$this->assertTrue( extrachill_shop_product_set_status( self::PRODUCT, 'publish' ) );
		$this->assertSame( 'publish', $this->status() );
		$this->assertSame( array(), $GLOBALS['test_post_updates'], 'No status write for an unchanged status.' );
		$this->assertSame( array(), $GLOBALS['notification_mail'] );
	}

	public function test_seller_can_still_unpublish_to_draft(): void {
		$this->product( 'pending' );
		extrachill_shop_product_set_status( self::PRODUCT, 'draft' );
		$this->assertSame( 'draft', $this->status() );
	}

	public function test_publish_validation_still_runs_before_review(): void {
		$GLOBALS['test_thumbnails'] = array();
		$result                     = extrachill_shop_product_set_status( self::PRODUCT, 'publish' );
		$this->assertSame( 'product_image_required', $result->get_error_code() );
		$this->assertSame( 'draft', $this->status() );
	}

	public function test_pending_requires_no_invented_status(): void {
		$this->assertSame( 'invalid_status', extrachill_shop_product_set_status( self::PRODUCT, 'pending' )->get_error_code() );
	}

	public function test_admin_is_emailed_when_a_product_enters_review(): void {
		extrachill_shop_product_set_status( self::PRODUCT, 'publish' );
		$mail = $this->admin_mail();
		$this->assertCount( 1, $mail );
		$this->assertSame( 'Product awaiting review: Tour Tee', $mail[0]['subject'] );
		$this->assertSame( 'https://shop.extrachill.com/wp-admin/post.php?post=700&action=edit', $mail[0]['context']['cta_url'] );
		$this->assertStringContainsString( 'Tour Tee', $mail[0]['context']['body_html'] );
		$this->assertCount( 1, $GLOBALS['notification_mail'], 'Sellers are not emailed on submission.' );
	}

	public function test_seller_is_emailed_when_an_admin_approves(): void {
		$this->product( 'pending' );
		$GLOBALS['test_user_id'] = self::ADMIN;
		wp_update_post(
			array(
				'ID'          => self::PRODUCT,
				'post_status' => 'publish',
			)
		);
		$this->assertSame( array( 'user4@example.com', 'user5@example.com' ), array_column( $GLOBALS['notification_mail'], 'to' ) );
		$this->assertSame( 'Your product is live: Tour Tee', $GLOBALS['notification_mail'][0]['subject'] );
		$this->assertSame( 'https://shop.extrachill.com/product/p-700/', $GLOBALS['notification_mail'][0]['context']['cta_url'] );
		$this->assertSame( array(), $this->admin_mail() );
	}

	public function test_platform_products_and_other_transitions_are_silent(): void {
		extrachill_shop_clear_product_seller( self::PRODUCT );
		extrachill_shop_product_approval_transition( 'pending', 'draft', $GLOBALS['test_posts'][ self::PRODUCT ] );
		$this->product( 'publish' );
		extrachill_shop_product_approval_transition( 'publish', 'draft', $GLOBALS['test_posts'][ self::PRODUCT ] );
		extrachill_shop_product_approval_transition( 'pending', 'pending', $GLOBALS['test_posts'][ self::PRODUCT ] );
		$order = (object) array(
			'ID'        => 5,
			'post_type' => 'shop_order',
		);
		extrachill_shop_product_approval_transition( 'pending', 'draft', $order );
		$this->assertSame( array(), $GLOBALS['notification_mail'] );
	}
}
