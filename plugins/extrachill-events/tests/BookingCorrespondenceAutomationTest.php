<?php
/**
 * Booking lifecycle correspondence automation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
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
		$this->assertCount( 1, $queued );
		$this->assertSame( 'artist@example.com', $queued[0]['to'] );
		$this->assertSame( 'chubes@extrachill.com', $queued[0]['cc'] );
		$this->assertSame( 'booking@lofi.example', $queued[0]['reply_to'] );
		$this->assertStringContainsString( 'pending review', $queued[0]['body'] );
		$this->assertStringContainsString( 'Thursday, August 1, 2030, 8:00 PM to 11:00 PM EDT (America/New_York)', $queued[0]['body'] );
		$this->assertStringNotContainsString( ' UTC', $queued[0]['body'] );
		$this->assertStringContainsString( 'does not place a hold or confirm', $queued[0]['body'] );
		$this->assertStringContainsString( $booking['public_id'], $queued[0]['body'] );
		$this->assertStringContainsString( "Extra Chill Bot sending on Chris's behalf.", $queued[0]['body'] );
		$this->assertArrayNotHasKey( 'attachments', $queued[0] );
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
		$this->assertCount( 1, array_filter( $activities, static function ( array $row ): bool { return 'booking_message_requested' === $row['kind']; } ) );
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
			return new BookingCorrespondenceAutomationService( null, null, new BookingCommunicationService( null, null, null, $queue ) );
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
