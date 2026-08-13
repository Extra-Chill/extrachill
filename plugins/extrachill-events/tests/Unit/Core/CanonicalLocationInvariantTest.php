<?php
/**
 * Canonical event location invariant tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/inc/core/location-normalizer.php';

/**
 * Verify canonical events carry one expected market.
 */
class CanonicalLocationInvariantTest extends TestCase {

	/**
	 * Expected-only assignments are canonical; conflicts are not.
	 */
	public function test_canonical_location_requires_exactly_one_expected_market(): void {
		$this->assertTrue( extrachill_events_has_canonical_location( array( 10 ), 10 ) );
		$this->assertFalse( extrachill_events_has_canonical_location( array( 10, 15 ), 10 ) );
		$this->assertFalse( extrachill_events_has_canonical_location( array( 15 ), 10 ) );
		$this->assertFalse( extrachill_events_has_canonical_location( array(), 10 ) );
	}
}
