<?php
/**
 * Tests for commission rate storage and seller refunds.
 *
 * @package ExtraChillShop
 */

// phpcs:disable WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile,Universal.Files.SeparateFunctionsFromOO
// phpcs:disable Squiz.Commenting.ClassComment.Missing,Squiz.Commenting.FunctionComment.Missing

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/commission-settings.php';
require_once dirname( __DIR__ ) . '/inc/stripe/refunds.php';

final class RefundTestOrder {
	public $meta  = array();
	public $notes = array();

	public function __construct( private int $id ) {}

	public function get_id() {
		return $this->id;
	}

	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}

	public function save() {}

	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
}

final class RefundTestWcRefund {
	public function __construct( private int $id ) {}

	public function get_id() {
		return $this->id;
	}
}

if ( ! function_exists( 'wc_create_refund' ) ) {
	function wc_create_refund( $args ) {
		$GLOBALS['test_wc_refunds'][] = $args;
		if ( isset( $GLOBALS['test_wc_refund_error'] ) ) {
			return $GLOBALS['test_wc_refund_error'];
		}
		return new RefundTestWcRefund( 900 + count( $GLOBALS['test_wc_refunds'] ) );
	}
}
if ( ! function_exists( 'gmdate' ) ) {
	function gmdate( $format ) {
		return date( $format ); // phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
	}
}

final class RefundAndCommissionTest extends TestCase {
	private array $reversals = array();

	protected function setUp(): void {
		$GLOBALS['test_options']    = array();
		$GLOBALS['test_wc_refunds'] = array();
		unset( $GLOBALS['test_wc_refund_error'] );
		$this->reversals = array();
	}

	private function reverser() {
		return function ( $transfer_id, $cents, $idempotency_key, $metadata ) {
			$this->reversals[] = compact( 'transfer_id', 'cents', 'idempotency_key', 'metadata' );
			return 'trr_' . count( $this->reversals );
		};
	}

	private function order(): RefundTestOrder {
		$order                              = new RefundTestOrder( 42 );
		$order->meta['_artist_payouts']     = array(
			7 => array(
				'total'           => 20.0,
				'application_fee' => 2.0,
				'artist_payout'   => 18.0,
			),
		);
		$order->meta['_seller_payouts']     = array(
			'venue:1524' => array(
				'total'           => 8.29,
				'application_fee' => 0.83,
				'artist_payout'   => 7.46,
				'seller'          => array( 'type' => 'venue', 'id' => 1524 ),
			),
		);
		$order->meta['_stripe_transfers']   = array(
			7            => array( 'transfer_id' => 'tr_artist' ),
			'venue:1524' => array( 'transfer_id' => 'tr_venue' ),
		);
		return $order;
	}

	public function test_settings_save_stores_decimal_rate(): void {
		$option = array( 'id' => 'extrachill_shop_commission_rate' );
		$value  = apply_filters( 'woocommerce_admin_settings_sanitize_option', '10', $option, '10' );
		$value  = apply_filters( 'woocommerce_admin_settings_sanitize_option_extrachill_shop_commission_rate', $value, $option, '10' );

		$this->assertSame( 0.1, $value );
	}

	public function test_legacy_percentage_option_reads_as_decimal(): void {
		$GLOBALS['test_options']['extrachill_shop_commission_rate'] = '10';
		$this->assertSame( 0.1, extrachill_shop_get_default_commission_rate() );

		$GLOBALS['test_options']['extrachill_shop_commission_rate'] = '0.15';
		$this->assertSame( 0.15, extrachill_shop_get_default_commission_rate() );
	}

	public function test_invalid_rate_falls_back_to_default(): void {
		foreach ( array( '-1', '250', 'abc' ) as $bad ) {
			$GLOBALS['test_options']['extrachill_shop_commission_rate'] = $bad;
			$this->assertSame( EXTRACHILL_SHOP_DEFAULT_COMMISSION_RATE, extrachill_shop_get_default_commission_rate(), $bad );
		}
	}

	public function test_artist_refund_reverses_payout_then_refunds_through_woocommerce(): void {
		$order  = $this->order();
		$result = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 20.0, $result['refund_amount'] );
		$this->assertTrue( $result['reversed'] );
		$this->assertSame( 'tr_artist', $this->reversals[0]['transfer_id'] );
		$this->assertSame( 1800, $this->reversals[0]['cents'] );
		$this->assertSame( 20.0, $GLOBALS['test_wc_refunds'][0]['amount'] );
		$this->assertTrue( $GLOBALS['test_wc_refunds'][0]['refund_payment'] );
		$this->assertSame( 'trr_1', $order->meta['_seller_refunds'][7]['reversal_id'] );
	}

	public function test_venue_refund_uses_seller_payouts_and_rounds_cents(): void {
		$order  = $this->order();
		$result = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'venue', 'id' => 1524 ), 'Sold out', $this->reverser() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 'tr_venue', $this->reversals[0]['transfer_id'] );
		$this->assertSame( 746, $this->reversals[0]['cents'] );
		$this->assertSame( 'venue:1524', $this->reversals[0]['metadata']['seller_reference'] );
		$this->assertSame( 8.29, $GLOBALS['test_wc_refunds'][0]['amount'] );
		$this->assertSame( 'Sold out', $GLOBALS['test_wc_refunds'][0]['reason'] );
		$this->assertArrayHasKey( 'venue:1524', $order->meta['_seller_refunds'] );
	}

	public function test_second_refund_is_refused(): void {
		$order = $this->order();
		extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );
		$again = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'already_refunded', $again->get_error_code() );
		$this->assertCount( 1, $this->reversals );
		$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
	}

	public function test_retry_after_failed_customer_refund_does_not_reverse_twice(): void {
		$order                           = $this->order();
		$GLOBALS['test_wc_refund_error'] = new WP_Error( 'gateway', 'card_declined' );
		$failed                          = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );

		$this->assertSame( 'refund_failed', $failed->get_error_code() );
		$this->assertNotEmpty( $order->notes );

		unset( $GLOBALS['test_wc_refund_error'] );
		$result = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $this->reversals );
		$this->assertCount( 2, $GLOBALS['test_wc_refunds'] );
	}

	public function test_failed_reversal_does_not_refund_customer(): void {
		$order  = $this->order();
		$result = extrachill_shop_refund_seller_portion(
			$order,
			array( 'type' => 'artist', 'id' => 7 ),
			'',
			static fn() => new WP_Error( 'transfer_reversal_failed', 'insufficient funds' )
		);

		$this->assertSame( 'transfer_reversal_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_wc_refunds'] );
	}

	public function test_untransferred_payout_refunds_without_reversal(): void {
		$order = $this->order();
		unset( $order->meta['_stripe_transfers'][7] );
		$result = extrachill_shop_refund_seller_portion( $order, array( 'type' => 'artist', 'id' => 7 ), '', $this->reverser() );

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['reversed'] );
		$this->assertSame( array(), $this->reversals );
	}

	public function test_seller_not_on_order_is_refused(): void {
		$result = extrachill_shop_refund_seller_portion( $this->order(), array( 'type' => 'promoter', 'id' => 88 ), '', $this->reverser() );

		$this->assertSame( 'invalid_seller', $result->get_error_code() );
	}
}
