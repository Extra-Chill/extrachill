<?php
/**
 * Atomic public booking inquiry and attachment admission.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Core classes follow the repository's established PSR-style filenames.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the staged-file saga behind the hidden inquiry ability. */
final class BookingInquiryAdmissionService {

	public const MAX_FILES           = 5;
	public const MAX_AGGREGATE_BYTES = 52428800;

	/**
	 * Inquiry lifecycle.
	 *
	 * @var BookingLifecycle
	 */
	private $lifecycle;
	/**
	 * Attachment reads.
	 *
	 * @var BookingAttachmentRepository
	 */
	private $attachments;
	/**
	 * Attachment writes.
	 *
	 * @var BookingAttachmentService
	 */
	private $attachment_service;
	/**
	 * Private provider or configuration failure.
	 *
	 * @var BookingPrivateFileProvider|\WP_Error
	 */
	private $provider;
	/**
	 * Attachment policy.
	 *
	 * @var BookingAttachmentPolicy
	 */
	private $policy;
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;

	/**
	 * Build the admission coordinator from existing domain services.
	 *
	 * @param BookingLifecycle|null            $lifecycle          Inquiry lifecycle.
	 * @param BookingAttachmentRepository|null $attachments        Attachment reads.
	 * @param BookingAttachmentService|null    $attachment_service Attachment writes.
	 * @param mixed                            $provider            Private provider.
	 * @param BookingAttachmentPolicy|null     $policy              File policy.
	 */
	public function __construct( ?BookingLifecycle $lifecycle = null, ?BookingAttachmentRepository $attachments = null, ?BookingAttachmentService $attachment_service = null, $provider = null, ?BookingAttachmentPolicy $policy = null, ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null ) {
		$this->lifecycle          = $lifecycle ? $lifecycle : new BookingLifecycle();
		$this->attachments        = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->bookings           = $bookings ? $bookings : new BookingRepository();
		$this->activity           = $activity ? $activity : new BookingActivityRepository();
		$this->policy             = $policy ? $policy : new BookingAttachmentPolicy();
		$resolved                 = null !== $provider ? $provider : BookingPrivateFileProviders::resolve();
		$this->provider           = $resolved instanceof BookingPrivateFileProvider || is_wp_error( $resolved )
			? $resolved
			: new \WP_Error( 'booking_private_storage_invalid_provider', __( 'The private booking file provider is invalid.', 'extrachill-events' ) );
		$this->attachment_service = $attachment_service ? $attachment_service : new BookingAttachmentService( $this->attachments, null, null, $this->policy, $this->provider );
	}

