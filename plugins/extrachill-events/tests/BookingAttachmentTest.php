<?php
/**
 * Private booking attachment contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\BookingAttachmentAbilities;
use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingAttachmentTest extends TestCase {

	private $provider;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test']                            = array(
			'blog_id'   => 7,
			'stack'     => array(),
			'uuid'      => 0,
			'options'   => array(),
			'dbdelta'   => array(),
			'abilities' => array(),
			'actions'   => array(),
			'terms'     => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'venue',
						'name'     => 'Other Room',
					),
				),
			),
			'meta'      => array(),
			'posts'     => array(),
			'post_meta' => array(),
		);
		$GLOBALS['wpdb']                                      = new BookingWpdb();
		$GLOBALS['extrachill_events_booking_reference_lock_uncertainty'] = array();
		$GLOBALS['extrachill_events_booking_database_connection_quarantined'] = false;
		$this->provider                                       = new BookingTestPrivateFileProvider();
		$this->provider->objects['private_object_one_123456'] = $this->metadata( 'press-kit.pdf', 'application/pdf' );
		$this->provider->objects['private_object_two_123456'] = $this->metadata( 'stage-plot.pdf', 'application/pdf' );
		$this->provider->objects['private_object_three_1234'] = $this->metadata( 'rider.pdf', 'application/pdf' );
	}

	private function metadata( string $filename, string $mime, int $size = 1024 ): array {
		return array(
			'filename'     => $filename,
			'mime_type'    => $mime,
			'byte_size'    => $size,
			'content_hash' => hash( 'sha256', $filename ),
			'scan_status'  => BookingAttachmentPolicy::requires_malware_scan( $mime ) ? 'clean' : 'not_required',
		);
	}

	private function booking( int $venue = 55, array $overrides = array() ): array {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id' => $venue,
					'artist_name'   => 'New Band',
					'intake'        => array(),
				),
				$overrides
			)
		);
	}

	private function input( array $booking, array $overrides = array() ): array {
		return array_merge(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => 'private_object_one_123456',
				'idempotency_key'   => 'request-1',
				'purpose'           => 'epk',
				'uploader_type'     => 'anonymous',
			),
			$overrides
		);
	}

	private function service( ?VenueAuthorization $authorization = null ): BookingAttachmentService {
		return new BookingAttachmentService( null, null, null, null, $this->provider, $authorization ? $authorization : new BookingTestAuthorization() );
	}

	public function test_anonymous_attribution_and_idempotent_retry_do_not_duplicate_rows(): void {
		$booking = $this->booking();
		$first   = $this->service()->attach( $this->input( $booking ) );
		$retry   = $this->service()->attach( $this->input( $booking ) );
		$this->assertIsArray( $retry, is_wp_error( $retry ) ? $retry->get_error_code() : '' );
		$this->assertSame( $first['id'], $retry['id'] );
		$this->assertSame( 'anonymous', $first['uploader_type'] );
		$this->assertNull( $first['uploader_user_id'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertCount(
			1,
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( $row ) {
					return 'attachment_added' === $row['kind'];
				}
			)
		);
		$this->assertSame( array(), $this->provider->released );
	}

	public function test_cross_booking_reuse_fails_closed_without_canonical_artist_authority(): void {
		$one     = $this->booking();
		$two     = $this->booking();
		$service = $this->service();
		$user    = array(
			'uploader_type'    => 'user',
			'uploader_user_id' => 12,
		);
		$this->assertIsArray( $service->attach( $this->input( $one, $user ) ) );
		$result = $service->attach( $this->input( $two, array_merge( $user, array( 'idempotency_key' => 'request-2' ) ) ) );
		$this->assertSame( 'booking_attachment_artist_unresolved', $result->get_error_code() );
		$three  = $this->booking();
		$result = $service->attach( $this->input( $three, array( 'idempotency_key' => 'request-3' ) ) );
		$this->assertSame( 'booking_attachment_artist_unresolved', $result->get_error_code() );
	}

	public function test_policy_rejects_mime_size_filename_and_tax_documents_and_releases_claims(): void {
		$booking = $this->booking();
		$cases   = array(
			'bad_mime_object_123456789' => array( $this->metadata( 'press-kit.pdf', 'image/jpeg' ), 'invalid_booking_attachment_type' ),
			'oversize_object_123456789' => array( $this->metadata( 'press-kit.pdf', 'application/pdf', BookingAttachmentPolicy::MAX_BYTES + 1 ), 'invalid_booking_attachment_size' ),
			'unsafe_name_object_123456' => array( $this->metadata( '../press kit.pdf', 'application/pdf' ), 'invalid_booking_attachment_filename' ),
			'tax_form_object_123456789' => array( $this->metadata( 'artist-w-9.pdf', 'application/pdf' ), 'booking_tax_document_denied' ),
		);
		foreach ( $cases as $reference => $case ) {
			$this->provider->objects[ $reference ] = $case[0];
			$result                                = $this->service()->attach(
				$this->input(
					$booking,
					array(
						'storage_reference' => $reference,
						'idempotency_key'   => $reference,
					)
				)
			);
			$this->assertSame( $case[1], $result->get_error_code(), $reference );
		}
		$this->assertCount( 4, $this->provider->released );
		$this->assertArrayNotHasKey( BookingSchema::attachments_table(), $GLOBALS['wpdb']->rows );
	}

	/** Private storage has no public-upload fallback and references cannot be paths or URLs. */
	public function test_storage_is_unavailable_by_default_and_reference_shape_rejects_paths(): void {
		$booking = $this->booking();
		$this->assertSame( 'booking_private_storage_unavailable', ( new BookingAttachmentService() )->attach( $this->input( $booking ) )->get_error_code() );
		$result = $this->service()->attach( $this->input( $booking, array( 'storage_reference' => '/uploads/private/file.pdf' ) ) );
		$this->assertSame( 'invalid_booking_attachment_reference', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->claims );
	}

	public function test_cross_booking_access_fails_and_presenter_never_leaks_private_reference(): void {
		$one        = $this->booking();
		$two        = $this->booking( 56 );
		$attachment = $this->service()->attach( $this->input( $one ) );
		$this->assertSame( 'booking_attachment_not_found', $this->service()->download_descriptor( $two['id'], $attachment['id'] )->get_error_code() );

		$abilities = new BookingAttachmentAbilities( null, null, $this->service(), new BookingTestAuthorization( array( '12:56' => false ) ) );
		$this->assertTrue( $abilities->can_access_booking( array( 'booking_id' => $one['id'] ) ) );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_booking( array( 'booking_id' => $two['id'] ) )->get_error_code() );
		$presented = $abilities->present( $attachment );
		$this->assertArrayNotHasKey( 'storage_reference', $presented );
		$this->assertArrayNotHasKey( 'content_hash', $presented );
	}

	public function test_replacement_and_deletion_preserve_audit_evidence_and_rollback_on_activity_failure(): void {
		$booking = $this->booking();
		$service = $this->service();
		$user    = array( 'uploader_type' => 'user', 'uploader_user_id' => 12 );
		$first   = $service->attach( $this->input( $booking, $user ) );
		$second  = $service->replace(
			$this->input(
				$booking,
				array(
					'attachment_id'     => $first['id'],
					'storage_reference' => 'private_object_two_123456',
					'idempotency_key'   => 'replacement-1',
					'uploader_type'      => 'user',
					'uploader_user_id'   => 12,
				)
			)
		);
		$this->assertSame( 'replaced', ( new BookingAttachmentRepository() )->get( $first['id'] )['state'] );
		$this->assertSame( $first['id'], $second['replaces_attachment_id'] );
		$this->assertSame( 'deleted', $service->delete( $booking['id'], $second['id'], 12 )['state'] );

		$third = $service->attach(
			$this->input(
				$booking,
				array(
					'storage_reference' => 'private_object_three_1234',
					'idempotency_key'   => 'third',
					'uploader_type'     => 'user',
					'uploader_user_id'  => 12,
				)
			)
		);
		$this->assertIsArray( $third );
		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result                                 = $service->delete( $booking['id'], $third['id'], 12 );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertSame( 'active', ( new BookingAttachmentRepository() )->get( $third['id'] )['state'] );
	}

	public function test_cleanup_skips_active_reuse_and_contract_audit_hold_then_purges_orphans(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking, array( 'purpose' => 'other_private_evidence' ) ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$table = BookingSchema::attachments_table();
		$GLOBALS['wpdb']->rows[ $table ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$cleanup_policy = array( 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } );
		$result = $service->cleanup( $cleanup_policy );
		$this->assertSame( 1, $result['purged'] );
		$this->assertSame( array( 'private_object_one_123456' ), $this->provider->retired );

		$contract = $service->attach(
			$this->input(
				$booking,
				array(
					'storage_reference' => 'private_object_two_123456',
					'idempotency_key'   => 'contract',
					'purpose'           => 'contract',
				)
			)
		);
		$service->delete( $booking['id'], $contract['id'], 12 );
		$GLOBALS['wpdb']->rows[ $table ][ $contract['id'] ]['retired_at']                     = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'confirmed';
		$this->assertSame( 0, $service->cleanup( $cleanup_policy )['purged'] );
		$this->assertSame( 'deleted', ( new BookingAttachmentRepository() )->get( $contract['id'] )['state'] );
	}

	public function test_cleanup_requires_policy_and_database_uncertainty_fails_closed(): void {
		$service = $this->service();
		$this->assertSame( 'booking_attachment_cleanup_policy_required', $service->cleanup()->get_error_code() );
		$GLOBALS['wpdb']->fail_reads = true;
		$result = $service->cleanup( array( 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame( 'booking_attachment_cleanup_read_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_cleanup_reference_read_failure_never_retires_bytes(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->fail_attachment_reference_reads = true;
		$result = $service->cleanup( array( 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame( 'booking_attachment_reference_read_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_revoked_membership_is_rechecked_under_lock(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ): void {
			$authorization->allowed['12:55'] = false;
		};
		$result = $this->service( $authorization )->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'venue_action_forbidden', $result->get_error_code() );
		$this->assertCount( 0, $this->provider->released );
	}

	public function test_authorization_uses_the_membership_rows_locked_by_the_transaction(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][1] = array(
			'id'                 => 1,
			'venue_term_id'      => 55,
			'user_id'            => 12,
			'is_owner'           => 0,
			'status'             => VenueAuthorization::STATUS_ACTIVE,
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => '2026-07-23 00:00:00',
			'updated_at'         => '2026-07-23 00:00:00',
			'revoked_at'         => null,
		);
		$result = $this->service( $authorization )->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );
		$this->assertCount( 1, $authorization->locked_calls );
		$this->assertSame( 1, $authorization->locked_calls[0][3][0]['id'] );
		$this->assertSame( array(), $authorization->direct_calls, 'Authorization must not perform an unlocked membership reread.' );
	}

	public function test_failed_claim_compensation_is_never_silently_ignored(): void {
		$booking = $this->booking();
		$this->provider->objects['bad_mime_object_123456789'] = $this->metadata( 'press-kit.pdf', 'image/jpeg' );
		$this->provider->fail_release = true;
		$result = $this->service()->attach( $this->input( $booking, array( 'storage_reference' => 'bad_mime_object_123456789' ) ) );
		$this->assertSame( 'booking_attachment_claim_compensation_failed', $result->get_error_code() );
		$this->assertSame( 'invalid_booking_attachment_type', $result->get_error_data()['cause'] );
	}

	public function test_partial_retirement_leaves_recoverable_purging_state(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$this->provider->fail_retire = true;
		$result = $service->cleanup( array( 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame( 'simulated_retirement_failure', $result->get_error_code() );
		$this->assertSame( 'purging', ( new BookingAttachmentRepository() )->get( $file['id'] )['state'] );
	}

	public function test_cleanup_rechecks_active_references_inside_reference_lock(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$table = BookingSchema::attachments_table();
		$GLOBALS['wpdb']->rows[ $table ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->after_reference_lock = static function () use ( $table, $file ): void {
			$row                    = $file;
			$row['id']              = 99;
			$row['booking_id']      = $file['booking_id'];
			$row['state']           = 'active';
			$row['idempotency_key'] = 'concurrent-active';
			$GLOBALS['wpdb']->rows[ $table ][99] = $row;
		};
		$result = $service->cleanup( array( 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame( 0, $result['purged'] );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_cleanup_phase_two_restores_state_when_booking_gains_retention_hold(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking, array( 'purpose' => 'other_private_evidence' ) ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$table = BookingSchema::attachments_table();
		$GLOBALS['wpdb']->rows[ $table ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->after_reference_unlock = static function () use ( $booking ): void {
			$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'confirmed';
		};
		$result = $service->cleanup( array( 'actor_id' => 12, 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame( 0, $result['purged'] );
		$this->assertSame( 'deleted', ( new BookingAttachmentRepository() )->get( $file['id'] )['state'] );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_cleanup_phase_two_restores_state_when_policy_read_fails(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$reads = 0;
		$result = $service->cleanup(
			array(
				'actor_id'           => 12,
				'retention_days'      => 1,
				'legal_hold_callback' => static function () use ( &$reads ) {
					++$reads;
					return $reads > 1 ? new WP_Error( 'simulated_legal_hold_read_failure' ) : false;
				},
			)
		);
		$this->assertSame( 'simulated_legal_hold_read_failure', $result->get_error_code() );
		$this->assertSame( 'deleted', ( new BookingAttachmentRepository() )->get( $file['id'] )['state'] );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_download_handoff_reauthorizes_membership_and_consumes_once(): void {
		$booking       = $this->booking();
		$authorization = new BookingTestAuthorization();
		$service       = $this->service( $authorization );
		$attachment    = $service->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$descriptor    = $service->download_descriptor( $booking['id'], $attachment['id'], 12 );
		$this->assertStringNotContainsString( $attachment['storage_reference'], $descriptor['stream_token'] );

		$authorization->allowed['12:55'] = false;
		$this->assertSame( 'venue_action_forbidden', $service->open_download_stream( $booking['id'], $attachment['id'], $descriptor['stream_token'], 12 )->get_error_code() );
		$authorization->allowed['12:55'] = true;
		$stream = $service->open_download_stream( $booking['id'], $attachment['id'], $descriptor['stream_token'], 12 );
		$this->assertIsResource( $stream );
		fclose( $stream );
		$this->assertSame( 'booking_private_stream_invalid', $service->open_download_stream( $booking['id'], $attachment['id'], $descriptor['stream_token'], 12 )->get_error_code() );
	}

	public function test_global_lock_order_and_site_scoped_claim_identity_are_deterministic(): void {
		$booking = $this->booking();
		$result  = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertIsArray( $result );
		$this->assertSame( array( 'membership:55', 'booking:1', 'reference' ), array_slice( $GLOBALS['wpdb']->lock_sequence, -3 ) );
		$this->assertStringStartsWith( 'site:7:table:wp_7_ec_booking_attachments:booking:1:request:', $this->provider->claims[0][1] );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_delete_download_and_cleanup_keep_the_same_global_lock_order(): void {
		$booking = $this->booking();
		$service = $this->service();
		$user    = array( 'uploader_type' => 'user', 'uploader_user_id' => 12 );
		$file    = $service->attach( $this->input( $booking, $user ) );

		$GLOBALS['wpdb']->lock_sequence = array();
		$service->download_descriptor( $booking['id'], $file['id'], 12 );
		$this->assertSame( array( 'membership:55', 'booking:1', 'reference' ), $GLOBALS['wpdb']->lock_sequence );

		$GLOBALS['wpdb']->lock_sequence = array();
		$service->delete( $booking['id'], $file['id'], 12 );
		$this->assertSame( array( 'membership:55', 'booking:1', 'reference' ), $GLOBALS['wpdb']->lock_sequence );

		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->lock_sequence = array();
		$service->cleanup( array( 'actor_id' => 12, 'retention_days' => 1, 'legal_hold_callback' => static function (): bool { return false; } ) );
		$this->assertSame(
			array( 'membership:55', 'booking:1', 'reference', 'membership:55', 'booking:1', 'reference' ),
			$GLOBALS['wpdb']->lock_sequence
		);
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
	}

	public function test_throwable_rolls_back_transaction_and_releases_reference_lock(): void {
		$booking = $this->booking();
		$this->provider->throw_claim = true;
		$result = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_transaction_exception', $result->get_error_code() );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
		$this->assertSame( 1, $GLOBALS['wpdb']->rollback_queries );
	}

	public function test_uncertain_commit_attempts_rollback_and_releases_reference_lock(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$result = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_transaction_commit_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['rollback_confirmed'] );
		$this->assertFalse( $GLOBALS['wpdb']->transaction_active );
		$this->assertSame( array(), $GLOBALS['wpdb']->reference_locks );
		$this->assertSame( 1, $GLOBALS['wpdb']->rollback_queries );
	}

	public function test_rollback_uncertainty_disconnects_and_quarantines_without_explicit_unlock(): void {
		$booking = $this->booking();
		$this->provider->throw_claim                 = true;
		$GLOBALS['wpdb']->fail_transaction_rollback = true;
		$result = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_transaction_rollback_failed', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['connection_quarantined'] );
		$this->assertTrue( $result->get_error_data()['disconnect_confirmed'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertFalse( $GLOBALS['wpdb']->ready );
		$this->assertSame( array( 'get' ), array_column( $GLOBALS['wpdb']->lock_names, 0 ), 'A quarantined connection must not claim an explicit advisory unlock.' );
		$this->assertSame( 1, $this->service()->reference_lock_uncertainty()['count'] );

		$retry = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_database_connection_quarantined', $retry->get_error_code() );
		$this->assertSame( 1, $GLOBALS['wpdb']->reference_lock_queries );
	}

	public function test_commit_and_rollback_uncertainty_disconnects_without_compensating_claim(): void {
		$booking = $this->booking();
		$GLOBALS['wpdb']->fail_transaction_commit   = true;
		$GLOBALS['wpdb']->fail_transaction_rollback = true;
		$result = $this->service()->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_transaction_commit_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['connection_quarantined'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] ?? array(), 'Disconnect must roll back uncommitted metadata in the fake connection.' );
		$this->assertSame( 'active', array_values( $this->provider->claim_records )[0]['state'], 'Uncertain cross-store claims require reconciliation, not compensation.' );
		$this->assertSame( array(), $this->provider->released );
		$this->assertSame( array( 'get' ), array_column( $GLOBALS['wpdb']->lock_names, 0 ) );
	}

	public function test_fake_named_locks_track_acquisition_and_recursive_ownership(): void {
		$wpdb = $GLOBALS['wpdb'];
		$wpdb->get_lock_result = 0;
		$this->assertSame( 0, $wpdb->get_var( "SELECT GET_LOCK('test-lock', 0)" ) );
		$this->assertSame( array(), $wpdb->reference_locks );
		$this->assertNull( $wpdb->get_var( "SELECT RELEASE_LOCK('test-lock')" ) );

		$wpdb->get_lock_result = 1;
		$this->assertSame( 1, $wpdb->get_var( "SELECT GET_LOCK('test-lock', 0)" ) );
		$this->assertSame( 1, $wpdb->get_var( "SELECT GET_LOCK('test-lock', 0)" ) );
		$this->assertSame( 2, $wpdb->reference_locks['test-lock'] );
		$this->assertSame( 1, $wpdb->get_var( "SELECT RELEASE_LOCK('test-lock')" ) );
		$this->assertSame( 1, $wpdb->reference_locks['test-lock'] );
		$this->assertSame( 1, $wpdb->get_var( "SELECT RELEASE_LOCK('test-lock')" ) );
		$this->assertSame( array(), $wpdb->reference_locks );
	}

	public function test_committed_unlock_uncertainty_quarantines_connection_for_different_reference(): void {
		$booking = $this->booking();
		$service = $this->service();
		$GLOBALS['wpdb']->fail_reference_unlock = true;
		$result = $service->attach( $this->input( $booking, array( 'uploader_type' => 'user', 'uploader_user_id' => 12 ) ) );
		$this->assertSame( 'booking_attachment_reference_unlock_uncertain', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['committed'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertSame( 'active', array_values( $this->provider->claim_records )[0]['state'] );
		$this->assertSame( array(), $this->provider->released );
		$this->assertTrue( $service->reference_lock_uncertainty()['connection_quarantined'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->close_calls );
		$this->assertFalse( $GLOBALS['wpdb']->ready );

		$GLOBALS['wpdb']->fail_reference_unlock = false;
		$retry = $this->service()->attach(
			$this->input(
				$booking,
				array(
					'uploader_type'      => 'user',
					'uploader_user_id'   => 12,
					'storage_reference' => 'private_object_two_123456',
					'idempotency_key'    => 'different-reference-after-uncertainty',
				)
			)
		);
		$this->assertSame( 'booking_attachment_database_connection_quarantined', $retry->get_error_code() );
		$this->assertTrue( $retry->get_error_data()['connection_quarantined'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->reference_lock_queries );
		$this->assertSame( 'active', array_values( $this->provider->claim_records )[0]['state'] );
		$this->assertSame( array(), $this->provider->released );
	}

	public function test_reconciliation_marks_crash_orphan_abandoned_without_deleting_bytes(): void {
		$booking   = $this->booking();
		$claim_key = 'site:7:table:wp_7_ec_booking_attachments:booking:' . $booking['id'] . ':request:' . hash( 'sha256', 'crashed-request' );
		$this->provider->claim( 'private_object_one_123456', $claim_key, 'epk' );
		$record_key = 'private_object_one_123456|' . $claim_key;
		$this->provider->claim_records[ $record_key ]['updated_at'] = '2020-01-01 00:00:00';
		$policy = array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => false );
		$report = $this->service()->reconcile( $policy );
		$this->assertCount( 1, $report['orphan_claims'] );
		$this->assertSame( array(), $this->provider->retired );
		$policy['repair'] = true;
		$repaired = $this->service()->reconcile( $policy );
		$this->assertSame( 1, $repaired['repaired_claims'] );
		$this->assertSame( 'abandoned', $this->provider->claim_records[ $record_key ]['state'] );
		$this->assertSame( array(), $this->provider->retired );
	}

	public function test_reconciliation_completes_replacement_after_process_crash(): void {
		$booking = $this->booking();
		$service = $this->service();
		$user    = array( 'uploader_type' => 'user', 'uploader_user_id' => 12 );
		$prior   = $service->attach( $this->input( $booking, $user ) );
		$new     = $service->attach(
			$this->input(
				$booking,
				array_merge(
					$user,
					array(
						'storage_reference'      => 'private_object_two_123456',
						'idempotency_key'        => 'crashed-replacement',
						'replaces_attachment_id' => $prior['id'],
					)
				)
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ][ $new['id'] ]['created_at'] = '2020-01-01 00:00:00';
		$report = $service->reconcile( array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => false ) );
		$this->assertCount( 1, $report['incomplete_replacements'] );
		$this->assertSame( 'active', ( new BookingAttachmentRepository() )->get( $prior['id'] )['state'] );
		$repaired = $service->reconcile( array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => true ) );
		$this->assertSame( 1, $repaired['repaired_replacements'] );
		$this->assertSame( 'replaced', ( new BookingAttachmentRepository() )->get( $prior['id'] )['state'] );
		$this->assertSame( 'active', ( new BookingAttachmentRepository() )->get( $new['id'] )['state'] );
	}

	public function test_reconciliation_counts_uncertain_claims_and_continues_later_candidates(): void {
		$booking = $this->booking();
		$missing_key = 'site:7:table:wp_7_ec_booking_attachments:booking:999:request:' . hash( 'sha256', 'missing' );
		$valid_key   = 'site:7:table:wp_7_ec_booking_attachments:booking:' . $booking['id'] . ':request:' . hash( 'sha256', 'valid' );
		$this->provider->claim( 'private_object_one_123456', $missing_key, 'epk' );
		$this->provider->claim( 'private_object_two_123456', $valid_key, 'epk' );
		foreach ( $this->provider->claim_records as &$claim ) {
			$claim['updated_at'] = '2020-01-01 00:00:00';
		}
		unset( $claim );
		$this->provider->inspect_uncertain = 2;
		$this->provider->inspect_truncated = true;
		$report = $this->service()->reconcile( array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => false ) );
		$this->assertSame( 3, $report['uncertain_claims'] );
		$this->assertCount( 1, $report['orphan_claims'] );
		$this->assertTrue( $report['truncated']['claims'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $report['continuation']['claims'] );
		$this->assertStringNotContainsString( 'private_object_', wp_json_encode( $report['continuation'] ) );
	}

	public function test_reconciliation_reports_replacement_truncation(): void {
		$table = BookingSchema::attachments_table();
		for ( $id = 1; $id <= 251; ++$id ) {
			$GLOBALS['wpdb']->rows[ $table ][ $id ] = array(
				'id'                     => $id,
				'public_id'              => 'replacement-' . $id,
				'booking_id'             => 1,
				'uploader_user_id'       => 12,
				'artist_term_id'         => null,
				'artist_profile_id'      => null,
				'byte_size'              => 1,
				'replaces_attachment_id' => 1000 + $id,
				'state'                  => 'active',
				'created_at'             => '2020-01-01 00:00:00',
			);
		}
		$report = $this->service()->reconcile( array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => false ) );
		$this->assertTrue( $report['truncated']['replacements'] );
		$this->assertSame( 250, $report['uncertain_replacements'] );
		$this->assertSame( 250, $report['continuation']['replacements'] );

		$next = $this->service()->reconcile(
			array(
				'actor_id'     => 12,
				'minimum_age'  => 3600,
				'repair'       => false,
				'continuation' => $report['continuation'],
			)
		);
		$this->assertFalse( $next['truncated']['replacements'] );
		$this->assertSame( 1, $next['uncertain_replacements'] );
		$this->assertNull( $next['continuation']['replacements'] );
	}

	public function test_replacement_reconciliation_advances_past_repaired_first_page(): void {
		$this->booking();
		$table = BookingSchema::attachments_table();
		for ( $id = 1; $id <= 251; ++$id ) {
			$GLOBALS['wpdb']->rows[ $table ][ $id ] = array(
				'id'                     => $id,
				'public_id'              => 'prior-' . $id,
				'booking_id'             => 1,
				'storage_reference'      => 'prior-reference-' . $id,
				'idempotency_key'        => 'prior-' . $id,
				'replaces_attachment_id' => null,
				'state'                  => 'active',
				'created_at'             => '2020-01-01 00:00:00',
				'updated_at'             => '2020-01-01 00:00:00',
			);
			$replacement_id = 1000 + $id;
			$GLOBALS['wpdb']->rows[ $table ][ $replacement_id ] = array(
				'id'                     => $replacement_id,
				'public_id'              => 'replacement-' . $id,
				'booking_id'             => 1,
				'storage_reference'      => 'replacement-reference-' . $id,
				'idempotency_key'        => 'replacement-' . $id,
				'replaces_attachment_id' => $id,
				'state'                  => 'active',
				'created_at'             => '2020-01-01 00:00:00',
				'updated_at'             => '2020-01-01 00:00:00',
			);
		}

		$policy = array( 'actor_id' => 12, 'minimum_age' => 3600, 'repair' => true );
		$first  = $this->service()->reconcile( $policy );
		$this->assertSame( 250, $first['repaired_replacements'] );
		$this->assertTrue( $first['truncated']['replacements'] );
		$this->assertSame( 1250, $first['continuation']['replacements'] );
		$this->assertSame( 'active', $GLOBALS['wpdb']->rows[ $table ][251]['state'] );

		$policy['continuation'] = $first['continuation'];
		$second                 = $this->service()->reconcile( $policy );
		$this->assertSame( 1, $second['repaired_replacements'] );
		$this->assertFalse( $second['truncated']['replacements'] );
		$this->assertNull( $second['continuation']['replacements'] );
		$this->assertSame( 'replaced', $GLOBALS['wpdb']->rows[ $table ][251]['state'] );
	}
}
