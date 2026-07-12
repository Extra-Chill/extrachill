<?php
/**
 * Tests for the inbound crosslink target contract.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Protect the ability's inbound-only product contract.
 */
final class GetCrosslinkTargetsTest extends TestCase {
	/**
	 * The report must describe destinations needing contextual inbound links.
	 */
	public function test_ability_contract_is_inbound_only(): void {
		$source = $this->get_ability_source();

		$this->assertStringContainsString( 'needing contextual links TO them', $source );
		$this->assertStringContainsString( 'source-page candidates or prescribe outbound destinations', $source );
	}

	/**
	 * Target rows and helpers must not prescribe an unproven surface.
	 */
	public function test_target_contract_does_not_prescribe_a_surface(): void {
		$source = $this->get_ability_source();

		$this->assertStringNotContainsString( "'suggested_surface' =>", $source );
		$this->assertStringNotContainsString( 'function extrachill_analytics_crosslink_suggest_surface', $source );
		$this->assertStringNotContainsString( "['same_session']['events']", $source );
		$this->assertStringNotContainsString( "['return']['community']", $source );
	}

	/**
	 * Read the production ability source.
	 *
	 * @return string
	 */
	private function get_ability_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local test fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-crosslink-targets.php' );

		$this->assertNotFalse( $source );

		return $source;
	}
}
