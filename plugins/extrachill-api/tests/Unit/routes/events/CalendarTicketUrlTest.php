<?php
/**
 * Tests for affiliate ticket-URL gating in the public calendar payload.
 *
 * @package ExtraChill\API\Tests
 */

/** Exercises extrachill_api_gate_calendar_ticket_urls() in isolation, without the calendar ability. */
class Calendar_TicketUrlTest extends WP_UnitTestCase {

	/** @var array<int, array<string, mixed>> */
	private $resolution_inputs = array();

	/** @var array<int, mixed> */
	private $resolution_queue = array();

	/** Reset controlled state. */
	public function set_up() {
		parent::set_up();
		$this->resolution_inputs = array();
		$this->resolution_queue  = array();
	}

	/** Remove the controlled fixture. */
	public function tear_down() {
		if ( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY );
		}
		if ( wp_has_ability_category( 'calendar-ticket-url-tests' ) ) {
			wp_unregister_ability_category( 'calendar-ticket-url-tests' );
		}
		parent::tear_down();
	}

	/** An affiliate ticket URL is nulled out and replaced with a ticket_ref. */
	public function test_affiliate_url_is_nulled_and_ref_added() {
		$this->register_ability( false );
		$this->resolution_queue = array(
			array(
				'url'          => 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=https%3A%2F%2Fwww.ticketmaster.com%2Fevent%2FZ7r9jZ1A7JFo-',
				'is_affiliate' => true,
			),
		);

		$result = extrachill_api_gate_calendar_ticket_urls( $this->fixture( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...' ) );
		$event  = $result['dates'][0]['events'][0];

		$this->assertArrayHasKey( 'ticket_url', $event );
		$this->assertNull( $event['ticket_url'] );
		$this->assertSame( 501, $event['ticket_ref'] );
		$this->assertSame( array( array( 'event_id' => 501 ) ), $this->resolution_inputs );
	}

	/** A non-affiliate ticket URL (e.g. a venue's own box office) passes through unchanged. */
	public function test_non_affiliate_url_passes_through_unchanged() {
		$this->register_ability( false );
		$this->resolution_queue = array(
			array(
				'url'          => 'https://venue.example.com/box-office/501',
				'is_affiliate' => false,
			),
		);

		$result = extrachill_api_gate_calendar_ticket_urls( $this->fixture( 'https://venue.example.com/box-office/501' ) );
		$event  = $result['dates'][0]['events'][0];

		$this->assertSame( 'https://venue.example.com/box-office/501', $event['ticket_url'] );
		$this->assertArrayNotHasKey( 'ticket_ref', $event );
	}

	/** An already-null ticket_url never calls the ability. */
	public function test_null_ticket_url_is_skipped() {
		$this->register_ability( false );

		$result = extrachill_api_gate_calendar_ticket_urls( $this->fixture( null ) );

		$this->assertNull( $result['dates'][0]['events'][0]['ticket_url'] );
		$this->assertEmpty( $this->resolution_inputs );
	}

	/** A missing sibling ability leaves the whole payload untouched, not fatal. */
	public function test_missing_ability_leaves_payload_untouched() {
		$this->assertFalse( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) );

		$fixture = $this->fixture( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...' );
		$result  = extrachill_api_gate_calendar_ticket_urls( $fixture );

		$this->assertSame( $fixture, $result );
	}

	/** A REST-visible ability (misconfigured) is treated the same as absent. */
	public function test_rest_visible_ability_leaves_payload_untouched() {
		$this->register_ability( true );

		$fixture = $this->fixture( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...' );
		$result  = extrachill_api_gate_calendar_ticket_urls( $fixture );

		$this->assertSame( $fixture, $result );
		$this->assertEmpty( $this->resolution_inputs );
	}

	/** A per-event resolution error leaves that event's ticket_url as the upstream ability returned it. */
	public function test_per_event_resolution_error_leaves_url_untouched() {
		$this->register_ability( false );
		$this->resolution_queue = array( new WP_Error( 'ticket_not_found', 'No ticket URL for this event.' ) );

		$fixture = $this->fixture( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...' );
		$result  = extrachill_api_gate_calendar_ticket_urls( $fixture );

		$this->assertSame( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...', $result['dates'][0]['events'][0]['ticket_url'] );
		$this->assertArrayNotHasKey( 'ticket_ref', $result['dates'][0]['events'][0] );
	}

	/** Multiple events across multiple date groups are gated independently. */
	public function test_multiple_events_gated_independently() {
		$this->register_ability( false );
		$this->resolution_queue = array(
			array(
				'url'          => 'https://ticketmaster.evyy.net/c/1191134/1',
				'is_affiliate' => true,
			),
			array(
				'url'          => 'https://venue.example.com/box-office/2',
				'is_affiliate' => false,
			),
		);

		$result = extrachill_api_gate_calendar_ticket_urls(
			array(
				'dates' => array(
					array(
						'date'   => '2026-08-10',
						'events' => array(
							array(
								'id'         => 501,
								'ticket_url' => 'https://ticketmaster.evyy.net/c/1191134/1',
							),
						),
					),
					array(
						'date'   => '2026-08-11',
						'events' => array(
							array(
								'id'         => 502,
								'ticket_url' => 'https://venue.example.com/box-office/2',
							),
						),
					),
				),
				'total' => 2,
			)
		);

		$this->assertNull( $result['dates'][0]['events'][0]['ticket_url'] );
		$this->assertSame( 501, $result['dates'][0]['events'][0]['ticket_ref'] );
		$this->assertSame( 'https://venue.example.com/box-office/2', $result['dates'][1]['events'][0]['ticket_url'] );
		$this->assertArrayNotHasKey( 'ticket_ref', $result['dates'][1]['events'][0] );
	}

	/** A malformed ability result (non-array, non-WP_Error) leaves ticket_url untouched. */
	public function test_malformed_ability_result_leaves_url_untouched() {
		$this->register_ability( false );
		$this->resolution_queue = array( 'not-an-array' );

		$fixture = $this->fixture( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...' );
		$result  = extrachill_api_gate_calendar_ticket_urls( $fixture );

		$this->assertSame( 'https://ticketmaster.evyy.net/c/1191134/264167/4272?u=...', $result['dates'][0]['events'][0]['ticket_url'] );
	}

	/** Build one canonical single-event calendar fixture. */
	private function fixture( $ticket_url ) {
		return array(
			'dates' => array(
				array(
					'date'   => '2026-08-10',
					'label'  => 'Monday, August 10, 2026',
					'events' => array(
						array(
							'id'         => 501,
							'title'      => 'Some Show',
							'ticket_url' => $ticket_url,
						),
					),
				),
			),
			'total' => 1,
			'page'  => 1,
		);
	}

	/** Register the controlled hidden ability contract used by data-machine-events#816. */
	private function register_ability( $show_in_rest ) {
		if ( wp_has_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY ) ) {
			wp_unregister_ability( EXTRACHILL_API_TICKET_REDIRECT_ABILITY );
		}
		if ( ! wp_has_ability_category( 'calendar-ticket-url-tests' ) ) {
			WP_Ability_Categories_Registry::get_instance()->register(
				'calendar-ticket-url-tests',
				array(
					'label'       => 'Calendar ticket URL tests',
					'description' => 'Controlled hidden ticket-destination contract.',
				)
			);
		}
		$test = $this;
		WP_Abilities_Registry::get_instance()->register(
			EXTRACHILL_API_TICKET_REDIRECT_ABILITY,
			array(
				'label'               => 'Resolve ticket destination',
				'description'         => 'Controlled ticket destination resolver.',
				'category'            => 'calendar-ticket-url-tests',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'event_id' => array( 'type' => 'integer' ),
					),
					'required'             => array( 'event_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array( 'type' => 'object' ),
				'permission_callback' => '__return_true',
				'execute_callback'    => static function ( $input ) use ( $test ) {
					$test->resolution_inputs[] = $input;
					return array_shift( $test->resolution_queue );
				},
				'meta'                => array( 'show_in_rest' => (bool) $show_in_rest ),
			)
		);
	}
}
