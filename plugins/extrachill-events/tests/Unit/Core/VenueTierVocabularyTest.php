<?php
/**
 * Extra Chill venue tier vocabulary tests.
 *
 * @package ExtraChillEvents\Tests
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 3 ) . '/inc/core/data-machine-events/venue-tier-vocabulary.php';

/** The site vocabulary extends the generic tiers without dropping any. */
final class VenueTierVocabularyTest extends BookingTestCase {

	public function test_adds_restaurant_and_keeps_generic_tiers(): void {
		$generic = array(
			'bar_gig'        => 'Bar / restaurant gig',
			'listening_room' => 'Small, music-first',
			'club'           => 'Standing music venue',
			'concert_hall'   => 'Large ticketed room',
			'amphitheater'   => 'Outdoor large-format',
		);

		$vocabulary = extrachill_events_venue_tier_vocabulary( $generic );

		$this->assertSame( array( 'bar_gig', 'listening_room', 'club', 'concert_hall', 'amphitheater', 'restaurant' ), array_keys( $vocabulary ) );
		$this->assertStringNotContainsString( 'restaurant', strtolower( $vocabulary['bar_gig'] ) );
	}

	public function test_tolerates_non_array_input(): void {
		$this->assertSame( array( 'bar_gig', 'restaurant' ), array_keys( extrachill_events_venue_tier_vocabulary( null ) ) );
	}
}
