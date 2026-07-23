<?php
/**
 * Booking detail, production, deal, and performance mutation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingMutationAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingMutationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingMutationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array(),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'fired_actions' => array(),
			'scheduled'     => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'          => array(
				7 => array(
					55 => array(
						'_venue_timezone'            => 'America/New_York',
						VenueBookingConfig::META_KEY => array(
							'enabled'          => true,
							'hold_ttl_minutes' => 30,
							'spaces'           => array(
								array(
									'key'        => 'main-room',
									'name'       => 'Main Room',
									'is_default' => true,
								),
								array(
									'key'  => 'patio',
									'name' => 'Patio',
								),
							),
						),
					),
				),
			),
			'posts'         => array(),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	private function booking( string $status = 'submitted', array $overrides = array() ): array {
		$booking = ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id' => 55,
					'artist_name'   => 'Test Band',
					'intake'        => array( 'draw' => 50 ),
				),
				$overrides
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = $status;
		$booking['status'] = $status;
		return $booking;
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
				'ticket_url'                  => 'https://tickets.example.com/test-band',
				'additional_terms'           => 'Merch is 100% artist.',
			),
			$overrides
		);
	}

	private function production(): array {
		return array(
			'version'              => 1,
			'support_requirements' => array( 'Local opener' ),
			'support_offers'       => array( 'House drums' ),
			'production_notes'     => 'Six inputs.',
		);
	}

	private function service( ?BookingTestAuthorization $authorization = null ): BookingMutationService {
		return new BookingMutationService( null, null, $authorization ? $authorization : new BookingTestAuthorization() );
	}

	public function test_schema_and_public_inquiry_separate_requested_from_authoritative_fields(): void {
		$this->assertSame( '6', BookingSchema::SCHEMA_VERSION );
		$this->assertTrue( BookingSchema::install() );
		$columns = $GLOBALS['wpdb']->schemas[ BookingSchema::bookings_table() ]['columns'];
		foreach ( array( 'requested_space_key', 'performance_start_at', 'performance_end_at', 'production_payload', 'confirmed_deal_payload' ) as $column ) {
			$this->assertArrayHasKey( $column, $columns );
		}
		$this->assertSame( array( 'venue_term_id', 'performance_start_at' ), $GLOBALS['wpdb']->schemas[ BookingSchema::bookings_table() ]['indexes']['venue_performance_start']['columns'] );

		$lifecycle = new BookingLifecycle();
		$created   = $lifecycle->create_inquiry(
			array(
				'idempotency_key'      => 'separation',
				'venue_term_id'        => 55,
				'artist_name'          => 'Band',
				'intake'               => array(),
				'requested_space_key'  => 'patio',
				'requested_start_at'   => '2030-02-01 20:00:00',
				'requested_end_at'     => '2030-02-01 23:00:00',
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-03-01 20:00:00',
				'performance_end_at'   => '2030-03-01 23:00:00',
				'deal'                 => $this->deal(),
			)
		);
		$this->assertSame( 'patio', $created['requested_space_key'] );
		$this->assertNull( $created['space_key'] );
		$this->assertNull( $created['performance_start_at'] );
		$this->assertNull( $created['deal'] );
	}

	public function test_intake_status_matrix_null_clearing_noop_stale_and_private_activity(): void {
		$service = $this->service();
		foreach ( array( 'submitted', 'needs_info', 'under_review', 'negotiating' ) as $status ) {
			$booking = $this->booking( $status, array( 'contact_phone' => '843-555-0100' ) );
			$result  = $service->correct_intake(
				$booking['id'],
				1,
				array(
					'contact_phone'       => null,
					'requested_space_key' => ' Patio ',
				),
				12
			);
			$this->assertNull( $result['contact_phone'], $status );
			$this->assertSame( 'patio', $result['requested_space_key'], $status );
			$this->assertSame( 2, $result['version'], $status );
			$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] )[0];
			$this->assertSame( 'intake_corrected', $activity['kind'] );
			$this->assertSame( 12, $activity['actor_id'] );
			$this->assertArrayHasKey( 'before', $activity['payload']['data'] );
			$this->assertSame( 'booking_version_conflict', $service->correct_intake( $booking['id'], 1, array( 'contact_name' => 'Stale' ), 12 )->get_error_code() );
			$noop = $service->correct_intake( $booking['id'], 2, array( 'requested_space_key' => 'patio' ), 12 );
			$this->assertSame( 2, $noop['version'] );
		}
		foreach ( array( 'held', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$booking = $this->booking( $status );
			$this->assertSame( 'booking_mutation_status_forbidden', $service->correct_intake( $booking['id'], 1, array( 'contact_name' => 'No' ), 12 )->get_error_code(), $status );
		}
		$this->assertSame( 'booking_intake_correction_required', $service->correct_intake( 1, 1, array(), 12 )->get_error_code() );
	}

	public function test_intake_ranges_validate_final_values_and_activity_failure_rolls_back(): void {
		$service = $this->service();
		$booking = $this->booking(
			'under_review',
			array(
				'requested_start_at' => '2030-02-01 20:00:00',
				'requested_end_at'   => '2030-02-01 23:00:00',
			)
		);
		$this->assertSame( 'invalid_booking_date_range', $service->correct_intake( $booking['id'], 1, array( 'requested_end_at' => '2030-02-01 19:00:00' ), 12 )->get_error_code() );
		$this->assertSame( 'invalid_booking_datetime', $service->correct_intake( $booking['id'], 1, array( 'requested_start_at' => 'tomorrow' ), 12 )->get_error_code() );
		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result                                 = $service->correct_intake( $booking['id'], 1, array( 'contact_name' => 'Changed' ), 12 );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertNull( ( new BookingRepository() )->get( $booking['id'] )['contact_name'] );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
	}

	public function test_production_and_deal_contracts_statuses_bounds_and_noops(): void {
		$service = $this->service();
		foreach ( array( 'under_review', 'negotiating', 'held' ) as $status ) {
			$booking = $this->booking( $status );
			$result  = $service->update_production( $booking['id'], 1, $this->production(), 12 );
			$this->assertSame( $this->production(), $result['production']['data'], $status );
			$this->assertSame( 2, $result['version'] );
			$this->assertSame( 2, $service->update_production( $booking['id'], 2, $this->production(), 12 )['version'] );
		}
		$this->assertSame( 'invalid_booking_production', BookingMutationService::normalize_production_document( array( 'version' => 1 ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_production', BookingMutationService::normalize_production_document( array( 'version' => 1, 'support_requirements' => array(), 'support_offers' => array() ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_production', BookingMutationService::normalize_production_document( array( 'version' => 1, 'support_requirements' => array( 7 ), 'support_offers' => array(), 'production_notes' => null ) )->get_error_code() );
		$this->assertSame(
			'invalid_booking_production',
			BookingMutationService::normalize_production_document(
				array(
					'version'              => 1,
					'support_requirements' => array_fill( 0, 51, 'x' ),
					'support_offers'       => array(),
					'production_notes'     => null,
				)
			)->get_error_code()
		);
		foreach ( array( 'submitted', 'needs_info', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$booking = $this->booking( $status );
			$this->assertSame( 'booking_mutation_status_forbidden', $service->update_production( $booking['id'], 1, $this->production(), 12 )->get_error_code(), $status );
		}

		foreach ( array( 'negotiating', 'held' ) as $status ) {
			$booking = $this->booking( $status );
			$result  = $service->update_deal( $booking['id'], 1, $this->deal(), 12 );
			$this->assertSame( $this->deal(), $result['deal']['data'], $status );
			$this->assertSame( 'deal_draft_updated', ( new BookingActivityRepository() )->list_for_booking( $booking['id'] )[0]['kind'] );
		}
		foreach ( array( 'submitted', 'needs_info', 'under_review', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$booking = $this->booking( $status );
			$this->assertSame( 'booking_mutation_status_forbidden', $service->update_deal( $booking['id'], 1, $this->deal(), 12 )->get_error_code(), $status );
		}
		foreach ( array( array( 'revenue_share_basis_points' => 10001 ), array( 'currency' => 'US1' ), array( 'capacity' => 0 ), array( 'guarantee_cents' => -1 ), array( 'tickets_on_sale_at' => 'soon' ), array( 'ticket_url' => 'javascript:alert(1)' ) ) as $invalid ) {
			$this->assertSame( 'invalid_booking_deal', BookingMutationService::normalize_deal_document( $this->deal( $invalid ) )->get_error_code() );
		}
		$this->assertSame( 'invalid_booking_deal', BookingMutationService::normalize_deal_document( $this->deal( array( 'currency' => 123 ) ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_deal', ( new BookingRepository() )->create( array( 'venue_term_id' => 55, 'artist_name' => 'Bad Deal', 'intake' => array(), 'deal' => array( 'version' => 1 ) ) )->get_error_code() );
	}

	public function test_document_object_order_does_not_create_false_revisions(): void {
		$service = $this->service();
		$booking = $this->booking( 'under_review', array( 'intake' => array( 'nested' => array( 'one' => 1, 'two' => 2 ) ) ) );
		$noop    = $service->correct_intake( $booking['id'], 1, array( 'intake' => array( 'nested' => array( 'two' => 2, 'one' => 1 ) ) ), 12 );
		$this->assertSame( 1, $noop['version'] );
		$this->assertSame( array(), ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) );

		$deal = $this->deal();
		$reordered = array_reverse( $deal, true );
		$this->assertTrue( BookingMutationService::documents_equal( BookingMutationService::normalize_deal_document( $reordered ), $reordered ) );
	}

	public function test_locked_reauthorization_and_transaction_failures_are_explicit(): void {
		$authorization                          = new BookingTestAuthorization();
		$service                                = $this->service( $authorization );
		$booking                                = $this->booking( 'negotiating' );
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$this->assertSame( 'venue_action_forbidden', $service->update_deal( $booking['id'], 1, $this->deal(), 12 )->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$authorization->allowed['12:55']          = true;
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$rollbacks                                = $GLOBALS['wpdb']->rollback_queries;
		$this->assertSame( 'booking_mutation_transaction_commit_uncertain', $service->update_production( $booking['id'], 1, $this->production(), 12 )->get_error_code() );
		$this->assertSame( $rollbacks, $GLOBALS['wpdb']->rollback_queries );
	}

	public function test_performance_selection_checks_config_conflicts_and_own_alternatives(): void {
		$service = $this->service();
		foreach ( array( 'under_review', 'negotiating' ) as $status ) {
			$candidate = $this->booking( $status );
			$result    = $service->select_performance( $candidate['id'], 1, 'patio', '2033-04-01 20:00:00', '2033-04-01 23:00:00', 12 );
			$this->assertSame( 'patio', $result['space_key'], $status );
		}
		foreach ( array( 'submitted', 'needs_info', 'held', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ) as $status ) {
			$candidate = $this->booking( $status );
			$result    = $service->select_performance( $candidate['id'], 1, 'patio', '2033-05-01 20:00:00', '2033-05-01 23:00:00', 12 );
			$this->assertSame( 'booking_performance_status_forbidden', $result->get_error_code(), $status );
		}
		$booking  = $this->booking( 'under_review' );
		$selected = $service->select_performance( $booking['id'], 1, 'main-room', '2030-04-01 20:00:00', '2030-04-01 23:00:00', 12 );
		$this->assertSame( 'main-room', $selected['space_key'] );
		$this->assertSame( 2, $selected['version'] );
		$this->assertSame( 2, $service->select_performance( $booking['id'], 2, 'main-room', '2030-04-01 20:00:00', '2030-04-01 23:00:00', 12 )['version'] );
		$this->assertSame( 'booking_performance_space_invalid', $service->select_performance( $booking['id'], 2, 'closet', '2030-04-02 20:00:00', '2030-04-02 23:00:00', 12 )->get_error_code() );
		$this->assertSame( 'invalid_booking_performance', $service->select_performance( $booking['id'], 2, 'main-room', '2030-04-02 23:00:00', '2030-04-02 20:00:00', 12 )->get_error_code() );
		$GLOBALS['wpdb']->after_venue_lock = static function () {
			$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ]['spaces'] = array(
				array(
					'key'        => 'patio',
					'name'       => 'Patio',
					'is_default' => true,
				),
			);
		};
		$this->assertSame( 'booking_performance_space_invalid', $service->select_performance( $booking['id'], 2, 'main-room', '2030-04-03 20:00:00', '2030-04-03 23:00:00', 12 )->get_error_code(), 'The configured space is reread under the venue lock.' );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ]['spaces'][] = array(
			'key'  => 'main-room',
			'name' => 'Main Room',
		);

		$other = $this->booking(
			'negotiating',
			array(
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-05-01 20:00:00',
				'performance_end_at'   => '2030-05-01 23:00:00',
			)
		);
		$holds = new BookingHoldRepository( null, null, new BookingTestAuthorization() );
		$hold  = $holds->create( $other['id'], 1, 12 );
		$this->assertSame( 'booking_time_conflict', $service->select_performance( $booking['id'], 2, 'main-room', '2030-05-01 21:00:00', '2030-05-01 22:00:00', 12 )->get_error_code() );
		$GLOBALS['wpdb']->rows[ BookingSchema::holds_table() ][ $hold['hold']['id'] ]['booking_id'] = $booking['id'];
		$this->assertSame( 3, $service->select_performance( $booking['id'], 2, 'main-room', '2030-05-01 21:00:00', '2030-05-01 22:00:00', 12 )['version'], 'Own alternative holds are excluded.' );

		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'held';
		$this->assertSame( 'booking_performance_status_forbidden', $service->select_performance( $booking['id'], 3, 'patio', '2030-06-01 20:00:00', '2030-06-01 23:00:00', 12 )->get_error_code() );
	}

	public function test_confirmation_freezes_exact_draft_and_rejects_later_mutations(): void {
		$authorization = new BookingTestAuthorization();
		$holds         = new BookingHoldRepository( null, null, $authorization );
		$service       = new BookingMutationService( null, null, $authorization, $holds );
		$lifecycle     = new BookingLifecycle( null, null, $authorization, null, $holds );
		$booking       = $this->booking(
			'negotiating',
			array(
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-07-01 20:00:00',
				'performance_end_at'   => '2030-07-01 23:00:00',
			)
		);
		$draft         = $service->update_deal( $booking['id'], 1, $this->deal(), 12 );
		$hold          = $holds->create( $booking['id'], 2, 12 );
		$held          = $lifecycle->transition( $booking['id'], 'held', 3, 12 );
		$confirmed     = $lifecycle->transition( $booking['id'], 'confirmed', $held['version'], 12, 'Approved' );
		$this->assertSame( $draft['deal'], $confirmed['confirmed_deal'] );
		$this->assertSame( $draft['deal'], ( new BookingRepository() )->get( $booking['id'] )['confirmed_deal'] );
		$this->assertSame( 'converted', $holds->get( $hold['hold']['id'] )['status'] );
		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] )[0];
		$this->assertSame( 'deal_confirmed', $activity['kind'] );
		$this->assertSame( $draft['deal'], $activity['payload']['data']['confirmed_deal'] );
		$this->assertSame( 'booking_mutation_status_forbidden', $service->update_deal( $booking['id'], $confirmed['version'], $this->deal( array( 'guarantee_cents' => 200000 ) ), 12 )->get_error_code() );
		$this->assertSame( 'booking_performance_status_forbidden', $service->select_performance( $booking['id'], $confirmed['version'], 'patio', '2030-07-02 20:00:00', '2030-07-02 23:00:00', 12 )->get_error_code() );
	}

	public function test_mutation_abilities_are_strict_visible_scoped_and_private_receipt_stays_small(): void {
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingMutationAbilities( $this->service( $authorization ), new BookingRepository(), $authorization );
		$abilities->register();
		$registered = $GLOBALS['ec_artist_test']['abilities'];
		foreach ( array( 'extrachill/correct-venue-booking-intake', 'extrachill/select-venue-booking-performance', 'extrachill/update-venue-booking-production', 'extrachill/update-venue-booking-deal' ) as $name ) {
			$this->assertArrayHasKey( $name, $registered );
			$this->assertTrue( $registered[ $name ]['meta']['show_in_rest'] );
			$this->assertFalse( $registered[ $name ]['input_schema']['additionalProperties'] );
			$this->assertFalse( $registered[ $name ]['output_schema']['additionalProperties'] );
		}
		$this->assertSame( array( 'booking_id', 'expected_version', 'deal' ), $registered['extrachill/update-venue-booking-deal']['input_schema']['required'] );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_booking( array( 'booking_id' => 999 ) )->get_error_code() );
	}
}
