<?php
/**
 * Artist-owned booking inquiry follow-through.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Exposes only the bounded artist side of an admitted booking inquiry. */
final class ArtistBookingInquiryService {

	private const WITHDRAWABLE_STATUSES = array( 'submitted', 'needs_info', 'under_review', 'negotiating', 'held' );
	private const TERMINAL_STATUSES     = array( 'declined', 'withdrawn', 'cancelled', 'completed' );
	private const STATUS_LABELS         = array(
		'submitted'    => 'Pending review',
		'needs_info'   => 'More information needed',
		'under_review' => 'Under review',
		'negotiating'  => 'In discussion',
		'held'         => 'Date held',
		'confirmed'    => 'Confirmed',
		'declined'     => 'Declined',
		'withdrawn'    => 'Withdrawn',
		'cancelled'    => 'Cancelled',
		'completed'    => 'Completed',
	);

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingLifecycle */
	private $lifecycle;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var BookingHoldRepository */
	private $holds;
	/** @var BookingNotificationService */
	private $notifications;
	/** @var BookingCorrespondenceAutomationService */
	private $correspondence;
	/** @var BookingCommunicationService */
	private $communication;
	/** @var VenueBookingConfig */
	private $config;

	public function __construct( ?BookingRepository $bookings = null, ?BookingLifecycle $lifecycle = null, ?BookingActivityRepository $activity = null, ?BookingHoldRepository $holds = null, ?BookingNotificationService $notifications = null, ?BookingCorrespondenceAutomationService $correspondence = null, ?VenueBookingConfig $config = null, ?BookingCommunicationService $communication = null ) {
		$this->bookings       = $bookings ? $bookings : new BookingRepository();
		$this->activity       = $activity ? $activity : new BookingActivityRepository();
		$this->holds          = $holds ? $holds : new BookingHoldRepository( $this->bookings, $this->activity );
		$this->lifecycle      = $lifecycle ? $lifecycle : new BookingLifecycle( $this->bookings, $this->activity, null, null, $this->holds );
		$this->communication  = $communication ? $communication : new BookingCommunicationService( $this->bookings, $this->activity );
		$this->notifications  = $notifications ? $notifications : new BookingNotificationService( $this->bookings, $this->activity );
		$this->correspondence = $correspondence ? $correspondence : new BookingCorrespondenceAutomationService( $this->bookings, $this->activity, $this->communication );
		$this->config         = $config ? $config : new VenueBookingConfig();
	}

	/** Derive the stable anonymous capability without persisting plaintext. */
	public static function capability_for( array $booking ): string {
		return hash_hmac( 'sha256', (string) ( $booking['public_id'] ?? '' ) . "\0" . (string) ( $booking['inquiry_request_hash'] ?? '' ), wp_salt( 'auth' ) );
	}

	/** Return the strict artist-safe inquiry projection. */
	public function status( string $public_id, int $venue_term_id, string $capability, int $current_user_id ) {
		$booking = $this->authorized_booking( $public_id, $venue_term_id, $capability, $current_user_id );
		return is_wp_error( $booking ) ? $booking : $this->project( $booking );
	}

