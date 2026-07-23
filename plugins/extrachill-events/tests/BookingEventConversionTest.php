<?php
/**
 * Confirmed booking to canonical event conversion tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingEventAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingConversionAbilityFake {
	public $calls = array();
	public $callback;
	public function __construct( callable $callback ) {
		$this->callback = $callback;
	}
	public function execute( $input ) {
		$this->calls[] = $input;
		return call_user_func( $this->callback, $input );
	}
}

final class BookingEventConversionTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'         => 7,
			'stack'           => array(),
			'uuid'            => 0,
			'options'         => array(),
			'dbdelta'         => array(),
			'abilities'       => array(),
			'ability_objects' => array(),
			'actions'         => array(),
			'fired_actions'   => array(),
			'scheduled'       => array(),
			'cache_deletes'   => array(),
			'permalinks'      => array( 7 => array() ),
			'event_venues'    => array( 7 => array() ),
			'terms'           => array(
				1 => array(
					101 => (object) array( 'term_id' => 101, 'taxonomy' => 'artist', 'name' => 'Canonical Artist' ),
				),
				7 => array(
					55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue', 'name' => 'The Canonical Room' ),
				),
			),
			'meta'            => array(
				1 => array(
					101 => array( '_artist_profile_id' => 501 ),
				),
				7 => array(
					55 => array(
						'_venue_address'     => '123 King Street',
						'_venue_city'        => 'Charleston',
						'_venue_state'       => 'SC',
						'_venue_zip'         => '29401',
						'_venue_country'     => 'US',
						'_venue_phone'       => '843-555-0100',
						'_venue_website'     => 'https://venue.example',
						'_venue_coordinates' => '32.7765,-79.9311',
						'_venue_capacity'    => '500',
						'_venue_timezone'    => 'America/New_York',
					),
				),
			),
			'posts'           => array(
				4 => array(
					501 => (object) array( 'ID' => 501, 'post_type' => 'artist_profile', 'post_status' => 'publish', 'post_title' => 'Canonical Artist' ),
				),
				7 => array(),
			),
			'post_meta'       => array( 4 => array( 501 => array( '_artist_term_id' => 101 ) ) ),
		);
		$GLOBALS['wpdb'] = new BookingWpdb();
		$this->install_ability();
	}

	private function deal( array $overrides = array() ): array {
		return array_merge(
			array(
				'version'                    => 1,
				'type'                       => 'guarantee',
				'guarantee_cents'            => 100000,
				'revenue_share_basis_points' => 1500,
				'revenue_share_basis'        => 'gross_ticket_sales',
				'currency'                   => 'USD',
				'capacity'                   => 300,
				'advance_ticket_price_cents' => 2000,
				'door_ticket_price_cents'    => 2500,
				'ticket_fee_cents'           => 300,
				'tickets_on_sale_at'         => '2030-01-01 15:00:00',
				'ticket_url'                  => 'https://tickets.example/test-band',
				'additional_terms'            => 'Private merch and settlement terms.',
			),
			$overrides
		);
	}

	private function booking( array $overrides = array(), bool $with_hold = true ): array {
		$data    = array_merge(
			array(
				'venue_term_id'        => 55,
				'artist_name'          => 'Test Band',
				'intake'               => array( 'private_contact' => 'hidden' ),
				'contact_email'        => 'private@example.com',
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-03-10 00:00:00',
				'performance_end_at'   => '2030-03-10 03:00:00',
				'confirmed_deal'       => $this->deal(),
			),
			$overrides
		);
		$booking = ( new BookingRepository() )->create( $data );
		$row     =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$row['status'] = $overrides['status'] ?? 'confirmed';
		$booking       = ( new BookingRepository() )->get( $booking['id'] );
		if ( $with_hold ) {
			$this->add_converted_hold( $booking );
		}
		return $booking;
	}

	private function add_converted_hold( array $booking, array $overrides = array() ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['wpdb']->insert(
			BookingSchema::holds_table(),
			array_merge(
				array(
					'booking_id' => $booking['id'], 'venue_term_id' => $booking['venue_term_id'], 'space_key' => $booking['space_key'],
					'start_at' => $booking['performance_start_at'], 'end_at' => $booking['performance_end_at'], 'expires_at' => $now,
					'status' => 'converted', 'version' => 2, 'created_by_user_id' => 12, 'created_at' => $now, 'updated_at' => $now,
					'released_at' => null, 'released_by_user_id' => null, 'release_reason' => null, 'expired_at' => null,
					'converted_at' => $now, 'converted_by_user_id' => 12,
				),
				$overrides
			)
		);
	}

	private function install_ability( ?callable $callback = null ): BookingConversionAbilityFake {
		$callback = $callback ? $callback : function ( $input ) {
			$this->add_event( 901 );
			$this->add_event_source_proof( 901, $input );
			return $this->upstream_result( $input );
		};
		$ability = new BookingConversionAbilityFake( $callback );
		$GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'] = $ability;
		return $ability;
	}

	private function add_event( int $id, string $type = 'data_machine_events', string $status = 'publish' ): void {
		$GLOBALS['ec_artist_test']['posts'][7][ $id ] = (object) array( 'ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Canonical Event' );
		$GLOBALS['ec_artist_test']['permalinks'][7][ $id ] = 'https://events.example/event/' . $id;
	}

	private function add_event_source_proof( int $id, array $input, ?int $venue_id = null ): void {
		$identity = hash( 'sha256', $input['source'] . "\0" . $input['source_id'] );
		$GLOBALS['ec_artist_test']['post_meta'][7][ $id ] = array(
			'_datamachine_event_source'          => $input['source'],
			'_datamachine_event_source_id'       => $input['source_id'],
			'_datamachine_event_source_identity' => $identity,
		);
		$GLOBALS['ec_artist_test']['event_venues'][7][ $id ] = array( null === $venue_id ? 55 : $venue_id );
	}

	private function upstream_result( array $input, array $overrides = array() ): array {
		$result = array(
			'success'   => true,
			'event_id'  => 901,
			'event_url' => 'https://events.example/test-band',
			'action'    => 'created',
			'source'    => array(
				'name'     => $input['source'],
				'id'       => $input['source_id'],
				'identity' => hash( 'sha256', $input['source'] . "\0" . $input['source_id'] ),
			),
			'normalized' => array( 'venue_id' => 55, 'post_status' => 'publish' ),
		);
		return array_replace_recursive( $result, $overrides );
	}

	private function add_completed_conversion( array $booking, int $event_id = 901 ): void {
		$identity = hash( 'sha256', 'extrachill-events-booking' . "\0" . $booking['public_id'] );
		$state    = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$attempt  = is_array( $state ) && $state['attempt'] > 0 ? $state['attempt'] : 1;
		if ( 0 === $state['attempt'] ) {
			( new BookingActivityRepository() )->append(
				array(
					'booking_id'      => $booking['id'],
					'kind'            => 'event_conversion_started',
					'idempotency_key' => sprintf( 'event-conversion:%s:%d:event_conversion_started', $booking['public_id'], $attempt ),
					'payload'         => array( 'attempt' => $attempt, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'expected_version' => $booking['version'] ),
				)
			);
		}
		( new BookingActivityRepository() )->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'event_converted',
				'idempotency_key' => sprintf( 'event-conversion:%s:%d:event_converted', $booking['public_id'], $attempt ),
				'external_id'     => (string) $event_id,
				'payload'         => array( 'attempt' => $attempt, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'event_id' => $event_id, 'version' => $booking['version'] + 1 ),
			)
		);
	}

	private function service( ?BookingTestAuthorization $authorization = null ): BookingEventConversionService {
		return new BookingEventConversionService( null, null, null, $authorization ? $authorization : new BookingTestAuthorization() );
	}

	public function test_strict_ability_contract_and_non_enumerating_exact_venue_permission(): void {
		$authorization = new BookingTestAuthorization();
		$ability       = new VenueBookingEventAbilities( $this->service( $authorization ), null, $authorization );
		$ability->register();
		$definition = $GLOBALS['ec_artist_test']['abilities']['extrachill/convert-booking-to-event'];
		$this->assertSame( array( 'booking_id', 'expected_version' ), $definition['input_schema']['required'] );
		$this->assertFalse( $definition['input_schema']['additionalProperties'] );
		$this->assertFalse( $definition['output_schema']['additionalProperties'] );
		$this->assertSame( array( 'created', 'updated', 'no_change', 'existing' ), $definition['output_schema']['properties']['event_action']['enum'] );
		$this->assertTrue( $definition['meta']['show_in_rest'] );
		$this->assertTrue( $definition['meta']['annotations']['idempotent'] );
		$this->assertFalse( $definition['meta']['annotations']['destructive'] );
		$this->assertSame( 'venue_action_forbidden', $ability->can_access_booking( array( 'booking_id' => 999 ) )->get_error_code() );
		$booking = $this->booking();
		$this->assertTrue( $ability->can_access_booking( array( 'booking_id' => $booking['id'] ) ) );
		$this->assertSame( 55, $authorization->calls[0][1] );
	}

	public function test_nested_dme_permission_is_scoped_to_the_active_booking_conversion(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$wrapper       = null;
		$this->install_ability(
			function ( array $input ) use ( &$wrapper ): array {
				$this->assertTrue( $wrapper->can_upsert_booking_event( false, $input ) );
				$this->assertFalse( $wrapper->can_upsert_booking_event( false, array_merge( $input, array( 'source_id' => 'another-booking' ) ) ) );
				$this->add_event( 901 );
				$this->add_event_source_proof( 901, $input );
				return $this->upstream_result( $input );
			}
		);
		$wrapper = new VenueBookingEventAbilities( $this->service( $authorization ), null, $authorization );

		$result = $wrapper->execute( array( 'booking_id' => $booking['id'], 'expected_version' => 1 ) );

		$this->assertSame( 901, $result['event_id'] );
		$this->assertFalse(
			$wrapper->can_upsert_booking_event(
				false,
				array( 'source' => BookingEventConversionService::SOURCE, 'source_id' => $booking['public_id'] )
			)
		);
	}

	public function test_success_maps_only_canonical_public_data_and_claims_once(): void {
		$ability = $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'];
		$booking = $this->booking();
		$result  = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( array( 'booking_id', 'booking_version', 'event_id', 'event_url', 'event_action', 'already_converted' ), array_keys( $result ) );
		$this->assertSame( 2, $result['booking_version'] );
		$this->assertSame( 'created', $result['event_action'] );
		$this->assertFalse( $result['already_converted'] );
		$input = $ability->calls[0];
		$this->assertSame( 'extrachill-events-booking', $input['source'] );
		$this->assertSame( $booking['public_id'], $input['source_id'] );
		$this->assertSame( 'publish', $input['post_status'] );
		$this->assertArrayNotHasKey( 'post_author', $input );
		$this->assertSame( 'Test Band at The Canonical Room', $input['event']['title'] );
		$this->assertSame( 'Test Band', $input['event']['performer'] );
		$this->assertSame( 'PerformingGroup', $input['event']['performerType'] );
		$this->assertSame( 'The Canonical Room', $input['event']['venue'] );
		$this->assertSame( '123 King Street', $input['event']['venueAddress'] );
		$this->assertSame( 'Charleston', $input['event']['venueCity'] );
		$this->assertSame( 'SC', $input['event']['venueState'] );
		$this->assertSame( '29401', $input['event']['venueZip'] );
		$this->assertSame( 'US', $input['event']['venueCountry'] );
		$this->assertSame( '843-555-0100', $input['event']['venuePhone'] );
		$this->assertSame( 'https://venue.example', $input['event']['venueWebsite'] );
		$this->assertSame( '32.7765,-79.9311', $input['event']['venueCoordinates'] );
		$this->assertSame( '500', $input['event']['venueCapacity'] );
		$this->assertSame( 'America/New_York', $input['event']['venueTimezone'] );
		$this->assertSame( '2030-03-09', $input['event']['startDate'] );
		$this->assertSame( '19:00', $input['event']['startTime'] );
		$this->assertSame( '22:00', $input['event']['endTime'] );
		$this->assertSame( '20.00 adv / 25.00 door', $input['event']['price'] );
		$this->assertSame( 'USD', $input['event']['priceCurrency'] );
		$this->assertSame( 'https://tickets.example/test-band', $input['event']['ticketUrl'] );
		foreach ( array( 'guarantee_cents', 'revenue_share_basis_points', 'ticket_fee_cents', 'additional_terms', 'space_key', 'contact_email', 'intake', 'production' ) as $private ) {
			$this->assertArrayNotHasKey( $private, $input['event'] );
		}
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertCount( 2, $activity );
		$this->assertSame( 'event_converted', $activity[0]['kind'] );
		$this->assertSame( 901, $activity[0]['payload']['data']['event_id'] );
		$this->assertSame( 2, $activity[0]['payload']['data']['version'] );
		$this->assertSame( 'event_conversion_started', $activity[1]['kind'] );
		$this->assertGreaterThanOrEqual( 2, $GLOBALS['wpdb']->booking_lock_queries );
	}

	/** @dataProvider priceProvider */
	public function test_public_price_cases( $advance, $door, $expected ): void {
		$ability = $this->install_ability();
		$booking = $this->booking( array( 'confirmed_deal' => $this->deal( array( 'advance_ticket_price_cents' => $advance, 'door_ticket_price_cents' => $door ) ) ) );
		$this->service()->convert( $booking['id'], 1, 12 );
		$event = $ability->calls[0]['event'];
		if ( null === $expected ) {
			$this->assertArrayNotHasKey( 'price', $event );
			$this->assertArrayNotHasKey( 'priceCurrency', $event );
		} else {
			$this->assertSame( $expected, $event['price'] );
		}
	}

	public static function priceProvider(): array {
		return array( 'absent' => array( null, null, null ), 'equal' => array( 2000, 2000, '20.00' ), 'advance' => array( 2000, null, '20.00 adv' ), 'door' => array( null, 2500, '25.00 door' ), 'different' => array( 2000, 2500, '20.00 adv / 25.00 door' ) );
	}

	public function test_independent_timezone_mapping_handles_overnight_and_dst(): void {
		$ability = $this->install_ability();
		$overnight = $this->booking( array( 'performance_start_at' => '2030-01-02 04:30:00', 'performance_end_at' => '2030-01-02 07:30:00' ) );
		$this->service()->convert( $overnight['id'], 1, 12 );
		$this->assertSame( array( '2030-01-01', '23:30', '2030-01-02', '02:30' ), array_values( array_intersect_key( $ability->calls[0]['event'], array_flip( array( 'startDate', 'startTime', 'endDate', 'endTime' ) ) ) ) );
		$dst = $this->booking( array( 'performance_start_at' => '2030-03-10 06:30:00', 'performance_end_at' => '2030-03-10 07:30:00' ) );
		$this->service()->convert( $dst['id'], 1, 12 );
		$this->assertSame( '01:30', $ability->calls[1]['event']['startTime'] );
		$this->assertSame( '03:30', $ability->calls[1]['event']['endTime'] );
	}

	public function test_preflight_fails_closed_for_status_selection_deal_venue_timezone_and_converted_hold(): void {
		$cases = array();
		$cases[] = array( $this->booking( array( 'status' => 'held' ) ), 'booking_event_status_forbidden' );
		$cases[] = array( $this->booking( array( 'space_key' => null ), false ), 'booking_event_selection_incomplete' );
		$invalid_deal = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $invalid_deal['id'] ]['confirmed_deal_payload'] = null;
		$cases[] = array( $invalid_deal, 'booking_event_confirmed_deal_invalid' );
		$no_hold = $this->booking( array(), false );
		$cases[] = array( $no_hold, 'booking_event_converted_hold_invalid' );
		foreach ( $cases as $case ) {
			$this->assertSame( $case[1], $this->service()->convert( $case[0]['id'], 1, 12 )->get_error_code() );
		}
		$multiple = $this->booking();
		$this->add_converted_hold( $multiple );
		$this->assertSame( 'booking_event_converted_hold_invalid', $this->service()->convert( $multiple['id'], 1, 12 )->get_error_code() );
		$timezone = $this->booking();
		$GLOBALS['ec_artist_test']['meta'][7][55]['_venue_timezone'] = 'Not/AZone';
		$this->assertSame( 'booking_event_venue_timezone_invalid', $this->service()->convert( $timezone['id'], 1, 12 )->get_error_code() );
		$GLOBALS['ec_artist_test']['meta'][7][55]['_venue_timezone'] = 'America/New_York';
		unset( $GLOBALS['ec_artist_test']['meta'][7][55]['_venue_address'] );
		$this->assertSame( 'booking_event_venue_incomplete', $this->service()->convert( $timezone['id'], 1, 12 )->get_error_code() );
		$GLOBALS['ec_artist_test']['meta'][7][55]['_venue_address'] = '123 King Street';
		unset( $GLOBALS['ec_artist_test']['terms'][7][55] );
		$this->assertSame( 'booking_event_venue_invalid', $this->service()->convert( $timezone['id'], 1, 12 )->get_error_code() );
		$this->assertCount( 0, $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event']->calls );
	}

	public function test_rollback_failure_is_explicit(): void {
		$booking = $this->booking( array( 'status' => 'held' ) );
		$GLOBALS['wpdb']->fail_transaction_rollback = true;
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_transaction_rollback_failed', $error->get_error_code() );
		$this->assertSame( 'booking_event_status_forbidden', $error->get_error_data()['cause'] );
	}

	public function test_missing_and_upstream_errors_preserve_retryability_without_booking_mutation(): void {
		$booking = $this->booking();
		unset( $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'] );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_ability_unavailable', $error->get_error_code() );
		$this->assertSame( 503, $error->get_error_data()['status'] );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 'none', $state['status'] );
		$this->assertFalse( $state['pending'] );
		$ability = $this->install_ability( static function () { return new WP_Error( 'upstream_busy', 'busy', array( 'status' => 503, 'retryable' => true ) ); } );
		$error   = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_upsert_failed', $error->get_error_code() );
		$this->assertSame( 'upstream_busy', $error->get_error_data()['upstream_code'] );
		$this->assertTrue( $error->get_error_data()['retryable'] );
		$this->assertSame( 1, $error->get_error_data()['attempt'] );
		$this->assertNull( ( new BookingRepository() )->get( $booking['id'] )['event_id'] );
		$this->assertCount( 1, $ability->calls );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertFalse( $state['pending'] );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertSame( 1, $state['attempt'] );
		$this->assertNotNull( $state['started'] );
		$this->assertNull( $state['completed'] );
		$this->assertCount( 1, array_filter( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), static function ( $activity ) { return 'event_conversion_started' === $activity['kind']; } ) );
	}

	public function test_start_activity_failure_rolls_back_and_prevents_external_call(): void {
		$booking = $this->booking();
		$ability = $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'];
		$GLOBALS['wpdb']->fail_activity_kinds = array( 'event_conversion_started' );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_activity_write_failed', $error->get_error_code() );
		$this->assertCount( 0, $ability->calls );
		$this->assertFalse( ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] )['pending'] );
	}

	public function test_missing_ability_does_not_block_cancellation(): void {
		$booking = $this->booking();
		unset( $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'] );
		$this->assertSame( 'booking_event_ability_unavailable', $this->service()->convert( $booking['id'], 1, 12 )->get_error_code() );
		$lifecycle = new BookingLifecycle( null, null, new BookingTestAuthorization() );
		$result    = $lifecycle->transition( $booking['id'], 'cancelled', 1, 12 );
		$this->assertSame( 'cancelled', $result['status'] );
	}

	public function test_pending_invalid_response_blocks_confirmed_cancellation_and_completion_under_lock(): void {
		$booking = $this->booking();
		$this->install_ability( function ( $input ) { return $this->upstream_result( $input, array( 'success' => false ) ); } );
		$this->assertSame( 'booking_event_upsert_failed', $this->service()->convert( $booking['id'], 1, 12 )->get_error_code() );
		$lifecycle = new BookingLifecycle( null, null, new BookingTestAuthorization() );
		foreach ( array( 'cancelled', 'completed' ) as $status ) {
			$error = $lifecycle->transition( $booking['id'], $status, 1, 12 );
			$this->assertSame( 'booking_event_conversion_pending', $error->get_error_code(), $status );
			$this->assertSame( 409, $error->get_error_data()['status'], $status );
			$this->assertSame( 'confirmed', ( new BookingRepository() )->get( $booking['id'] )['status'], $status );
		}
		$this->assertGreaterThanOrEqual( 3, $GLOBALS['wpdb']->booking_lock_queries );
	}

	public function test_pending_conversion_blocks_assignment_and_artist_binding_without_mutation(): void {
		$booking = $this->booking();
		$this->install_ability( function ( $input ) { return $this->upstream_result( $input, array( 'success' => false ) ); } );
		$this->service()->convert( $booking['id'], 1, 12 );
		$authorization = new BookingTestAuthorization( array( '20:55' => true ) );
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$before        = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$assigned      = $lifecycle->assign( $booking['id'], 20, 1, 12 );
		$this->assertSame( 'booking_event_conversion_pending', $assigned->get_error_code() );
		$bound = $lifecycle->bind_artist( $booking['id'], 101, 501, 1, 12 );
		$this->assertSame( 'booking_event_conversion_pending', $bound->get_error_code() );
		$current = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertSame( 1, $current['version'] );
		$this->assertNull( $current['assignee_user_id'] );
		$this->assertNull( $current['artist_term_id'] );
		$this->assertNull( $current['artist_profile_id'] );
		$this->assertCount( count( $before ), ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) );
		$this->assertGreaterThanOrEqual( 2, $GLOBALS['wpdb']->booking_lock_queries );
	}

	public function test_failed_state_allows_binding_while_completed_state_allows_only_private_assignment(): void {
		$failed = $this->booking();
		$this->install_ability( static function () { return new WP_Error( 'failed', 'failed', array( 'status' => 422 ) ); } );
		$this->service()->convert( $failed['id'], 1, 12 );
		$lifecycle = new BookingLifecycle( null, null, new BookingTestAuthorization( array( '20:55' => true ) ) );
		$assigned  = $lifecycle->assign( $failed['id'], 20, 1, 12 );
		$this->assertSame( 2, $assigned['version'] );
		$bound = $lifecycle->bind_artist( $failed['id'], 101, 501, 2, 12 );
		$this->assertSame( 3, $bound['version'] );
		$failed_state = ( new BookingActivityRepository() )->event_conversion_state( $failed['id'], $failed['public_id'] );
		$this->assertSame( 'failed', $failed_state['status'] );
		$this->assertFalse( $failed_state['pending'] );

		$completed = $this->booking();
		$this->install_ability();
		$this->service()->convert( $completed['id'], 1, 12 );
		$assigned = $lifecycle->assign( $completed['id'], 20, 2, 12 );
		$this->assertSame( 3, $assigned['version'] );
		$activity_count = count( ( new BookingActivityRepository() )->list_for_booking( $completed['id'] ) );
		$bound = $lifecycle->bind_artist( $completed['id'], 101, 501, 3, 12 );
		$this->assertSame( 'booking_event_artist_frozen', $bound->get_error_code() );
		$this->assertSame( 409, $bound->get_error_data()['status'] );
		$current = ( new BookingRepository() )->get( $completed['id'] );
		$this->assertSame( 3, $current['version'] );
		$this->assertSame( 20, $current['assignee_user_id'] );
		$this->assertNull( $current['artist_term_id'] );
		$this->assertNull( $current['artist_profile_id'] );
		$this->assertCount( $activity_count, ( new BookingActivityRepository() )->list_for_booking( $completed['id'] ) );
		$completed_state = ( new BookingActivityRepository() )->event_conversion_state( $completed['id'], $completed['public_id'] );
		$this->assertSame( 'completed', $completed_state['status'] );
		$this->assertFalse( $completed_state['pending'] );
		$this->assertSame( 'existing', $this->service()->convert( $completed['id'], 1, 12 )['event_action'] );
	}

	/** @dataProvider explicitUpstreamErrorProvider */
	public function test_explicit_upstream_errors_finalize_attempt_and_unblock_lifecycle( array $data, bool $retryable ): void {
		$booking = $this->booking();
		$this->install_ability( static function () use ( $data ) { return new WP_Error( 'explicit_failure', 'failed', $data ); } );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_upsert_failed', $error->get_error_code() );
		$this->assertSame( $retryable, $error->get_error_data()['retryable'] );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertFalse( $state['pending'] );
		$this->assertSame( $retryable, $state['failed']['payload']['data']['retryable'] );
		$result = ( new BookingLifecycle( null, null, new BookingTestAuthorization() ) )->transition( $booking['id'], 'cancelled', 1, 12 );
		$this->assertSame( 'cancelled', $result['status'] );
	}

	public static function explicitUpstreamErrorProvider(): array {
		return array( 'retryable' => array( array( 'status' => 503, 'retryable' => true ), true ), 'nonretryable' => array( array( 'status' => 422 ), false ) );
	}

	public function test_retry_after_failure_starts_attempt_two_and_succeeds(): void {
		$booking = $this->booking();
		$this->install_ability( static function () { return new WP_Error( 'first_failed', 'failed', array( 'status' => 422 ) ); } );
		$this->service()->convert( $booking['id'], 1, 12 );
		$this->install_ability( function ( $input ) { return $this->upstream_result( $input, array( 'success' => false ) ); } );
		$this->service()->convert( $booking['id'], 1, 12 );
		$pending = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 2, $pending['attempt'] );
		$this->assertTrue( $pending['pending'] );
		$error = ( new BookingLifecycle( null, null, new BookingTestAuthorization() ) )->transition( $booking['id'], 'cancelled', 1, 12 );
		$this->assertSame( 'booking_event_conversion_pending', $error->get_error_code() );
		$ability = $this->install_ability();
		$result  = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'created', $result['event_action'] );
		$this->assertCount( 1, $ability->calls );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 'completed', $state['status'] );
		$this->assertSame( 2, $state['attempt'] );
		$this->assertSame( 2, $state['completed']['payload']['data']['attempt'] );
		$this->assertSame( 'existing', $this->service()->convert( $booking['id'], 1, 12 )['event_action'] );
	}

	public function test_failure_marker_transaction_failure_preserves_upstream_and_pending_attempt(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->fail_activity_kinds = array( 'event_conversion_failed' );
		$this->install_ability( static function () { return new WP_Error( 'explicit_failure', 'failed', array( 'status' => 422 ) ); } );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_failure_finalize_failed', $error->get_error_code() );
		$this->assertSame( 'explicit_failure', $error->get_error_data()['upstream_code'] );
		$this->assertSame( 1, $error->get_error_data()['attempt'] );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertTrue( $state['pending'] );
		$this->assertSame( 1, $state['attempt'] );
	}

	public function test_unverified_upstream_source_venue_and_status_are_retryable_and_never_claimed(): void {
		$booking = $this->booking();
		$cases   = array(
			array( 'success' => false ),
			array( 'source' => array( 'name' => 'wrong-source' ) ),
			array( 'source' => array( 'id' => 'wrong-id' ) ),
			array( 'source' => array( 'identity' => 'wrong-identity' ) ),
			array( 'normalized' => array( 'venue_id' => 999 ) ),
			array( 'normalized' => array( 'post_status' => 'draft' ) ),
		);
		foreach ( $cases as $override ) {
			$this->install_ability( function ( $input ) use ( $override ) { return $this->upstream_result( $input, $override ); } );
			$error = $this->service()->convert( $booking['id'], 1, 12 );
			$this->assertSame( 'booking_event_upsert_failed', $error->get_error_code() );
			$this->assertSame( 502, $error->get_error_data()['status'] );
			$this->assertTrue( $error->get_error_data()['retryable'] );
			$this->assertNull( ( new BookingRepository() )->get( $booking['id'] )['event_id'] );
		}
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertCount( 1, $activities );
		$this->assertSame( 'event_conversion_started', $activities[0]['kind'] );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 1, $state['attempt'] );
		$this->assertTrue( $state['pending'] );
	}

	public function test_malformed_and_colliding_conversion_markers_fail_closed(): void {
		$repository = new BookingActivityRepository();
		$booking    = $this->booking();
		$identity   = hash( 'sha256', 'extrachill-events-booking' . "\0" . $booking['public_id'] );
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_conversion_started', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_conversion_started', $booking['public_id'] ), 'payload' => array( 'attempt' => 1, 'source' => 'wrong', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'expected_version' => 1 ) ) );
		$this->assertSame( 'booking_event_conversion_state_invalid', $repository->event_conversion_state( $booking['id'], $booking['public_id'] )->get_error_code() );

		$booking = $this->booking();
		$identity = hash( 'sha256', 'extrachill-events-booking' . "\0" . $booking['public_id'] );
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_conversion_started', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_conversion_started', $booking['public_id'] ), 'payload' => array( 'attempt' => 1, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'expected_version' => 1 ) ) );
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_converted', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_converted', $booking['public_id'] ), 'payload' => array( 'attempt' => 1, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'event_id' => 901 ) ) );
		$this->assertSame( 'booking_event_conversion_state_invalid', $repository->event_conversion_state( $booking['id'], $booking['public_id'] )->get_error_code() );

		$booking = $this->booking();
		$identity = hash( 'sha256', 'extrachill-events-booking' . "\0" . $booking['public_id'] );
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_conversion_started', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_conversion_started', $booking['public_id'] ), 'payload' => array( 'attempt' => 1, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'expected_version' => 1 ) ) );
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'event_conversion_failed', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_conversion_failed', $booking['public_id'] ), 'payload' => array( 'attempt' => 1, 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'], 'source_identity' => $identity, 'upstream_code' => 'failed', 'retryable' => false ) ) );
		$this->assertSame( 'booking_event_conversion_state_invalid', $repository->event_conversion_state( $booking['id'], $booking['public_id'] )->get_error_code() );

		$booking = $this->booking();
		$repository->append( array( 'booking_id' => $booking['id'], 'kind' => 'status_changed', 'idempotency_key' => sprintf( 'event-conversion:%s:1:event_conversion_started', $booking['public_id'] ), 'payload' => array() ) );
		$error = $repository->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 'booking_event_conversion_state_invalid', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['repairable'] );
	}

	public function test_immediate_retry_returns_existing_without_dme_call_even_with_stale_version(): void {
		$ability = $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event'];
		$booking = $this->booking();
		$this->service()->convert( $booking['id'], 1, 12 );
		$result = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'existing', $result['event_action'] );
		$this->assertTrue( $result['already_converted'] );
		$this->assertSame( 2, $result['booking_version'] );
		$this->assertCount( 1, $ability->calls );
		$this->assertCount( 2, ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) );
		$state = ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] );
		$this->assertSame( 1, $state['completed']['payload']['data']['attempt'] );
	}

	public function test_external_success_activity_failure_rolls_back_claim_and_retry_reuses_event(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->fail_activity_kinds = array( 'event_converted' );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_activity_write_failed', $error->get_error_code() );
		$this->assertNull( ( new BookingRepository() )->get( $booking['id'] )['event_id'] );
		$GLOBALS['wpdb']->fail_activity_kinds = array();
		$ability = $this->install_ability( function ( $input ) {
			$this->add_event( 901 );
			$this->add_event_source_proof( 901, $input );
			return $this->upstream_result( $input, array( 'action' => 'no_change' ) );
		} );
		$result  = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'no_change', $result['event_action'] );
		$this->assertSame( 901, $result['event_id'] );
		$this->assertCount( 1, $ability->calls );
	}

	public function test_locked_authorization_revocation_is_enforced_in_preflight_and_finalize(): void {
		$authorization = new BookingTestAuthorization();
		$booking       = $this->booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) { $authorization->allowed['12:55'] = false; };
		$this->assertSame( 'venue_action_forbidden', $this->service( $authorization )->convert( $booking['id'], 1, 12 )->get_error_code() );
		$authorization = new BookingTestAuthorization();
		$booking       = $this->booking();
		$this->install_ability( function ( $input ) use ( $authorization ) {
			$this->add_event( 901 );
			$this->add_event_source_proof( 901, $input );
			$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) { $authorization->allowed['12:55'] = false; };
			return $this->upstream_result( $input );
		} );
		$this->assertSame( 'venue_action_forbidden', $this->service( $authorization )->convert( $booking['id'], 1, 12 )->get_error_code() );
		$this->assertNull( ( new BookingRepository() )->get( $booking['id'] )['event_id'] );
		$this->assertTrue( ( new BookingActivityRepository() )->event_conversion_state( $booking['id'], $booking['public_id'] )['pending'] );
	}

	public function test_same_event_concurrent_finalize_returns_existing_and_different_event_fails(): void {
		$booking = $this->booking();
		$this->install_ability( function ( $input ) use ( $booking ) {
			$this->add_event( 901 );
			$this->add_event_source_proof( 901, $input );
			$GLOBALS['wpdb']->after_membership_lock = function () use ( $booking ) {
				$GLOBALS['wpdb']->simulate_external_commit(
					function () use ( $booking ) {
						$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['event_id'] = 901;
						$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['version']  = 2;
						$this->add_completed_conversion( $booking, 901 );
					}
				);
			};
			return $this->upstream_result( $input );
		} );
		$this->assertSame( 'existing', $this->service()->convert( $booking['id'], 1, 12 )['event_action'] );
		$booking = $this->booking();
		$this->install_ability( function ( $input ) use ( $booking ) {
			$this->add_event( 901 ); $this->add_event( 902 );
			$this->add_event_source_proof( 901, $input );
			$this->add_event_source_proof( 902, $input );
			$GLOBALS['wpdb']->after_membership_lock = function () use ( $booking ) {
				$GLOBALS['wpdb']->simulate_external_commit(
					function () use ( $booking ) {
						$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['event_id'] = 902;
						$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['version']  = 2;
						$this->add_completed_conversion( $booking, 902 );
					}
				);
			};
			return $this->upstream_result( $input );
		} );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_already_linked', $error->get_error_code() );
		$this->assertSame( 409, $error->get_error_data()['status'] );
	}

	public function test_finalize_commit_uncertainty_never_rolls_back_external_event(): void {
		$booking = $this->booking();
		$this->install_ability( function ( $input ) {
			$this->add_event( 901 );
			$this->add_event_source_proof( 901, $input );
			$GLOBALS['wpdb']->fail_transaction_commit = true;
			return $this->upstream_result( $input );
		} );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_finalize_uncertain', $error->get_error_code() );
		$this->assertTrue( $error->get_error_data()['retryable'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->rollback_queries );
		$this->assertNotNull( get_post( 901 ) );
	}

	public function test_existing_event_must_be_a_valid_site_local_dme_post(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['event_id'] = 901;
		$this->add_event( 901, 'post' );
		$this->assertSame( 'booking_event_existing_invalid', $this->service()->convert( $booking['id'], 999, 12 )->get_error_code() );
		$this->add_event( 901 );
		$input = array( 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'] );
		$this->add_event_source_proof( 901, $input );
		$this->add_completed_conversion( $booking );
		$result = $this->service()->convert( $booking['id'], 999, 12 );
		$this->assertSame( 'existing', $result['event_action'] );
		$this->assertCount( 0, $GLOBALS['ec_artist_test']['ability_objects']['data-machine-events/upsert-event']->calls );
	}

	public function test_existing_draft_private_wrong_source_wrong_venue_and_missing_activity_are_rejected(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['event_id'] = 901;
		$input = array( 'source' => 'extrachill-events-booking', 'source_id' => $booking['public_id'] );
		$this->add_event( 901, 'data_machine_events', 'draft' );
		$this->add_event_source_proof( 901, $input );
		$this->add_completed_conversion( $booking );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertContains( 'post_status', $error->get_error_data()['integrity'] );
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_status = 'private';
		$this->assertSame( 'booking_event_existing_invalid', $this->service()->convert( $booking['id'], 1, 12 )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_status = 'publish';
		$GLOBALS['ec_artist_test']['post_meta'][7][901]['_datamachine_event_source'] = 'wrong';
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertContains( 'source_name', $error->get_error_data()['integrity'] );
		$GLOBALS['ec_artist_test']['post_meta'][7][901]['_datamachine_event_source'] = 'extrachill-events-booking';
		$GLOBALS['ec_artist_test']['event_venues'][7][901] = array( 999 );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertContains( 'venue', $error->get_error_data()['integrity'] );

		$missing = $this->booking();
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $missing['id'] ]['event_id'] = 902;
		$this->add_event( 902 );
		$this->add_event_source_proof( 902, array( 'source' => 'extrachill-events-booking', 'source_id' => $missing['public_id'] ) );
		$error = $this->service()->convert( $missing['id'], 1, 12 );
		$this->assertSame( 'booking_event_existing_invalid', $error->get_error_code() );
		$this->assertContains( 'event_converted_activity', $error->get_error_data()['integrity'] );
		$this->assertTrue( $error->get_error_data()['repairable'] );
	}

	public function test_nested_dme_permission_error_runs_honestly_and_is_wrapped(): void {
		$booking = $this->booking();
		$this->install_ability( static function () { return new WP_Error( 'dme_write_forbidden', 'forbidden', array( 'status' => 403 ) ); } );
		$error = $this->service()->convert( $booking['id'], 1, 12 );
		$this->assertSame( 'booking_event_upsert_failed', $error->get_error_code() );
		$this->assertSame( 422, $error->get_error_data()['status'] );
		$this->assertSame( 'dme_write_forbidden', $error->get_error_data()['upstream_code'] );
	}
}
