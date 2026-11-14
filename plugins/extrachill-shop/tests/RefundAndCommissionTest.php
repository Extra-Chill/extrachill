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
require_once __DIR__ . '/fixtures/woo-refunds.php';

final class RefundTestOrder {
	use WooRefundTracking;

	/**
	 * Order meta.
	 *
	 * @var array
	 */
	public $meta = array();

	public function __construct( private int $id, private float $total ) {}

	public function get_id() {
		return $this->id;
	}

	public function get_total() {
		return $this->total;
	}

	public function get_meta( $key ) {
		return $this->meta[ $key ] ?? '';
	}
}

final class RefundAndCommissionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['test_options']       = array();
		$GLOBALS['test_wc_refunds']    = array();
		$GLOBALS['test_orders']        = array();
		$GLOBALS['ticket_refunds']     = array();
		$GLOBALS['ticket_refund_fail'] = false;
	}

	/** A paid direct-charge artist order, registered where wc_get_order() finds it. */
	private function artist_order(): RefundTestOrder {
		$order                                     = new RefundTestOrder( 42, 25.0 );
		$order->meta['_extrachill_payment_intent'] = 'pi_artist';
		$order->meta['_extrachill_stripe_account'] = 'acct_artist';
		$order->meta['_artist_payouts']            = array(
			7 => array(
				'total'           => 25.0,
				'application_fee' => 2.0,
				'artist_payout'   => 23.0,
				'stripe_account'  => 'acct_artist',
			),
		);
		$GLOBALS['test_orders'][42]                = $order;
		return $order;
	}

	/** A paid direct-charge venue order. */
	private function venue_order(): RefundTestOrder {
		$order                                     = new RefundTestOrder( 43, 8.29 );
		$order->meta['_extrachill_payment_intent'] = 'pi_venue';
		$order->meta['_extrachill_stripe_account'] = 'acct_venue';
		$order->meta['_seller_payouts']            = array(
			'venue:1524' => array(
				'total'           => 8.29,
				'application_fee' => 0.83,
				'artist_payout'   => 7.46,
				'stripe_account'  => 'acct_venue',
			),
		);
		$GLOBALS['test_orders'][43]                = $order;
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

	public function test_seller_full_refund_is_one_direct_charge_refund(): void {
		$order  = $this->artist_order();
		$result = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);

		$this->assertSame(
			array(
				'success'       => true,
				'order_id'      => 42,
				'refund_amount' => 25.0,
			),
			$result
		);
		$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
		$this->assertTrue( $GLOBALS['test_wc_refunds'][0]['refund_payment'] );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
		list( $params, $options ) = $GLOBALS['ticket_refunds'][0];
		$this->assertSame( 'pi_artist', $params['payment_intent'] );
		$this->assertSame( 2500, $params['amount'] );
		$this->assertTrue( $params['refund_application_fee'] );
		$this->assertSame( 'acct_artist', $options['stripe_account'] );
	}

	public function test_venue_refund_uses_seller_payouts_and_rounds_cents(): void {
		$order  = $this->venue_order();
		$result = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'venue',
				'id'   => 1524,
			),
			'Sold out'
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 8.29, $GLOBALS['test_wc_refunds'][0]['amount'] );
		$this->assertSame( 'Sold out', $GLOBALS['test_wc_refunds'][0]['reason'] );
		$this->assertSame( 829, $GLOBALS['ticket_refunds'][0][0]['amount'] );
		$this->assertSame( 'acct_venue', $GLOBALS['ticket_refunds'][0][1]['stripe_account'] );
	}

	public function test_seller_refund_after_partial_refund_refunds_the_remainder(): void {
		$order = $this->artist_order();
		$this->assertFalse(
			is_wp_error(
				wc_create_refund(
					array(
						'order_id'       => 42,
						'amount'         => 10,
						'refund_payment' => true,
					)
				)
			)
		);

		$result = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);

		$this->assertSame( 15.0, $result['refund_amount'] );
		$this->assertSame( array( 1000, 1500 ), array_map( static fn( $r ) => $r[0]['amount'], $GLOBALS['ticket_refunds'] ) );
		$this->assertSame( 0.0, $order->get_remaining_refund_amount() );
	}

	public function test_second_refund_is_refused(): void {
		$order = $this->artist_order();
		extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);
		$again = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);

		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'already_refunded', $again->get_error_code() );
		$this->assertCount( 1, $GLOBALS['test_wc_refunds'] );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
	}

	public function test_failed_stripe_refund_leaves_order_refundable_and_retry_succeeds(): void {
		$order                         = $this->artist_order();
		$GLOBALS['ticket_refund_fail'] = true;
		$failed                        = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);

		$this->assertSame( 'refund_failed', $failed->get_error_code() );
		$this->assertSame( 25.0, $order->get_remaining_refund_amount() );

		$GLOBALS['ticket_refund_fail'] = false;
		$result                        = extrachill_shop_refund_seller_portion(
			$order,
			array(
				'type' => 'artist',
				'id'   => 7,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 1, $GLOBALS['ticket_refunds'] );
	}

	public function test_seller_not_on_order_is_refused(): void {
		$result = extrachill_shop_refund_seller_portion(
			$this->artist_order(),
			array(
				'type' => 'promoter',
				'id'   => 88,
			)
		);

		$this->assertSame( 'invalid_seller', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['test_wc_refunds'] );
	}

	public function test_transfer_era_refund_code_is_gone(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/stripe/refunds.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( 'createReversal', '_stripe_transfers', '_seller_refunds', 'reverse_transfer' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $source );
		}
	}
}
