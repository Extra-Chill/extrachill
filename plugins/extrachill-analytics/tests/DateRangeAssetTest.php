<?php
/**
 * Tests for the shared date-range asset ownership contract.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/** Verify registration remains lazy and network-consumable. */
final class DateRangeAssetTest extends TestCase {
	/** Both frontend and admin hooks register without unconditional enqueueing. */
	public function test_asset_is_registered_on_demand_for_both_request_surfaces(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/assets.php' );

		$this->assertNotFalse( $source );
		$this->assertStringContainsString( "const EXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE = 'extrachill-analytics-date-range';", $source );
		$this->assertStringContainsString( "add_action( 'wp_enqueue_scripts', 'extrachill_analytics_register_date_range_asset', 5 );", $source );
		$this->assertStringContainsString( "add_action( 'admin_enqueue_scripts', 'extrachill_analytics_register_date_range_asset', 5 );", $source );
		$this->assertStringContainsString( 'wp_register_script(', $source );
		$this->assertStringContainsString( 'wp_register_style(', $source );
		$this->assertStringNotContainsString( "wp_enqueue_script(\n\t\tEXTRACHILL_ANALYTICS_DATE_RANGE_HANDLE", $source );
	}
}