	/**
	 * Admit one inquiry and its ordered attachment slots idempotently.
	 *
	 * @param array    $input    Hidden ability input.
	 * @param int|null $actor_id Actual authenticated caller, when present.
	 */
	public function admit( array $input, ?int $actor_id = null ) {
		$scope = $this->validate_scope();
		if ( is_wp_error( $scope ) ) {
			return $this->public_error( $scope );
		}
		$key = self::canonical_idempotency_key( $input['idempotency_key'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $this->public_error( $key );
		}
		$input['idempotency_key'] = $key;

		$files = $this->preflight_files( $input['attachments'] ?? array() );
		if ( is_wp_error( $files ) ) {
			return $this->public_error( $files );
		}
		unset( $input['attachments'] );
		unset( $input['user_id'], $input['uploader_user_id'], $input['submitter_user_id'] );
		if ( $files && is_wp_error( $this->provider ) ) {
			return $this->public_error( $this->provider );
		}
		$lock = $this->acquire_inquiry_lock( absint( $input['venue_term_id'] ?? 0 ), (string) ( $input['idempotency_key'] ?? '' ) );
		if ( is_wp_error( $lock ) ) {
			$manifest = array_map(
				static function ( array $file ): array {
					return $file['manifest'];
				},
				$files
			);
			$replay   = $this->lifecycle->replay_completed_inquiry( $input, $actor_id, $manifest ? array( 'attachments' => $manifest ) : array() );
			if ( is_wp_error( $replay ) ) {
				return $this->public_error( $replay );
			}
			return is_array( $replay ) ? $this->receipt( $replay ) : $this->public_error( $lock );
		}
		$result   = $this->admit_locked( $input, $actor_id, $files );
		$released = $this->release_inquiry_lock( $lock );
		return is_wp_error( $released ) ? $this->public_error( $released ) : $result;
	}

	/** Execute reservation, attachments, and publication under one owner lock. */
	private function admit_locked( array $input, ?int $actor_id, array $files ) {
		$owner_token = wp_generate_uuid4();
		$manifest    = array_map(
			static function ( array $file ): array {
				return $file['manifest'];
			},
			$files
		);
		$booking     = $this->lifecycle->reserve_inquiry( $input, $actor_id, $manifest ? array( 'attachments' => $manifest ) : array(), $owner_token );
		if ( is_wp_error( $booking ) ) {
			return $this->public_error( $booking );
		}

		$pending = array();
		foreach ( $files as $index => $file ) {
			$key      = $this->attachment_key( (string) $input['idempotency_key'], $index, $file['manifest'] );
			$existing = $this->attachments->get_idempotent( (int) $booking['id'], $key );
			if ( is_wp_error( $existing ) ) {
				return $this->public_error( $existing );
			}
			if ( is_array( $existing ) ) {
				if ( 'active' === $existing['state'] ) {
					continue;
				}
				return $this->public_error( new \WP_Error( 'booking_inquiry_attachment_reconciliation_required', __( 'The inquiry attachment state requires reconciliation.', 'extrachill-events' ) ) );
			}
			$file['idempotency_key'] = $key;
			$pending[]               = $file;
		}

		$staged = array();
		foreach ( $pending as $file ) {
			$reference = $this->provider_error_or_stage( $file );
			if ( is_wp_error( $reference ) ) {
				$cleanup = $this->compensate( $booking, $staged, array() );
				if ( is_wp_error( $cleanup ) ) {
					return $this->public_error( $cleanup );
				}
				return $this->public_error( $reference );
			}
			$file['storage_reference'] = $reference;
			$staged[]                  = $file;
		}

		$attached_files = array();
		foreach ( $staged as $file ) {
			$attached = $this->attachment_service->attach_admitted(
				array(
					'booking_id'         => (int) $booking['id'],
					'storage_reference'  => $file['storage_reference'],
					'idempotency_key'    => $file['idempotency_key'],
					'purpose'            => $file['purpose'],
					'uploader_type'      => null === $actor_id ? 'anonymous' : 'user',
					'uploader_user_id'   => $actor_id,
					'uploader_reference' => 'inquiry:' . $booking['public_id'],
				)
			);
			if ( is_wp_error( $attached ) ) {
				if ( $this->is_uncertain( $attached ) ) {
					return $this->public_error( $attached );
				}
				$cleanup = $this->compensate( $booking, $staged, $attached_files );
				if ( is_wp_error( $cleanup ) ) {
					return $this->public_error( $cleanup );
				}
				return $this->public_error( $attached );
			}
			$attached_files[] = $file;
		}
		$published = $this->lifecycle->publish_inquiry( $booking );
		if ( is_wp_error( $published ) ) {
			if ( $this->is_uncertain( $published ) ) {
				return $this->public_error( $published );
			}
			$cleanup = $this->compensate( $booking, $staged, $attached_files );
			return is_wp_error( $cleanup ) ? $this->public_error( $cleanup ) : $this->public_error( $published );
		}

		return $this->receipt( $published );
	}

	/** Build the stable public admission receipt. */
	private function receipt( array $booking ): array {
		return array(
			'public_id'     => $booking['public_id'],
			'venue_term_id' => $booking['venue_term_id'],
			'submitted_at'  => $booking['created_at'],
		);
	}

	/** Acquire the deterministic complete-saga ownership lock. */
	private function acquire_inquiry_lock( int $venue_id, string $idempotency_key ) {
		global $wpdb;
		if ( $venue_id < 1 || '' === trim( $idempotency_key ) ) {
			return new \WP_Error( 'booking_inquiry_lock_invalid', __( 'The inquiry ownership lock is invalid.', 'extrachill-events' ) );
		}
		$name     = self::inquiry_lock_name( $venue_id, $idempotency_key );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes the cross-store inquiry saga.
		return 1 === (int) $acquired ? $name : new \WP_Error(
			'booking_inquiry_processing',
			__( 'The inquiry is still being processed. Retry shortly.', 'extrachill-events' ),
			array(
				'status'      => 423,
				'retryable'   => true,
				'retry_after' => 1,
			)
		);
	}

	/** Return the stable complete-saga lock identity. */
	public static function inquiry_lock_name( int $venue_id, string $idempotency_key ): string {
		return 'ec_booking_inquiry_' . substr( hash( 'sha256', get_current_blog_id() . "\0" . $venue_id . "\0" . $idempotency_key ), 0, 40 );
	}

	/** Canonicalize without allowing two lossy raw forms to alias. */
	public static function canonical_idempotency_key( $raw ) {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return new \WP_Error( 'booking_idempotency_key_invalid', __( 'Inquiry idempotency keys must be plain text.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$key = trim( (string) $raw );
		if ( '' === $key || strlen( $key ) > 191 || sanitize_text_field( $key ) !== $key ) {
			return new \WP_Error( 'booking_idempotency_key_invalid', __( 'Inquiry idempotency keys must be unambiguous plain text no longer than 191 bytes.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $key;
	}

	/** Release the complete-saga lock without guessing on uncertainty. */
	private function release_inquiry_lock( string $name ) {
		global $wpdb;
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the inquiry saga lock.
		return 1 === (int) $released ? true : new \WP_Error( 'booking_inquiry_lock_release_uncertain', __( 'The inquiry lock release could not be confirmed.', 'extrachill-events' ) );
	}

	/** Fail closed before a current-prefix repository can touch the wrong site. */
	private function validate_scope() {
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
		return 0 < $events_blog_id && (int) get_current_blog_id() === $events_blog_id
			? true
			: new \WP_Error( 'booking_inquiry_wrong_site', __( 'Booking inquiries must execute on the Events site.', 'extrachill-events' ) );
	}

	/**
	 * Validate every cheap transport and policy bound before storage or database work.
	 *
	 * @param mixed $files Staged upload records.
	 */
	private function preflight_files( $files ) {
		if ( ! is_array( $files ) || count( $files ) > self::MAX_FILES ) {
			return new \WP_Error( 'invalid_booking_attachment_count', __( 'Too many booking attachments were submitted.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		$aggregate  = 0;
		foreach ( array_values( $files ) as $file ) {
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be received completely.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$path     = (string) ( $file['tmp_name'] ?? '' );
			$filename = (string) ( $file['name'] ?? '' );
			$purpose  = sanitize_key( (string) ( $file['purpose'] ?? '' ) );
			$trusted  = '' !== $path && is_readable( $path ) && ( is_uploaded_file( $path ) || true === apply_filters( 'extrachill_events_allow_test_booking_file', false, $file ) );
			if ( ! $trusted ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be verified.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$safe_filename = sanitize_file_name( $filename );
			$filetype      = wp_check_filetype( $safe_filename, BookingAttachmentPolicy::allowed_mimes() );
			$checked       = function_exists( 'wp_check_filetype_and_ext' ) ? wp_check_filetype_and_ext( $path, $safe_filename, BookingAttachmentPolicy::allowed_mimes() ) : $filetype;
			$actual_size   = filesize( $path );
			$declared_size = $file['size'] ?? null;
			if ( '' === $safe_filename || $safe_filename !== $filename || basename( $safe_filename ) !== $safe_filename ) {
				return new \WP_Error( 'invalid_booking_attachment_filename', __( 'The attachment filename is unsafe.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $purpose, BookingAttachmentPolicy::PURPOSES, true ) ) {
				return new \WP_Error( 'invalid_booking_attachment_purpose', __( 'The attachment purpose is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || empty( $checked['ext'] ) || empty( $checked['type'] ) || $filetype['type'] !== $checked['type'] ) {
				return new \WP_Error( 'invalid_booking_attachment_type', __( 'The attachment type is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( false === $actual_size || ! is_int( $declared_size ) || $declared_size !== $actual_size || $actual_size < 1 || $actual_size > BookingAttachmentPolicy::max_bytes() ) {
				return new \WP_Error( 'invalid_booking_attachment_size', __( 'The attachment size is outside the allowed range.', 'extrachill-events' ), array( 'status' => 413 ) );
			}
			if ( $this->policy->is_default_denied_filename( $safe_filename ) ) {
				return new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents are not accepted here.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$aggregate += $actual_size;
			if ( $aggregate > self::MAX_AGGREGATE_BYTES ) {
				return new \WP_Error( 'invalid_booking_attachment_aggregate_size', __( 'The combined attachment size is too large.', 'extrachill-events' ), array( 'status' => 413 ) );
			}
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be fingerprinted.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$normalized[] = array(
				'path'     => $path,
				'filename' => $safe_filename,
				'purpose'  => $purpose,
				'manifest' => array(
					'filename'  => $safe_filename,
					'purpose'   => $purpose,
					'byte_size' => $actual_size,
					'sha256'    => $hash,
				),
			);
		}
		return $normalized;
	}

	/**
	 * Stage one preflighted file without exposing its source in errors.
	 *
	 * @param array $file Preflighted file.
	 */
	private function provider_error_or_stage( array $file ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		try {
			return $this->provider->stage( $file['path'], $file['filename'], $file['purpose'] );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'booking_private_stage_failed', __( 'The private attachment could not be staged.', 'extrachill-events' ) );
		}
	}

	/**
	 * Retire only objects whose attachment outcome is confirmed uncommitted.
	 *
	 * @param array $files Staged files containing private references.
	 */
	private function retire_staged( array $files ) {
		foreach ( $files as $file ) {
			try {
				$retired = $this->provider->retire( (string) $file['storage_reference'] );
			} catch ( \Throwable $throwable ) {
				$retired = false;
			}
			if ( true !== $retired ) {
				return new \WP_Error( 'booking_inquiry_attachment_cleanup_uncertain', __( 'The failed inquiry attachment requires reconciliation.', 'extrachill-events' ) );
			}
		}
		return true;
	}

	/** Remove a known-failed inquiry and every cross-store side effect. */
	private function compensate( array $booking, array $staged, array $attached ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensation boundary.
			return new \WP_Error( 'booking_inquiry_compensation_uncertain', __( 'The failed inquiry requires reconciliation.', 'extrachill-events' ) );
		}
		$current = $this->bookings->get_for_update( (int) $booking['id'], true );
		if ( ! is_array( $current ) || 'admission_pending' !== $current['status'] || ( $current['admission_owner_token'] ?? null ) !== ( $booking['admission_owner_token'] ?? null ) || (int) $current['version'] !== (int) $booking['version'] ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership mismatch rollback.
			return new \WP_Error( 'booking_inquiry_compensation_uncertain', __( 'The failed inquiry reservation changed ownership.', 'extrachill-events' ) );
		}
		foreach ( array( $this->activity->discard_for_booking( (int) $booking['id'] ), $this->attachments->discard_for_booking( (int) $booking['id'] ), $this->bookings->discard_inquiry( $current ) ) as $discarded ) {
			if ( is_wp_error( $discarded ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensation rollback.
				return new \WP_Error( 'booking_inquiry_compensation_uncertain', __( 'The failed inquiry requires reconciliation.', 'extrachill-events' ) );
			}
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensation commit.
			return new \WP_Error( 'booking_inquiry_compensation_uncertain', __( 'The failed inquiry requires reconciliation.', 'extrachill-events' ) );
		}
		foreach ( $attached as $file ) {
			$released = $this->provider->release_claim( $file['storage_reference'], $this->attachment_service->claim_key( (int) $booking['id'], $file['idempotency_key'] ) );
			if ( true !== $released ) {
				return new \WP_Error( 'booking_inquiry_attachment_cleanup_uncertain', __( 'The failed inquiry attachment requires reconciliation.', 'extrachill-events' ) );
			}
		}
		return $this->retire_staged( $staged );
	}

	/**
	 * Build a stable booking-scoped slot key.
	 *
	 * @param string $inquiry_key Inquiry idempotency key.
	 * @param int    $index       Ordered slot index.
	 * @param array  $manifest    Canonical file manifest.
	 */
	private function attachment_key( string $inquiry_key, int $index, array $manifest ): string {
		return 'inquiry-attachment:' . hash_hmac( 'sha256', $inquiry_key . "\0" . $index . "\0" . wp_json_encode( $manifest ), wp_salt( 'auth' ) );
	}

	/**
	 * Never compensate a cross-store outcome that may have committed.
	 *
	 * @param \WP_Error $error Internal failure.
	 */
	private function is_uncertain( \WP_Error $error ): bool {
		$data = (array) $error->get_error_data();
		return in_array(
			$error->get_error_code(),
			array(
				'booking_attachment_transaction_commit_uncertain',
				'booking_attachment_reference_unlock_uncertain',
				'booking_attachment_claim_compensation_failed',
				'booking_transaction_commit_uncertain',
				'booking_transaction_rollback_failed',
				'booking_inquiry_lock_release_uncertain',
			),
			true
		) || true === ( $data['lock_uncertain'] ?? false ) || true === ( $data['connection_quarantined'] ?? false );
	}

	/**
	 * Return only stable facade-safe error codes and data.
	 *
	 * @param \WP_Error $error Internal failure.
	 */
	private function public_error( \WP_Error $error ): \WP_Error {
		$code = $error->get_error_code();
		if ( 'booking_inquiry_processing' === $code ) {
			return new \WP_Error( $code, $error->get_error_message(), (array) $error->get_error_data() );
		}
		if ( $this->is_uncertain( $error ) || in_array( $code, array( 'booking_transaction_commit_uncertain', 'booking_transaction_rollback_failed', 'booking_inquiry_attachment_reconciliation_required', 'booking_inquiry_attachment_cleanup_uncertain', 'booking_inquiry_compensation_uncertain' ), true ) ) {
			return new \WP_Error(
				'booking_inquiry_reconciliation_required',
				__( 'The inquiry outcome requires reconciliation before retrying.', 'extrachill-events' ),
				array(
					'status'                  => 503,
					'retryable'               => true,
					'reconciliation_required' => true,
				)
			);
		}
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		$safe   = in_array( $status, array( 400, 403, 409, 413 ), true );
		return new \WP_Error(
			$safe ? $code : 'booking_inquiry_unavailable',
			$safe ? $error->get_error_message() : __( 'Booking inquiry processing is temporarily unavailable.', 'extrachill-events' ),
			array(
				'status'    => $safe ? $status : 503,
				'retryable' => ! $safe,
			)
		);
	}
}
