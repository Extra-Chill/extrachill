<?php
/**
 * Booking privacy, retention, and diagnostics tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingPrivacyService;
use ExtraChillEvents\Core\BookingSchema;

// Repository tests use PSR-4 filenames and concise method names as descriptions.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Covers Core privacy integration and the dry-run-first operator contract. */
final class BookingPrivacyTest extends BookingTestCase {
	/** @var BookingTestAuthorization */
	private $authorization;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'abilities'       => array(),
			'actions'         => array(),
			'blog_id'         => 7,
			'current_user_id' => 12,
			'options'         => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
		);
		$GLOBALS['wpdb']           = new BookingWpdb(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated test database double.
		$this->authorization       = new BookingTestAuthorization();
	}

	public function test_retention_defaults_cover_every_status_and_allow_only_complete_overrides(): void {
		$policy = BookingPrivacyService::retention_policy();
		$this->assertSame( 180, $policy['categories']['rejected']['contact_intake_days'] );
		$this->assertSame( 730, $policy['categories']['active']['contact_intake_days'] );
		$this->assertSame( 2555, $policy['financial_audit_days'] );
		$this->assertSame( 'deferred_to_issue_336', $policy['attachments'] );
		$this->assertSame( array(), array_diff( \ExtraChillEvents\Core\BookingRepository::STATUSES, array_keys( $policy['statuses'] ) ) );

		add_filter(
			'extrachill_events_booking_retention_policy',
			static function ( array $filtered ): array {
				$filtered['categories']['rejected']['contact_intake_days'] = 90;
				return $filtered;
			}
		);
		$this->assertSame( 90, BookingPrivacyService::retention_policy()['categories']['rejected']['contact_intake_days'] );
	}

	public function test_exporter_paginates_and_uses_fail_closed_account_and_anonymous_matching(): void {
		$bookings = array();
		for ( $id = 1; $id <= BookingPrivacyService::BATCH_SIZE + 1; ++$id ) {
			$bookings[] = $this->booking( $id );
		}
		$identities = array();
		$reader     = static function ( string $stream, array $identity, int $offset, int $limit ) use ( &$identities, $bookings ): array {
			$identities[] = $identity;
			return 'bookings' === $stream ? array_slice( $bookings, $offset, $limit ) : array();
		};
		$service    = new BookingPrivacyService( $this->authorization, static fn(): int => 44, $reader );
		$first      = $service->export( 'PERSON@example.com', 1 );
		$second     = $service->export( 'person@example.com', 2 );

		$this->assertFalse( $first['done'] );
		$this->assertCount( BookingPrivacyService::BATCH_SIZE, $first['data'] );
		$this->assertTrue( $second['done'] );
		$this->assertCount( 1, $second['data'] );
		$this->assertSame(
			array(
				'email'   => 'person@example.com',
				'user_id' => 44,
			),
			$identities[0]
		);
		$this->assertTrue(
			BookingPrivacyService::row_matches_identity(
				array(
					'submitter_user_id' => 44,
					'contact_email'     => 'other@example.com',
				),
				$identities[0]
			)
		);
		$this->assertFalse(
			BookingPrivacyService::row_matches_identity(
				array(
					'submitter_user_id' => 55,
					'contact_email'     => 'person@example.com',
				),
				$identities[0]
			),
			'Account-owned inquiries never fall back to contact email.'
		);
		$this->assertTrue(
			BookingPrivacyService::row_matches_identity(
				array(
					'submitter_user_id' => null,
					'contact_email'     => 'person@example.com',
				),
				array(
					'email'   => 'person@example.com',
					'user_id' => 0,
				)
			)
		);
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			$service->export( 'not-an-email', 1 )
		);
	}

	public function test_exporter_includes_correspondence_without_exposing_other_records(): void {
		$reader = function ( string $stream ): array {
			if ( 'bookings' === $stream ) {
				return array( $this->booking( 1 ) );
			}
			if ( 'communications' === $stream ) {
				return array(
					array(
						'id'          => 9,
						'kind'        => 'booking_message_requested',
						'occurred_at' => '2026-01-02 00:00:00',
						'payload'     => wp_json_encode(
							array(
								'version' => 1,
								'data'    => array(
									'recipient' => 'person@example.com',
									'message'   => 'Private message',
								),
							)
						),
					),
				);
			}
			return array();
		};
		$result = ( new BookingPrivacyService( $this->authorization, static fn(): int => 44, $reader ) )->export( 'person@example.com' );
		$this->assertCount( 2, $result['data'] );
		$this->assertStringContainsString( 'Private message', wp_json_encode( $result['data'] ) );
	}

	public function test_erasure_is_bounded_idempotent_and_preserves_financial_evidence(): void {
		$rows       = array( $this->booking( 1 ) );
		$financial  = array(
			'amount_due_minor' => 12500,
			'currency'         => 'USD',
		);
		$redactions = 0;
		$reader     = static function ( string $stream ) use ( &$rows ): array {
			return 'erasable' === $stream ? $rows : array();
		};
		$redactor   = static function ( array $row ) use ( &$rows, &$redactions, &$financial ): array {
			++$redactions;
			$rows = array();
			unset( $row['contact_email'], $row['contact_name'], $row['contact_phone'], $row['intake_payload'] );
			return array(
				'changed'           => true,
				'retained_evidence' => isset( $financial['amount_due_minor'] ),
			);
		};
		$service    = new BookingPrivacyService( $this->authorization, static fn(): int => 44, $reader, $redactor );
		$first      = $service->erase( 'person@example.com' );
		$replay     = $service->erase( 'person@example.com' );

		$this->assertTrue( $first['items_removed'] );
		$this->assertTrue( $first['items_retained'] );
		$this->assertTrue( $first['done'] );
		$this->assertFalse( $replay['items_removed'] );
		$this->assertSame( 1, $redactions );
		$this->assertSame( 12500, $financial['amount_due_minor'] );
		$this->assertStringNotContainsString( 'person@example.com', wp_json_encode( $first ) );
	}

	public function test_cleanup_has_dry_run_apply_parity_bounds_resume_and_status_boundaries(): void {
		$candidates = array( $this->booking( 5, 'declined' ), $this->booking( 8, 'declined' ) );
		$requests   = array();
		$redacted   = array();
		$reader     = static function ( string $stream, array $scope, int $offset, int $limit ) use ( $candidates, &$requests ): array {
			unset( $offset );
			if ( 'cleanup' !== $stream ) {
				return array();
			}
			$requests[] = $scope;
			return array_slice( array_values( array_filter( $candidates, static fn( array $row ): bool => $row['id'] > $scope['after_id'] ) ), 0, $limit );
		};
		$redactor   = static function ( array $row ) use ( &$redacted ): array {
			$redacted[] = $row['id'];
			return array(
				'changed'           => true,
				'retained_evidence' => true,
			);
		};
		$service    = new BookingPrivacyService( $this->authorization, null, $reader, $redactor );
		$input      = array(
			'operation'     => 'cleanup',
			'venue_term_id' => 55,
			'status'        => 'declined',
			'before'        => '2030-01-01 00:00:00',
			'limit'         => 1,
		);
		$dry        = $service->operate( $input, 12 );
		$apply      = $service->operate( array_merge( $input, array( 'apply' => true ) ), 12 );

		$this->assertSame( 'would_anonymize', $dry['items'][0]['action'] );
		$this->assertSame( 'anonymized', $apply['items'][0]['action'] );
		$this->assertSame( 5, $dry['next_after_id'] );
		$this->assertFalse( $dry['done'] );
		$this->assertSame( array( 5 ), $redacted );
		$this->assertSame( $requests[0]['retention_cutoff'], $requests[1]['retention_cutoff'] );
		$this->assertSame( 'declined', $requests[0]['status'] );

		$resumed = $service->operate( array_merge( $input, array( 'after_id' => 5 ) ), 12 );
		$this->assertSame( 'booking-8', $resumed['items'][0]['booking_ref'] );
		$this->assertSame(
			'invalid_booking_privacy_operation',
			$service->operate(
				array(
					'operation'     => 'cleanup',
					'venue_term_id' => 55,
					'before'        => '2030-01-01 00:00:00',
				),
				12
			)->get_error_code()
		);
	}

	public function test_cross_venue_denial_prevents_reads_and_diagnostics_are_redacted(): void {
		$reads       = 0;
		$diagnostics = static function () use ( &$reads ): array {
			++$reads;
			return array(
				'stale_holds'                => array(
					array(
						'booking_ref' => 'booking-1',
						'hold_id'     => 2,
					),
				),
				'correspondence_automation'  => array(
					array(
						'booking_ref' => 'booking-1',
						'status'      => 'failed',
					),
				),
				'stuck_event_handoffs'       => array(
					array(
						'booking_ref' => 'booking-1',
						'kind'        => 'event_sync_started',
					),
				),
				'overdue_retained_inquiries' => array(
					array(
						'booking_ref' => 'booking-1',
						'status'      => 'declined',
					),
				),
			);
		};
		$service     = new BookingPrivacyService( $this->authorization, null, null, null, $diagnostics );
		$input       = array(
			'operation'     => 'diagnose',
			'venue_term_id' => 66,
			'status'        => 'declined',
			'before'        => '2030-01-01 00:00:00',
		);
		$this->assertSame( 'venue_action_forbidden', $service->operate( $input, 12 )->get_error_code() );
		$this->assertSame( 0, $reads );

		$input['venue_term_id'] = 55;
		$result                 = $service->operate( $input, 12 );
		$this->assertSame( 1, $reads );
		$this->assertStringNotContainsString( 'contact_email', wp_json_encode( $result ) );
		$this->assertStringNotContainsString( 'message', wp_json_encode( $result ) );
	}

	private function booking( int $id, string $status = 'submitted' ): array {
		return array(
			'id'                 => $id,
			'public_id'          => 'booking-' . $id,
			'venue_term_id'      => 55,
			'submitter_user_id'  => 44,
			'artist_name'        => 'Test Artist',
			'contact_name'       => 'Private Person',
			'contact_email'      => 'person@example.com',
			'contact_phone'      => '555-0100',
			'status'             => $status,
			'requested_start_at' => '2026-02-01 01:00:00',
			'requested_end_at'   => '2026-02-01 03:00:00',
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-02 00:00:00',
			'intake_payload'     => wp_json_encode(
				array(
					'version' => 1,
					'data'    => array( 'bio' => 'Private intake' ),
				)
			),
		);
	}
}