	/** Record a correction request without mutating canonical booking data. */
	public function request_correction( string $public_id, int $venue_term_id, string $capability, int $current_user_id, int $expected_version, string $idempotency_key, string $correction ) {
		$correction = mb_substr( sanitize_textarea_field( $correction ), 0, 2000 );
		if ( '' === $correction ) {
			return new \WP_Error( 'booking_artist_correction_invalid', __( 'Describe the correction you need the venue to review.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $this->record_request( $public_id, $venue_term_id, $capability, $current_user_id, $expected_version, $idempotency_key, 'artist_correction_requested', BookingNotificationService::TYPE_ARTIST_CORRECTION_REQUESTED, array( 'correction' => $correction ) );
	}

	/** Withdraw a pending inquiry or request cancellation of a confirmed booking. */
	public function withdraw( string $public_id, int $venue_term_id, string $capability, int $current_user_id, int $expected_version, string $idempotency_key ) {
		$booking = $this->authorized_booking( $public_id, $venue_term_id, $capability, $current_user_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( 'confirmed' === $booking['status'] ) {
			return $this->record_request( $public_id, $venue_term_id, $capability, $current_user_id, $expected_version, $idempotency_key, 'artist_cancellation_requested', BookingNotificationService::TYPE_ARTIST_CANCELLATION_REQUESTED );
		}
		$key = $this->idempotency_key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$hash  = $this->operation_hash( 'artist_withdrawn', $expected_version, array() );
		$prior = $this->idempotent_result( $booking, 'artist_withdrawn', $key, $hash );
		if ( is_wp_error( $prior ) || is_array( $prior ) ) {
			return is_array( $prior ) ? $this->finish_withdrawal( $prior, (int) $booking['id'] ) : $prior;
		}
		if ( in_array( $booking['status'], self::TERMINAL_STATUSES, true ) || ! in_array( $booking['status'], self::WITHDRAWABLE_STATUSES, true ) ) {
			return $this->terminal_error();
		}
		if ( (int) $booking['version'] !== $expected_version ) {
			return $this->version_error( $booking );
		}
		$valid = $this->lifecycle->validate_transition( $booking, 'withdrawn' );
		if ( is_wp_error( $valid ) ) {
			return $this->terminal_error();
		}

		return $this->holds->with_artist_withdrawal_lock(
			$booking,
			$expected_version,
			function ( array $current ) use ( $venue_term_id, $capability, $current_user_id, $expected_version, $key, $hash ) {
				return $this->withdraw_locked( $current, $venue_term_id, $capability, $current_user_id, $expected_version, $key, $hash );
			}
		);
	}

	/** Complete one artist withdrawal while any required venue-space lock is held. */
	private function withdraw_locked( array $booking, int $venue_term_id, string $capability, int $current_user_id, int $expected_version, string $key, string $hash ) {
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked  = $this->bookings->get_for_update( (int) $booking['id'] );
		$allowed = is_array( $locked ) ? $this->authorize( $locked, $venue_term_id, $capability, $current_user_id ) : $this->forbidden();
		if ( ! is_array( $locked ) || true !== $allowed ) {
			return $this->rollback( is_wp_error( $allowed ) ? $allowed : $this->forbidden() );
		}
		$prior = $this->idempotent_result( $locked, 'artist_withdrawn', $key, $hash );
		if ( is_wp_error( $prior ) ) {
			return $this->rollback( $prior );
		}
		if ( is_array( $prior ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $this->finish_withdrawal( $prior, (int) $locked['id'] );
		}
		if ( (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_error( $locked ) );
		}
		if ( in_array( $locked['status'], self::TERMINAL_STATUSES, true ) || ! in_array( $locked['status'], self::WITHDRAWABLE_STATUSES, true ) || is_wp_error( $this->lifecycle->validate_transition( $locked, 'withdrawn' ) ) ) {
			return $this->rollback( $this->terminal_error() );
		}
		$updated = $this->update_status( $locked, 'withdrawn', $expected_version );
		if ( is_wp_error( $updated ) ) {
			return $this->rollback( $updated );
		}
		if ( 'held' === $locked['status'] ) {
			$released = $this->holds->release_for_artist_withdrawal( (int) $locked['id'] );
			if ( is_wp_error( $released ) ) {
				return $this->rollback( $released );
			}
		}
		$status_event = $this->activity->append(
			array(
				'booking_id'      => $locked['id'],
				'kind'            => 'status_changed',
				'actor_type'      => $current_user_id > 0 ? 'user' : 'anonymous',
				'actor_id'        => $current_user_id > 0 ? $current_user_id : null,
				'idempotency_key' => $this->activity_key( 'status_changed', $key ),
				'payload'         => array(
					'from_status' => $locked['status'],
					'to_status'   => 'withdrawn',
					'version'     => $expected_version + 1,
				),
			)
		);
		if ( is_wp_error( $status_event ) ) {
			return $this->rollback( $status_event );
		}
		$result = array(
			'public_id'     => $locked['public_id'],
			'venue_term_id' => (int) $locked['venue_term_id'],
			'operation'     => 'withdrawn',
			'status'        => 'withdrawn',
			'status_label'  => self::STATUS_LABELS['withdrawn'],
			'version'       => $expected_version + 1,
		);
		$event  = $this->append_artist_activity(
			$locked,
			'artist_withdrawn',
			$key,
			$hash,
			$current_user_id,
			$result,
			array(
				'from_status' => $locked['status'],
				'to_status'   => 'withdrawn',
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$request = $this->notifications->request( BookingNotificationService::TYPE_ARTIST_WITHDREW, (int) $event['id'] );
		if ( is_wp_error( $request ) ) {
			return $this->rollback( $request );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		BookingNotificationService::emit( BookingNotificationService::TYPE_ARTIST_WITHDREW, (int) $event['id'] );
		return $this->finish_withdrawal( $result, (int) $locked['id'] );
	}

	/** Queue access recovery after domain-owned venue and contact authorization. */
	public function resend_receipt( string $public_id, int $venue_term_id, string $claimed_contact_email, string $idempotency_key ) {
		$public_id = sanitize_text_field( $public_id );
		$booking   = wp_is_uuid( $public_id ) ? $this->bookings->get( $public_id ) : null;
		$claimed   = strtolower( sanitize_email( trim( $claimed_contact_email ) ) );
		$persisted = is_array( $booking ) ? strtolower( sanitize_email( (string) ( $booking['contact_email'] ?? '' ) ) ) : '';
		if ( ! is_array( $booking ) || $venue_term_id < 1 || (int) $booking['venue_term_id'] !== $venue_term_id || ! empty( $booking['submitter_user_id'] ) || '' === $claimed || '' === $persisted || ! hash_equals( $persisted, $claimed ) || ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $booking['inquiry_request_hash'] ?? '' ) ) ) {
			return $this->recovery_forbidden();
		}
		$key = $this->idempotency_key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$source = $this->activity->find_by_idempotency( (int) $booking['id'], 'inquiry:' . (string) $booking['inquiry_idempotency_key'] );
		if ( ! is_array( $source ) || 'inquiry_submitted' !== $source['kind'] ) {
			return new \WP_Error( 'booking_receipt_recovery_unavailable', __( 'The inquiry receipt source is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$identity = hash_hmac( 'sha256', $key, wp_salt( 'auth' ) );
		$queued   = $this->correspondence->resend_receipt( (int) $source['id'], 'artist-recovery:' . $identity );
		if ( is_wp_error( $queued ) ) {
			return $queued;
		}
		return array(
			'public_id'     => $booking['public_id'],
			'venue_term_id' => (int) $booking['venue_term_id'],
			'operation'     => 'receipt_resend_requested',
		);
	}

	/** Retry post-commit reminder suppression without reverting the withdrawal. */
	private function finish_withdrawal( array $result, int $booking_id ) {
		$suppressed = $this->communication->suppress_pending_reminders( $booking_id, 'artist_withdrawn' );
		return is_wp_error( $suppressed ) ? $suppressed : $result;
	}

	private function record_request( string $public_id, int $venue_term_id, string $capability, int $current_user_id, int $expected_version, string $idempotency_key, string $kind, string $notification_type, array $details = array() ) {
		$booking = $this->authorized_booking( $public_id, $venue_term_id, $capability, $current_user_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$key = $this->idempotency_key( $idempotency_key );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$hash  = $this->operation_hash( $kind, $expected_version, $details );
		$prior = $this->idempotent_result( $booking, $kind, $key, $hash );
		if ( is_wp_error( $prior ) || is_array( $prior ) ) {
			return $prior;
		}
		if ( in_array( $booking['status'], self::TERMINAL_STATUSES, true ) ) {
			return $this->terminal_error();
		}
		if ( (int) $booking['version'] !== $expected_version ) {
			return $this->version_error( $booking );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked  = $this->bookings->get_for_update( (int) $booking['id'] );
		$allowed = is_array( $locked ) ? $this->authorize( $locked, $venue_term_id, $capability, $current_user_id ) : $this->forbidden();
		if ( ! is_array( $locked ) || true !== $allowed ) {
			return $this->rollback( is_wp_error( $allowed ) ? $allowed : $this->forbidden() );
		}
		$prior = $this->idempotent_result( $locked, $kind, $key, $hash );
		if ( is_wp_error( $prior ) || is_array( $prior ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $prior;
		}
		if ( in_array( $locked['status'], self::TERMINAL_STATUSES, true ) || ( 'artist_cancellation_requested' === $kind && 'confirmed' !== $locked['status'] ) ) {
			return $this->rollback( $this->terminal_error() );
		}
		if ( (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_error( $locked ) );
		}
		$result = array(
			'public_id'     => $locked['public_id'],
			'venue_term_id' => (int) $locked['venue_term_id'],
			'operation'     => 'artist_cancellation_requested' === $kind ? 'cancellation_requested' : 'correction_requested',
			'version'       => $expected_version,
		);
		$event  = $this->append_artist_activity( $locked, $kind, $key, $hash, $current_user_id, $result, array_merge( array( 'expected_version' => $expected_version ), $details ) );
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$stored_hash = (string) ( $event['payload']['data']['operation_hash'] ?? '' );
		if ( 64 !== strlen( $stored_hash ) || ! hash_equals( $stored_hash, $hash ) ) {
			return $this->rollback( new \WP_Error( 'booking_artist_idempotency_conflict', __( 'The idempotency key was already used for different details.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$request = $this->notifications->request( $notification_type, (int) $event['id'] );
		if ( is_wp_error( $request ) ) {
			return $this->rollback( $request );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		BookingNotificationService::emit( $notification_type, (int) $event['id'] );
		return $result;
	}

	private function append_artist_activity( array $booking, string $kind, string $key, string $hash, int $current_user_id, array $result, array $details ) {
		return $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => $kind,
				'actor_type'      => $current_user_id > 0 ? 'user' : 'anonymous',
				'actor_id'        => $current_user_id > 0 ? $current_user_id : null,
				'idempotency_key' => $this->activity_key( $kind, $key ),
				'payload'         => array_merge(
					$details,
					array(
						'operation_hash' => $hash,
						'result'         => $result,
					)
				),
			)
		);
	}

	private function idempotent_result( array $booking, string $kind, string $key, string $hash ) {
		$existing = $this->activity->find_by_idempotency( (int) $booking['id'], $this->activity_key( $kind, $key ) );
		if ( ! is_array( $existing ) ) {
			return $existing;
		}
		$stored = (string) ( $existing['payload']['data']['operation_hash'] ?? '' );
		return 64 === strlen( $stored ) && hash_equals( $stored, $hash ) && is_array( $existing['payload']['data']['result'] ?? null )
			? $existing['payload']['data']['result']
			: new \WP_Error( 'booking_artist_idempotency_conflict', __( 'The idempotency key was already used for different details.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function authorized_booking( string $public_id, int $venue_term_id, string $capability, int $current_user_id ) {
		$public_id = sanitize_text_field( $public_id );
		$booking   = wp_is_uuid( $public_id ) ? $this->bookings->get( $public_id ) : null;
		if ( ! is_array( $booking ) ) {
			return $this->forbidden();
		}
		$allowed = $this->authorize( $booking, $venue_term_id, $capability, $current_user_id );
		return true === $allowed ? $booking : $allowed;
	}

	private function authorize( array $booking, int $venue_term_id, string $capability, int $current_user_id ) {
		if ( $venue_term_id < 1 || (int) $booking['venue_term_id'] !== $venue_term_id ) {
			return $this->forbidden();
		}
		if ( ! empty( $booking['submitter_user_id'] ) ) {
			return $current_user_id > 0 && (int) $booking['submitter_user_id'] === $current_user_id ? true : $this->forbidden();
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $booking['inquiry_request_hash'] ?? '' ) ) ) {
			return $this->forbidden();
		}
		$expected = self::capability_for( $booking );
		return 64 === strlen( $capability ) && hash_equals( $expected, $capability ) ? true : $this->forbidden();
	}

	private function project( array $booking ): array {
		$venue = get_term( (int) $booking['venue_term_id'], 'venue' );
		$space = $this->requested_space( $booking );
		return array(
			'public_id'          => $booking['public_id'],
			'venue_term_id'      => (int) $booking['venue_term_id'],
			'venue'              => array( 'name' => $venue && ! is_wp_error( $venue ) ? (string) $venue->name : __( 'Venue', 'extrachill-events' ) ),
			'submitted_at'       => $booking['created_at'],
			'updated_at'         => $booking['updated_at'],
			'status'             => $booking['status'],
			'status_label'       => self::STATUS_LABELS[ $booking['status'] ],
			'version'            => (int) $booking['version'],
			'requested_interval' => array(
				'start_at' => $booking['requested_start_at'],
				'end_at'   => $booking['requested_end_at'],
			),
			'requested_space'    => $space,
			'permitted_actions'  => $this->permitted_actions( $booking['status'] ),
		);
	}

	private function requested_space( array $booking ): array {
		$key    = (string) ( $booking['requested_space_key'] ?? '' );
		$label  = '' !== $key ? $key : __( 'Not specified', 'extrachill-events' );
		$config = $this->config->get( (int) $booking['venue_term_id'] );
		if ( is_array( $config ) ) {
			foreach ( (array) ( $config['spaces'] ?? array() ) as $space ) {
				if ( (string) ( $space['key'] ?? '' ) === $key ) {
					$label = (string) $space['name'];
					break;
				}
			}
		}
		return array(
			'key'   => '' !== $key ? $key : null,
			'label' => $label,
		);
	}

	private function permitted_actions( string $status ): array {
		if ( in_array( $status, self::TERMINAL_STATUSES, true ) ) {
			return array();
		}
		$actions = array( 'request_correction' );
		if ( in_array( $status, self::WITHDRAWABLE_STATUSES, true ) ) {
			$actions[] = 'withdraw';
		} elseif ( 'confirmed' === $status ) {
			$actions[] = 'request_cancellation';
		}
		return $actions;
	}

	private function update_status( array $booking, string $status, int $expected_version ) {
		global $wpdb;
		$table  = BookingSchema::bookings_table();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_at = %s WHERE id = %d AND version = %d", $status, $now, $booking['id'], $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Artist transition is enclosed by the aggregate transaction.
		return 1 === $result ? true : ( false === $result ? new \WP_Error( 'booking_update_failed', __( 'The booking could not be updated.', 'extrachill-events' ) ) : $this->version_error( $booking ) );
	}

	private function idempotency_key( string $key ) {
		$trimmed = trim( $key );
		return $trimmed === $key && '' !== $key && strlen( $key ) <= 120 && sanitize_text_field( $key ) === $key
			? $key
			: new \WP_Error( 'booking_artist_idempotency_key_invalid', __( 'A bounded plain-text idempotency key is required.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	private function activity_key( string $kind, string $key ): string {
		return 'artist-follow-through:' . $kind . ':' . $key;
	}

	private function operation_hash( string $kind, int $expected_version, array $details ): string {
		ksort( $details, SORT_STRING );
		return hash_hmac( 'sha256', wp_json_encode( compact( 'kind', 'expected_version', 'details' ) ), wp_salt( 'auth' ) );
	}

	private function begin() {
		global $wpdb;
		return false === $wpdb->query( 'START TRANSACTION' ) ? new \WP_Error( 'booking_transaction_start_failed', __( 'The booking transaction could not be started.', 'extrachill-events' ) ) : true; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
	}

	private function commit() {
		global $wpdb;
		return false === $wpdb->query( 'COMMIT' ) ? new \WP_Error( 'booking_transaction_commit_uncertain', __( 'The booking transaction outcome could not be confirmed.', 'extrachill-events' ) ) : true; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
	}

	private function rollback( \WP_Error $error ): \WP_Error {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		return $error;
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'booking_inquiry_forbidden', __( 'This booking inquiry is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function recovery_forbidden(): \WP_Error {
		return new \WP_Error( 'booking_receipt_recovery_forbidden', __( 'The booking inquiry receipt cannot be recovered with those details.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function terminal_error(): \WP_Error {
		return new \WP_Error( 'booking_inquiry_terminal', __( 'This booking inquiry no longer accepts artist changes.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function version_error( array $booking ): \WP_Error {
		return new \WP_Error(
			'booking_version_conflict',
			__( 'Someone else updated this booking first, so nothing here was saved. Reload to see the latest version, then make your change again.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => (int) $booking['version'],
			)
		);
	}
}
