<?php
/**
 * Booking lifecycle correspondence automation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\ArtistBookingInquiryService;
use ExtraChillEvents\Core\BookingCommunicationService;
use ExtraChillEvents\Core\BookingCorrespondenceAutomationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingCorrespondenceAutomationTest extends BookingTestCase {

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'         => 7,
			'stack'           => array(),
			'uuid'            => 0,
			'options'         => array(),
			'abilities'       => array(),
			'actions'         => array(),
			'fired_actions'   => array(),
			'scheduled'       => array(),
			'cache_deletes'   => array(),
			'terms'           => array(
				7 => array(
					55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue', 'name' => 'Lo-Fi Brewing' ),
					56 => (object) array( 'term_id' => 56, 'taxonomy' => 'venue', 'name' => 'Other Room' ),
				),
			),
			'meta'            => array(
				7 => array(
					55 => array(
						'_venue_timezone'             => 'America/New_York',
						VenueBookingConfig::META_KEY => array(
							'enabled'        => true,
							'spaces'         => array( array( 'key' => 'main-room', 'name' => 'Main Room', 'is_default' => true ) ),
							'correspondence' => array( 'booking_address' => 'booking@lofi.example' ),
						),
					),
					56 => array(
						'_venue_timezone'             => 'America/Chicago',
						VenueBookingConfig::META_KEY => array( 'enabled' => true ),
					),
				),
			),
			'posts'           => array(),
			'post_meta'       => array(),
		);
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	private function booking( array $overrides = array() ): array {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id'       => 55,
					'artist_name'         => 'Test Band',
					'contact_name'        => 'Band Manager',
					'contact_email'       => 'artist@example.com',
					'requested_space_key' => 'main-room',
					'requested_start_at'  => '2030-08-01 20:00:00',
					'requested_end_at'    => '2030-08-01 23:00:00',
					'inquiry_idempotency_key' => 'receipt-' . wp_generate_uuid4(),
					'inquiry_request_hash'    => hash( 'sha256', wp_generate_uuid4() ),
					'intake'              => array(),
				),
				$overrides
			)
		);
	}

	private function source( array $booking, string $kind ): array {
		return ( new BookingActivityRepository() )->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => $kind,
				'actor_type'      => 'system',
				'idempotency_key' => $kind . ':' . $booking['id'],
				'payload'         => array( 'status' => $booking['status'] ),
			)
		);
	}

	private function service( array &$queued, bool $success = true ): BookingCorrespondenceAutomationService {
		$communication = new BookingCommunicationService(
			null,
			null,
			null,
			static function ( array $input ) use ( &$queued, $success ) {
				$queued[] = $input;
				return $success ? array( 'success' => true, 'action_id' => 100 + count( $queued ) ) : array( 'success' => false, 'error' => 'temporary' );
			},
			null,
			null,
			null,
			null,
			static function (): array {
				return array( 'owner@example.com', 'operator@example.com' );
			}
		);
		return new BookingCorrespondenceAutomationService( null, null, $communication );
	}

	public function test_committed_inquiry_receipt_is_threaded_private_and_replay_safe(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();
		$service = $this->service( $queued );

		$this->assertSame( array( 'completed' => true ), $service->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'completed' => true ), $service->reconcile_source( $source['id'] ) );
		$this->assertCount( 2, $queued );
		$this->assertSame( 'artist@example.com', $queued[0]['to'] );
		$this->assertSame( '', $queued[0]['cc'] );
		$this->assertSame( 'Extra Chill Bookings', $queued[0]['from_name'] );
		$this->assertSame( 'booking@lofi.example', $queued[0]['reply_to'] );
		$this->assertSame( 'Booking inquiry received: Test Band at Lo-Fi Brewing - Aug 1', $queued[0]['subject'] );
		$this->assertStringNotContainsString( $booking['public_id'], $queued[0]['subject'] );
		$this->assertStringContainsString( 'pending review', $queued[0]['body'] );
		$this->assertStringContainsString( 'Thursday, August 1, 2030, 8:00 PM to 11:00 PM EDT (America/New_York)', $queued[0]['body'] );
		$this->assertStringNotContainsString( ' UTC', $queued[0]['body'] );
		$this->assertStringContainsString( 'does not place a hold or confirm', $queued[0]['body'] );
		$this->assertStringContainsString( $booking['public_id'], $queued[0]['body'] );
		$capability = ArtistBookingInquiryService::capability_for( $booking );
		$this->assertStringContainsString( 'Access code: ' . $capability, $queued[0]['body'] );
		$this->assertStringNotContainsString( $capability, $queued[0]['subject'] );
		$this->assertStringNotContainsString( $capability, wp_json_encode( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] ) );
		$this->assertStringContainsString( 'Powered by Extra Chill', $queued[0]['body'] );
		$this->assertStringNotContainsString( 'Extra Chill Bot', $queued[0]['body'] );
		$this->assertStringNotContainsString( 'Chris', $queued[0]['body'] );
		$this->assertArrayNotHasKey( 'attachments', $queued[0] );
	}

	/** The venue email carries every operator fact the bell notification omits. */
	public function test_venue_inquiry_email_carries_operator_facts_and_replies_to_the_artist(): void {
		$booking = $this->booking( array( 'intake' => array( 'message' => 'We tour through in August and would love a Thursday.' ) ) );
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();
		$service = $this->service( $queued );

		$this->assertSame( array( 'completed' => true ), $service->reconcile_source( $source['id'] ) );
		$this->assertCount( 2, $queued );
		$venue = $queued[1];
		$this->assertSame( 'operator@example.com,owner@example.com', $venue['to'] );
		$this->assertSame( '', $venue['cc'] );
		$this->assertSame( 'artist@example.com', $venue['reply_to'] );
		$this->assertSame( 'Extra Chill Bookings', $venue['from_name'] );
		$this->assertSame( 'New booking inquiry: Test Band at Lo-Fi Brewing - Aug 1', $venue['subject'] );
		$this->assertStringContainsString( 'Artist: Test Band', $venue['body'] );
		$this->assertStringContainsString( 'Contact: Band Manager', $venue['body'] );
		$this->assertStringContainsString( 'Thursday, August 1, 2030, 8:00 PM to 11:00 PM EDT (America/New_York)', $venue['body'] );
		$this->assertStringNotContainsString( ' UTC', $venue['body'] );
		$this->assertStringContainsString( 'Requested space: Main Room', $venue['body'] );
		$this->assertStringContainsString( 'Reference: ' . $booking['public_id'], $venue['body'] );
		$this->assertStringContainsString( 'We tour through in August and would love a Thursday.', $venue['body'] );
		$this->assertStringContainsString( 'https://events.example/venue-settings/?venue_id=55&booking_id=' . $booking['id'] . '#tab-calendar', $venue['body'] );
		$this->assertStringContainsString( 'Powered by Extra Chill', $venue['body'] );
		$this->assertStringNotContainsString( ArtistBookingInquiryService::capability_for( $booking ), wp_json_encode( $venue ) );
	}

	/** Replays and reconciliation passes reuse the per-template idempotency key. */
	public function test_venue_inquiry_email_is_idempotent_under_replay_and_reconciliation(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();

		$this->assertSame( array( 'completed' => true ), $this->service( $queued )->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'completed' => true ), $this->service( $queued )->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'completed' => 0 ), $this->service( $queued )->reconcile_pending() );
		$this->assertCount( 2, $queued );

		$requests = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( array $row ): bool {
					return 'booking_message_requested' === $row['kind'];
				}
			)
		);
		$this->assertCount( 2, $requests );
		$keys = array_map(
			static function ( array $row ): string {
				return (string) $row['idempotency_key'];
			},
			$requests
		);
		sort( $keys );
		$this->assertSame(
			array(
				'booking-message-request:automatic:inquiry_receipt:' . $source['id'] . ':' . $booking['id'],
				'booking-message-request:automatic:inquiry_received_venue:' . $source['id'] . ':' . $booking['id'],
			),
			$keys
		);
	}

	/** A crash between the two legs resumes at the venue email, never the receipt. */
	public function test_venue_leg_failure_leaves_the_source_open_without_resending_the_artist_receipt(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$calls   = array();
		$fail    = true;
		$queue   = static function ( array $input ) use ( &$calls, &$fail ) {
			$calls[] = $input['to'];
			if ( $fail && 'artist@example.com' !== $input['to'] ) {
				return array( 'success' => false, 'error' => 'temporary' );
			}
			return array( 'success' => true, 'action_id' => 300 + count( $calls ) );
		};
		$automation = static function () use ( $queue ): BookingCorrespondenceAutomationService {
			$recipients = static function (): array {
				return array( 'owner@example.com' );
			};
			return new BookingCorrespondenceAutomationService( null, null, new BookingCommunicationService( null, null, null, $queue, null, null, null, null, $recipients ) );
		};

		$first = $automation()->reconcile_source( $source['id'] );
		$this->assertSame( 'booking_message_queue_failed', $first->get_error_code() );
		$this->assertSame( array( 'artist@example.com', 'owner@example.com' ), $calls );
		$this->assertEmpty(
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( array $row ): bool {
					return 'booking_correspondence_source_completed' === $row['kind'];
				}
			)
		);

		$fail = false;
		$this->assertSame( array( 'completed' => true ), $automation()->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'artist@example.com', 'owner@example.com', 'owner@example.com' ), $calls );
	}

	/** Missing venue recipients degrade without failing the inquiry itself. */
	public function test_unavailable_venue_recipients_degrade_without_duplicating_the_artist_receipt(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();
		$queue   = static function ( array $input ) use ( &$queued ) {
			$queued[] = $input;
			return array( 'success' => true, 'action_id' => 400 + count( $queued ) );
		};
		$empty   = static function (): array {
			return array();
		};
		$result  = ( new BookingCorrespondenceAutomationService( null, null, new BookingCommunicationService( null, null, null, $queue, null, null, null, null, $empty ) ) )->reconcile_source( $source['id'] );

		$this->assertSame( 'booking_correspondence_venue_recipients_unavailable', $result->get_error_code() );
		$this->assertCount( 0, $queued );
		$this->assertSame( 'submitted', ( new BookingRepository() )->get( $booking['id'] )['status'] );
	}

	public function test_authenticated_receipt_omits_anonymous_access_code(): void {
		$booking = $this->booking( array( 'submitter_user_id' => 44 ) );
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();
		$this->service( $queued )->reconcile_source( $source['id'] );
		$this->assertCount( 2, $queued );
		$this->assertStringNotContainsString( 'Access code:', $queued[0]['body'] );
		$this->assertStringNotContainsString( ArtistBookingInquiryService::capability_for( $booking ), wp_json_encode( $queued ) );
		$this->assertSame( '', $queued[0]['cc'] );
	}

	public function test_capability_receipt_executes_real_queued_email_cc_string_contract(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$ability = new class() {
			public $inputs = array();

			public function get_input_schema(): array {
				return array(
					'type'       => 'object',
					'properties' => array(
						'cc' => array( 'type' => 'string' ),
					),
					'required'   => array( 'cc' ),
				);
			}

			public function execute( array $input ) {
				$schema = $this->get_input_schema();
				if ( ! isset( $input['cc'] ) || 'string' !== $schema['properties']['cc']['type'] || ! is_string( $input['cc'] ) ) {
					return new WP_Error( 'ability_input_schema_invalid' );
				}
				$this->inputs[] = $input;
				return array( 'success' => true, 'action_id' => 501 );
			}
		};
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/send-email-queued'] = $ability;
		$communication = new BookingCommunicationService(
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			null,
			static function (): array {
				return array( 'owner@example.com' );
			}
		);
		$service = new BookingCorrespondenceAutomationService( null, null, $communication );

		$this->assertSame( array( 'completed' => true ), $service->reconcile_source( $source['id'] ) );
		$this->assertCount( 2, $ability->inputs );
		$this->assertSame( 'string', $ability->get_input_schema()['properties']['cc']['type'] );
		$this->assertSame( '', $ability->inputs[0]['cc'] );
		$this->assertSame( '', $ability->inputs[1]['cc'] );
		$this->assertStringContainsString( 'Access code:', $ability->inputs[0]['body'] );
		$this->assertStringNotContainsString( 'Access code:', $ability->inputs[1]['body'] );
	}

	public function test_failed_queue_retries_from_durable_intent_without_duplicate_request(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'inquiry_submitted' );
		$queued  = array();
		$failed  = $this->service( $queued, false )->reconcile_source( $source['id'] );
		$this->assertSame( 'booking_message_queue_failed', $failed->get_error_code() );

		$recovered = $this->service( $queued, true )->reconcile_source( $source['id'] );
		$this->assertSame( array( 'completed' => true ), $recovered );
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 2, array_filter( $activities, static function ( array $row ): bool { return 'booking_message_requested' === $row['kind']; } ) );
	}

	public function test_receipt_fails_closed_for_nonexistent_and_ambiguous_venue_times(): void {
		$queued = array();
		foreach (
			array(
				array( '2030-03-10 02:30:00', '2030-03-10 03:30:00' ),
				array( '2030-11-03 01:30:00', '2030-11-03 02:30:00' ),
			) as $interval
		) {
			$booking = $this->booking(
				array(
					'requested_start_at' => $interval[0],
					'requested_end_at'   => $interval[1],
				)
			);
			$source = $this->source( $booking, 'inquiry_submitted' );
			$result = $this->service( $queued )->reconcile_source( $source['id'] );
			$this->assertSame( 'booking_correspondence_interval_invalid', $result->get_error_code() );
		}
		$this->assertCount( 0, $queued );
	}

	public function test_confirmation_notifies_only_current_half_open_same_space_competitors(): void {
		$selected = $this->booking(
			array(
				'artist_name'          => 'Selected Artist',
				'contact_email'        => 'selected@example.com',
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-08-02 00:00:00',
				'performance_end_at'   => '2030-08-02 03:00:00',
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $selected['id'] ]['status'] = 'confirmed';
		$selected['status'] = 'confirmed';
		$overlap = $this->booking( array( 'artist_name' => 'Overlap', 'contact_email' => 'overlap@example.com', 'requested_start_at' => '2030-08-01 22:00:00', 'requested_end_at' => '2030-08-02 00:00:00' ) );
		$this->booking( array( 'artist_name' => 'Adjacent', 'contact_email' => 'adjacent@example.com', 'requested_start_at' => '2030-08-01 23:00:00', 'requested_end_at' => '2030-08-02 01:00:00' ) );
		$this->booking( array( 'venue_term_id' => 56, 'artist_name' => 'Elsewhere', 'contact_email' => 'elsewhere@example.com', 'requested_space_key' => 'main-room' ) );
		$terminal = $this->booking( array( 'artist_name' => 'Declined', 'contact_email' => 'declined@example.com' ) );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $terminal['id'] ]['status'] = 'declined';
		$source = $this->source( $selected, 'deal_confirmed' );
		$queued = array();

		$this->assertSame( array( 'completed' => true ), $this->service( $queued )->reconcile_source( $source['id'] ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( 'overlap@example.com', $queued[0]['to'] );
		$this->assertSame( 'Booking date update: Overlap at Lo-Fi Brewing - Aug 1', $queued[0]['subject'] );
		$this->assertStringContainsString( 'has been filled', $queued[0]['body'] );
		$this->assertStringContainsString( 'reply to this email with another exact date', $queued[0]['body'] );
		$this->assertStringContainsString( 'Thursday, August 1, 2030, 10:00 PM to Friday, August 2, 2030, 12:00 AM EDT (America/New_York)', $queued[0]['body'] );
		$this->assertStringNotContainsString( ' UTC', $queued[0]['body'] );
		$this->assertStringNotContainsString( 'Selected Artist', $queued[0]['body'] );
		$this->assertStringNotContainsString( $selected['public_id'], $queued[0]['body'] );
		$this->assertSame( 'submitted', ( new BookingRepository() )->get( $overlap['id'] )['status'] );
	}

	public function test_partial_competing_fanout_retries_only_the_failed_recipient(): void {
		$selected = $this->booking(
			array(
				'contact_email'        => 'selected@example.com',
				'space_key'            => 'main-room',
				'performance_start_at' => '2030-08-02 00:00:00',
				'performance_end_at'   => '2030-08-02 03:00:00',
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $selected['id'] ]['status'] = 'confirmed';
		$selected['status'] = 'confirmed';
		$this->booking( array( 'contact_email' => 'first@example.com' ) );
		$this->booking( array( 'contact_email' => 'second@example.com' ) );
		$source = $this->source( $selected, 'deal_confirmed' );
		$calls  = array();
		$fail   = true;
		$queue  = static function ( array $input ) use ( &$calls, &$fail ) {
			$calls[] = $input['to'];
			if ( $fail && 'second@example.com' === $input['to'] ) {
				return array( 'success' => false, 'error' => 'temporary' );
			}
			return array( 'success' => true, 'action_id' => 200 + count( $calls ) );
		};
		$automation = static function () use ( $queue ): BookingCorrespondenceAutomationService {
			$recipients = static function (): array {
				return array( 'owner@example.com', 'operator@example.com' );
			};
			return new BookingCorrespondenceAutomationService( null, null, new BookingCommunicationService( null, null, null, $queue, null, null, null, null, $recipients ) );
		};

		$first = $automation()->reconcile_source( $source['id'] );
		$this->assertSame( 'booking_message_queue_failed', $first->get_error_code() );
		$fail = false;
		$this->assertSame( array( 'completed' => true ), $automation()->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'completed' => true ), $automation()->reconcile_source( $source['id'] ) );
		$this->assertSame( array( 'first@example.com', 'second@example.com', 'second@example.com' ), $calls );
		$activities = array_values( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertCount( 2, array_filter( $activities, static function ( array $row ): bool { return 'booking_message_requested' === $row['kind']; } ) );
	}
}
