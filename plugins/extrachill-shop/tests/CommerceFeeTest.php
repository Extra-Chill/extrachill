<?php
// phpcs:disable -- Test doubles and WordPress/WooCommerce stubs.
/** One fee primitive (#1403): extrachill_commerce_fee() and its network settings. */
use PHPUnit\Framework\TestCase;

final class CommerceFeeTest extends TestCase {
	private const VENUE = array( 'type' => 'venue', 'id' => 9911 );

	protected function setUp(): void {
		$GLOBALS['test_site_options'] = array();
		$GLOBALS['test_blog_options'] = array();
	}

	public function test_defaults_keep_current_production_values(): void {
		// Production today: blog-3 commission '10', ticket fees all zero.
		$GLOBALS['test_blog_options'][3]['extrachill_shop_commission_rate'] = '10';
		$GLOBALS['test_site_options']['extrachill_ticket_fees']            = array( 'buyer_fee_flat_minor' => 0, 'buyer_fee_percent' => 0, 'seller_fee_percent' => 0 );
		$this->assertSame(
			array( 'merch_percent' => 10.0, 'ticket_percent' => 0.0, 'ticket_buyer_fee_flat_minor' => 0, 'ticket_buyer_fee_percent' => 0.0 ),
			ec_commerce_fee_settings()
		);
		$this->assertSame( array( 'buyer_fee' => 0, 'application_fee' => 200 ), extrachill_commerce_fee( 'extrachill_merch', 2000, self::VENUE ) );
		$this->assertSame( array( 'buyer_fee' => 0, 'application_fee' => 0 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, self::VENUE ) );
	}

	public function test_missing_shop_option_defaults_to_ten_percent(): void {
		$this->assertSame( 10.0, ec_commerce_fee_settings()['merch_percent'] );
	}

	public function test_decimal_shop_rate_reads_as_a_percentage(): void {
		$GLOBALS['test_blog_options'][3]['extrachill_shop_commission_rate'] = 0.15;
		$this->assertSame( 15.0, ec_commerce_fee_settings()['merch_percent'] );
	}

	public function test_legacy_ticket_fees_are_the_ticket_defaults(): void {
		$GLOBALS['test_site_options']['extrachill_ticket_fees'] = array( 'buyer_fee_flat_minor' => 100, 'buyer_fee_percent' => 3, 'seller_fee_percent' => 5 );
		$this->assertSame( array( 'buyer_fee' => 160, 'application_fee' => 260 ), extrachill_commerce_fee( 'extrachill_ticket', 2000, self::VENUE ) );
	}

	public function test_network_setting_overrides_the_legacy_stores(): void {
		$GLOBALS['test_blog_options'][3]['extrachill_shop_commission_rate'] = '10';
		$GLOBALS['test_site_options']['extrachill_ticket_fees']            = array( 'seller_fee_percent' => 5 );
		$GLOBALS['test_site_options']['extrachill_commerce_fees']          = array( 'merch_percent' => 12.5, 'ticket_percent' => 2 );
		$this->assertSame( 250, extrachill_commerce_fee( 'extrachill_merch', 2000, self::VENUE )['application_fee'] );
		$this->assertSame( 40, extrachill_commerce_fee( 'extrachill_ticket', 2000, self::VENUE )['application_fee'] );
	}

	public function test_platform_products_and_empty_sales_have_no_fee(): void {
		$none = array( 'buyer_fee' => 0, 'application_fee' => 0 );
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'ticket_buyer_fee_flat_minor' => 100 );
		$this->assertSame( $none, extrachill_commerce_fee( 'extrachill_merch', 2000, null ) );
		$this->assertSame( $none, extrachill_commerce_fee( 'extrachill_ticket', 0, self::VENUE ) );
	}

	public function test_merch_fee_never_exceeds_the_subtotal(): void {
		$GLOBALS['test_site_options']['extrachill_commerce_fees'] = array( 'merch_percent' => 100 );
		$this->assertSame( 999, extrachill_commerce_fee( 'extrachill_merch', 999, self::VENUE )['application_fee'] );
	}

	public function test_unknown_kind_is_a_programming_error(): void {
		$this->expectException( InvalidArgumentException::class );
		extrachill_commerce_fee( 'merch', 2000, self::VENUE );
	}

	public function test_sanitize_clamps_and_keeps_fallback_for_invalid_values(): void {
		$fallback = array( 'merch_percent' => 10, 'ticket_percent' => 1, 'ticket_buyer_fee_flat_minor' => 50, 'ticket_buyer_fee_percent' => 2 );
		$this->assertSame(
			array( 'merch_percent' => 100.0, 'ticket_percent' => 0.0, 'ticket_buyer_fee_flat_minor' => 50, 'ticket_buyer_fee_percent' => 2.0 ),
			ec_commerce_fee_sanitize( array( 'merch_percent' => '250', 'ticket_percent' => '-3', 'ticket_buyer_fee_flat_minor' => 'abc', 'ticket_buyer_fee_percent' => '' ), $fallback )
		);
		$this->assertSame( 125, ec_commerce_fee_sanitize( array( 'ticket_buyer_fee_flat_minor' => '124.6' ), $fallback )['ticket_buyer_fee_flat_minor'] );
	}

	public function test_no_duplicate_fee_math_remains(): void {
		$root = dirname( __DIR__ );
		$this->assertFileDoesNotExist( $root . '/inc/core/commission-settings.php' );
		$code = '';
		foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/inc' ) ) as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$code .= file_get_contents( $file->getPathname() );
			}
		}
		foreach ( array( 'extrachill_shop_calculate_commission', 'extrachill_shop_get_default_commission_rate', 'extrachill_shop_ticket_fees', "'extrachill_ticket_fees'", "'extrachill_shop_commission_rate'", '_artist_commission_rate' ) as $legacy ) {
			$this->assertStringNotContainsString( $legacy, $code, $legacy );
		}
	}

	public function test_payments_page_edits_every_fee_setting(): void {
		$path = dirname( __DIR__, 2 ) . '/extrachill-network/admin/network-payments-settings.php';
		require_once $path;
		$this->assertSame( array_keys( ec_commerce_fee_fields() ), array_keys( ec_commerce_fee_labels() ) );
		$page = file_get_contents( $path );
		$this->assertStringContainsString( "update_site_option( 'extrachill_commerce_fees', ec_commerce_fee_sanitize(", $page );
		$this->assertStringNotContainsString( 'extrachill_ticket_fees', $page );
	}
}
