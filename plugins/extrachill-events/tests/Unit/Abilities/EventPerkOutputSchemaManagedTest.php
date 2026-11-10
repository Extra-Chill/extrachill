<?php
/**
 * Real-registry output validation for the RSVP perk abilities (extrachill#1244).
 *
 * The perk abilities spread the ticket offer fields into their output schema.
 * An event with no ticket seller stores `ticket_seller` as `{type:venue,id:0}`,
 * which the strict input schema (`id >= 1`) rejected on the way out:
 * `output[event][ticket_seller][id] must be >= 1`. These tests run both
 * abilities through core's real `wp_get_ability()->execute()` so
 * WP_Ability::validate_output() checks the registered schema.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\TicketAbilities;

/** Covers get/set-event-perk output on events with and without a seller. */
final class EventPerkOutputSchemaManagedTest extends WP_UnitTestCase {

	/** Create an admin who can manage every event. */
	protected function setUp(): void {
		parent::setUp();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
	}

	/** Restore the anonymous request user. */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** An event that never chose a seller reads back with a null seller. */
	public function test_get_event_perk_without_a_seller_validates(): void {
		$event = $this->event( array(
			'ticket_seller' => array(
				'type' => 'venue',
				'id'   => 0,
			),
		) );

		$result = $this->ability( 'extrachill/get-event-perk' )->execute( array( 'event' => (string) $event ) );

		$this->assertNotWPError( $result );
		$this->assertArrayHasKey( 'ticket_seller', $result );
		$this->assertNull( $result['ticket_seller'] );
	}

	/** A fresh event with no stored offer reads back the same way. */
	public function test_get_event_perk_without_a_stored_offer_validates(): void {
		$result = $this->ability( 'extrachill/get-event-perk' )->execute( array( 'event' => (string) $this->event() ) );

		$this->assertNotWPError( $result );
		$this->assertNull( $result['ticket_seller'] );
	}

	/** Saving the perk on an event without a seller returns a valid summary. */
	public function test_set_event_perk_without_a_seller_validates(): void {
		$event = $this->event( array(
			'ticket_seller' => array(
				'type' => 'venue',
				'id'   => 0,
			),
		) );

		$result = $this->ability( 'extrachill/set-event-perk' )->execute(
			array(
				'event'   => (string) $event,
				'enabled' => false,
				'text'    => 'First beer on us.',
			)
		);

		$this->assertNotWPError( $result );
		$this->assertTrue( $result['success'] );
		$this->assertNull( $result['event']['ticket_seller'] );
		// Storage keeps the internal ID-0 default other ticket callers read.
		$this->assertSame( 0, TicketAbilities::settings( $event )['ticket_seller']['id'] );
	}

	/** A real seller still reads back as a typed object. */
	public function test_get_event_perk_with_a_seller_keeps_it(): void {
		$event = $this->event( array(
			'ticket_seller' => array(
				'type' => 'promoter',
				'id'   => 55,
			),
		) );

		$result = $this->ability( 'extrachill/get-event-perk' )->execute( array( 'event' => (string) $event ) );

		$this->assertNotWPError( $result );
		$this->assertSame(
			array(
				'type' => 'promoter',
				'id'   => 55,
			),
			$result['ticket_seller']
		);
	}

	/**
	 * Resolve one registered ability through the real registry.
	 *
	 * @param string $name Ability name.
	 */
	private function ability( string $name ): WP_Ability {
		$ability = wp_get_ability( $name );
		$this->assertInstanceOf( WP_Ability::class, $ability, $name . ' must be registered.' );

		return $ability;
	}

	/**
	 * Create one event, optionally with a stored ticket offer.
	 *
	 * @param array|null $offer Stored ticket offer meta.
	 */
	private function event( ?array $offer = null ): int {
		$event = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
				'post_title'  => 'Door test show',
			)
		);
		if ( null !== $offer ) {
			update_post_meta( $event, TicketAbilities::META_KEY, $offer );
		}

		return $event;
	}
}
