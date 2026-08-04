<?php
/**
 * Deterministic venue booking lifecycle aggregate.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns booking state changes and their append-only activity records. */
class BookingLifecycle {

	public const STATUSES = BookingRepository::STATUSES;

	private const TRANSITIONS = array(
		'submitted'    => array( 'needs_info', 'under_review', 'declined', 'withdrawn' ),
		'needs_info'   => array( 'submitted', 'under_review', 'declined', 'withdrawn' ),
		'under_review' => array( 'needs_info', 'negotiating', 'declined', 'withdrawn' ),
		'negotiating'  => array( 'needs_info', 'under_review', 'held', 'confirmed', 'declined', 'withdrawn' ),
		'held'         => array( 'negotiating', 'confirmed', 'declined', 'withdrawn', 'cancelled' ),
		'confirmed'    => array( 'cancelled', 'completed' ),
		'declined'     => array(),
		'withdrawn'    => array(),
		'cancelled'    => array(),
		'completed'    => array(),
	);

	/**
	 * Booking persistence.
	 *
	 * @var BookingRepository
	 */
	private $bookings;

	/**
	 * Append-only activity persistence.
	 *
	 * @var BookingActivityRepository
	 */
	private $activity;

	/**
	 * Exact venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Admission configuration.
	 *
	 * @var VenueBookingConfig
	 */
	private $config;

	/** @var BookingHoldRepository */
	private $holds;

	/**
	 * Build the aggregate from its two owned repositories.
	 *
	 * @param BookingRepository|null         $bookings Booking persistence.
	 * @param BookingActivityRepository|null $activity      Activity persistence.
	 * @param VenueAuthorization|null        $authorization Exact venue authorization.
	 * @param VenueBookingConfig|null        $config        Admission configuration.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?VenueBookingConfig $config = null, ?BookingHoldRepository $holds = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig();
		$this->holds         = $holds ? $holds : new BookingHoldRepository( $this->bookings, $this->activity, $this->authorization, $this->config );
	}

	/**
	 * Create a submitted inquiry and its receipt event exactly once.
	 *
	 * @param array    $data     Inquiry fields.
	 * @param int|null $actor_id            Authenticated submitter, when present.
	 * @param array    $fingerprint_context Admission-only fingerprint context.
	 */
	public function create_inquiry( array $data, ?int $actor_id = null, array $fingerprint_context = array() ) {
		$key = BookingInquiryAdmissionService::canonical_idempotency_key( $data['idempotency_key'] ?? '' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$data['idempotency_key'] = $key;
		$booking                 = $this->reserve_inquiry( $data, $actor_id, $fingerprint_context, wp_generate_uuid4() );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$published = $this->publish_inquiry( $booking );
		if ( ! is_wp_error( $published ) || in_array( $published->get_error_code(), array( 'booking_transaction_commit_uncertain', 'booking_transaction_rollback_failed' ), true ) ) {
			return $published;
		}
		$discarded = $this->discard_reserved_inquiry( $booking );
		return is_wp_error( $discarded ) ? $discarded : $published;
	}

	/** Persist or recover an inquiry without publishing its lifecycle event. */
	public function reserve_inquiry( array $data, ?int $actor_id = null, array $fingerprint_context = array(), string $owner_token = '' ) {
		unset( $data['attachments'] );
		unset( $data['space_key'], $data['performance_start_at'], $data['performance_end_at'], $data['production'], $data['deal'], $data['confirmed_deal'] );
		$key = (string) ( $data['idempotency_key'] ?? '' );
		if ( '' === $key || '' === $owner_token || BookingInquiryAdmissionService::canonical_idempotency_key( $key ) !== $key ) {
			return new \WP_Error( 'booking_idempotency_key_required', __( 'Inquiry creation requires an idempotency key.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venue_id = absint( $data['venue_term_id'] ?? 0 );
		$hash     = $this->request_hash( $data, $actor_id, $fingerprint_context );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		$existing = $this->bookings->find_inquiry( $venue_id, $key );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			$retry = $this->resolve_retry( $existing, $hash );
			return is_array( $retry ) && 'admission_pending' === $retry['status'] ? $this->bookings->claim_admission( $retry, $owner_token ) : $retry;
		}
		$venue = get_term( $venue_id, 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_booking_config_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
		}
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		global $wpdb;
		$locked_venue = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $venue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes inquiry admission with config updates.
		if ( '' !== (string) $wpdb->last_error || $venue_id !== (int) $locked_venue ) {
			return $this->rollback( new \WP_Error( 'booking_inquiry_venue_lock_failed', __( 'The venue booking admission state could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		wp_cache_delete( $venue_id, 'term_meta' );
		$config = $this->config->get( $venue_id );
		if ( is_wp_error( $config ) ) {
			return $this->rollback( $config );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_inquiry_config_read_failed', __( 'The venue booking admission state could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		if ( empty( $config['enabled'] ) ) {
			return $this->rollback( new \WP_Error( 'booking_inquiry_admission_disabled', __( 'This venue is not accepting booking inquiries.', 'extrachill-events' ), array( 'status' => 403 ) ) );
		}
		$intake = $this->validate_public_intake( $data['intake'] ?? array(), $config );
		if ( is_wp_error( $intake ) ) {
			return $this->rollback( $intake );
		}
		$data['intake'] = $intake;

		$booking = $this->bookings->create(
			array_merge(
				$data,
				array(
					'status'                  => 'admission_pending',
					'admission_owner_token'   => $owner_token,
					'inquiry_idempotency_key' => $key,
					'inquiry_request_hash'    => $hash,
					'submitter_user_id'       => $actor_id,
				)
			)
		);
		if ( is_wp_error( $booking ) && 'booking_idempotent_insert_failed' === $booking->get_error_code() ) {
			$database_error = (string) ( $booking->get_error_data()['database_error'] ?? '' );
			$rolled         = $this->rollback( $booking );
			if ( 'booking_idempotent_insert_failed' !== $rolled->get_error_code() ) {
				return $rolled;
			}
			$winner = $this->bookings->find_inquiry( $venue_id, $key );
			if ( is_wp_error( $winner ) ) {
				return $winner;
			}
			return is_array( $winner )
				? $this->resolve_retry( $winner, $hash )
				: new \WP_Error( 'booking_create_failed', __( 'The booking could not be created.', 'extrachill-events' ), array( 'database_error' => $database_error ) );
		}
		if ( is_wp_error( $booking ) ) {
			return $this->rollback( $booking );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->bookings->get( $booking['id'], true );
	}

	/** Validate the revision, consent, and configured public field values. */
	private function validate_public_intake( $intake, array $config ) {
		if ( ! is_array( $intake ) ) {
			return new \WP_Error( 'booking_inquiry_invalid_intake', __( 'A valid booking inquiry is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		// Existing operator and integration callers predate the public projection.
		if ( ! array_key_exists( 'config_revision', $intake ) ) {
			return $intake;
		}
		if ( (int) ( $intake['config_revision'] ?? -1 ) !== (int) $config['revision'] ) {
			return new \WP_Error( 'booking_config_revision_conflict', __( 'The venue booking configuration changed since this form loaded.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$message = sanitize_textarea_field( (string) ( $intake['message'] ?? '' ) );
		if ( '' === $message || mb_strlen( $message ) > 10000 ) {
			return new \WP_Error( 'booking_inquiry_message_invalid', __( 'Tell the venue about the proposed performance.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$submitted_consent = is_array( $intake['consent'] ?? null ) ? $intake['consent'] : array();
		$consent           = $config['consent'];
		if ( ( $submitted_consent['id'] ?? '' ) !== $consent['id'] || (int) ( $submitted_consent['version'] ?? 0 ) !== (int) $consent['version'] || ( $consent['required'] && true !== ( $submitted_consent['accepted'] ?? false ) ) ) {
			return new \WP_Error( 'booking_inquiry_consent_invalid', __( 'Current booking consent is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$values     = is_array( $intake['fields'] ?? null ) ? $intake['fields'] : array();
		$normalized = array();
		foreach ( $config['intake']['fields'] as $field ) {
			$condition = $field['visible_when'] ?? null;
			$visible   = ! is_array( $condition ) || (string) ( $normalized[ $condition['field'] ] ?? '' ) === (string) $condition['value'];
			if ( ! $visible ) {
				$normalized[ $field['key'] ] = 'checkbox' === $field['type'] ? false : ( 'url_list' === $field['type'] ? array() : '' );
				continue;
			}
			$value = $values[ $field['key'] ] ?? ( 'checkbox' === $field['type'] ? false : '' );
			if ( 'checkbox' === $field['type'] ) {
				$value = true === $value;
			} elseif ( 'number' === $field['type'] ) {
				$value = '' === $value ? '' : filter_var( $value, FILTER_VALIDATE_FLOAT );
				if ( false === $value ) {
					return new \WP_Error(
						'booking_inquiry_field_invalid',
						__( 'A configured booking field is invalid.', 'extrachill-events' ),
						array(
							'status' => 400,
							'field'  => $field['key'],
						)
					);
				}
			} elseif ( 'textarea' === $field['type'] ) {
				$value = sanitize_textarea_field( (string) $value );
			} elseif ( 'email' === $field['type'] ) {
				$value = sanitize_email( (string) $value );
			} elseif ( 'url_list' === $field['type'] ) {
				$lines = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
				$value = array_values( array_filter( array_map( 'trim', array_slice( (array) $lines, 0, 20 ) ) ) );
				foreach ( $value as $index => $url ) {
					$sanitized = esc_url_raw( $url );
					$scheme    = wp_parse_url( $sanitized, PHP_URL_SCHEME );
					$host      = wp_parse_url( $sanitized, PHP_URL_HOST );
					if ( '' === $sanitized || $sanitized !== $url || ! in_array( $scheme, array( 'http', 'https' ), true ) || ! is_string( $host ) || '' === $host ) {
						return new \WP_Error(
							'booking_inquiry_field_invalid',
							__( 'A configured booking field is invalid.', 'extrachill-events' ),
							array(
								'status' => 400,
								'field'  => $field['key'],
							)
						);
					}
					$value[ $index ] = $sanitized;
				}
			} elseif ( 'url' === $field['type'] ) {
				$value = esc_url_raw( (string) $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			if ( $field['required'] && ( false === $value || '' === $value || array() === $value ) ) {
				return new \WP_Error(
					'booking_inquiry_field_required',
					__( 'A required booking field is missing.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field['key'],
					)
				);
			}
			if ( 'select' === $field['type'] && '' !== $value && ! in_array( $value, $field['options'], true ) ) {
				return new \WP_Error(
					'booking_inquiry_field_invalid',
					__( 'A configured booking field is invalid.', 'extrachill-events' ),
					array(
						'status' => 400,
						'field'  => $field['key'],
					)
				);
			}
			$normalized[ $field['key'] ] = $value;
		}
		return array(
			'config_revision' => (int) $config['revision'],
			'message'         => $message,
			'fields'          => $normalized,
			'consent'         => array(
				'id'       => $consent['id'],
				'version'  => (int) $consent['version'],
				'accepted' => true === ( $submitted_consent['accepted'] ?? false ),
			),
		);
	}

	/** Return a completed canonical inquiry after bounded lock contention. */
	public function replay_completed_inquiry( array $data, ?int $actor_id = null, array $fingerprint_context = array() ) {
		unset( $data['attachments'] );
		unset( $data['space_key'], $data['performance_start_at'], $data['performance_end_at'], $data['production'], $data['deal'], $data['confirmed_deal'] );
		$key      = (string) ( $data['idempotency_key'] ?? '' );
		$venue_id = absint( $data['venue_term_id'] ?? 0 );
		$hash     = $this->request_hash( $data, $actor_id, $fingerprint_context );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		$existing = $this->bookings->find_inquiry( $venue_id, $key );
		if ( ! is_array( $existing ) ) {
			return $existing;
		}
		$resolved = $this->resolve_retry( $existing, $hash );
		return is_array( $resolved ) && 'admission_pending' !== $resolved['status'] ? $resolved : null;
	}

	/** Publish an admitted inquiry exactly once after every attachment succeeds. */
	public function publish_inquiry( array $booking ) {
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( (int) ( $booking['id'] ?? 0 ), true );
		if ( ! is_array( $locked ) || empty( $locked['inquiry_idempotency_key'] ) ) {
			return $this->rollback( is_wp_error( $locked ) ? $locked : new \WP_Error( 'booking_inquiry_publication_invalid', __( 'The admitted inquiry could not be published.', 'extrachill-events' ) ) );
		}
		$key      = 'inquiry:' . $locked['inquiry_idempotency_key'];
		$existing = $this->activity->find_by_idempotency( $locked['id'], $key );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		if ( is_array( $existing ) ) {
			$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, (int) $existing['id'] );
			if ( is_wp_error( $request ) ) {
				return $this->rollback( $request );
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $locked;
		}
		$owner_token = $booking['admission_owner_token'] ?? null;
		$version     = (int) ( $booking['version'] ?? 0 );
		if ( 'admission_pending' !== $locked['status'] || empty( $locked['admission_owner_token'] ) || $owner_token !== $locked['admission_owner_token'] || $version !== (int) $locked['version'] ) {
			return $this->rollback( new \WP_Error( 'booking_inquiry_publication_invalid', __( 'Only the owned inquiry reservation can be published.', 'extrachill-events' ) ) );
		}
		$locked = $this->bookings->publish_admission( $locked );
		if ( is_wp_error( $locked ) ) {
			return $this->rollback( $locked );
		}
		$actor_id = $locked['submitter_user_id'];
		$event    = $this->activity->append(
			array(
				'booking_id'      => $locked['id'],
				'kind'            => 'inquiry_submitted',
				'actor_type'      => $actor_id ? 'user' : 'anonymous',
				'actor_id'        => $actor_id,
				'idempotency_key' => $key,
				'payload'         => array( 'status' => 'submitted' ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, (int) $event['id'] );
		if ( is_wp_error( $request ) ) {
			return $this->rollback( $request );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		BookingNotificationService::emit( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, (int) $event['id'] );
		BookingCorrespondenceAutomationService::emit( (int) $event['id'] );
		return $this->bookings->get( $locked['id'] );
	}

	/** Remove a reservation when direct publication fails conclusively. */
	private function discard_reserved_inquiry( array $booking ) {
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$current = $this->bookings->get_for_update( (int) $booking['id'], true );
		if ( ! is_array( $current ) || 'admission_pending' !== $current['status'] || ( $current['admission_owner_token'] ?? null ) !== ( $booking['admission_owner_token'] ?? null ) || (int) $current['version'] !== (int) $booking['version'] ) {
			return $this->rollback( new \WP_Error( 'booking_inquiry_compensation_invalid', __( 'The inquiry reservation changed before compensation.', 'extrachill-events' ) ) );
		}
		$activity = $this->activity->discard_for_booking( (int) $booking['id'] );
		$discard  = is_wp_error( $activity ) ? $activity : $this->bookings->discard_inquiry( $current );
		if ( is_wp_error( $discard ) ) {
			return $this->rollback( $discard );
		}
		return $this->commit();
	}

	/**
	 * Bind an unresolved booking to existing artist identities.
	 *
	 * @param int      $booking_id       Booking ID.
	 * @param int|null $artist_term_id    Canonical artist term.
	 * @param int|null $artist_profile_id Artist Platform profile.
	 * @param int      $expected_version  Expected aggregate version.
	 * @param int      $actor_id          Acting operator.
	 */
	public function bind_artist( int $booking_id, $artist_term_id, $artist_profile_id, int $expected_version, int $actor_id ) {
		$current = $this->bookings->get( $booking_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$current = $this->holds->reconcile_booking( $current );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( (int) $current['version'] !== $expected_version ) {
			return $this->version_conflict( $current );
		}
		$term_id    = null === $artist_term_id ? null : absint( $artist_term_id );
		$profile_id = null === $artist_profile_id ? null : absint( $artist_profile_id );
		if ( null === $term_id && null === $profile_id ) {
			return new \WP_Error( 'booking_artist_binding_required', __( 'An artist term or profile is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		if ( ( $current['artist_term_id'] && null !== $term_id && $current['artist_term_id'] !== $term_id ) || ( $current['artist_profile_id'] && null !== $profile_id && $current['artist_profile_id'] !== $profile_id ) ) {
			return new \WP_Error( 'booking_artist_already_bound', __( 'Existing booking artist bindings cannot be replaced implicitly.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$started = $this->begin_authorized( $current, $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $locked ) ) {
			return $this->rollback( is_wp_error( $locked ) ? $locked : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		if ( (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_conflict( $locked ) );
		}
		$conversion = $this->check_event_conversion_pending( $locked );
		if ( is_wp_error( $conversion ) ) {
			return $this->rollback( $conversion );
		}
		if ( null !== $locked['event_id'] ) {
			return $this->rollback( new \WP_Error( 'booking_event_artist_frozen', __( 'Public event performer identity is frozen; use the event correction path to synchronize artist changes.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( ( $locked['artist_term_id'] && null !== $term_id && $locked['artist_term_id'] !== $term_id ) || ( $locked['artist_profile_id'] && null !== $profile_id && $locked['artist_profile_id'] !== $profile_id ) ) {
			return $this->rollback( new \WP_Error( 'booking_artist_already_bound', __( 'Existing booking artist bindings cannot be replaced implicitly.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$changes = array();
		if ( null !== $term_id ) {
			$changes['artist_term_id'] = $term_id;
		}
		if ( null !== $profile_id ) {
			$changes['artist_profile_id'] = $profile_id;
		}
		$updated = $this->bookings->update( $booking_id, $changes, $expected_version );
		if ( is_wp_error( $updated ) ) {
			return $this->rollback( $updated );
		}
		$event = $this->activity->append(
			array(
				'booking_id' => $booking_id,
				'kind'       => 'artist_bound',
				'actor_type' => 'user',
				'actor_id'   => $actor_id,
				'payload'    => array(
					'artist_term_id'    => $updated['artist_term_id'],
					'artist_profile_id' => $updated['artist_profile_id'],
					'version'           => $updated['version'],
				),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->bookings->get( $booking_id );
	}

	/**
	 * Transition lifecycle state after validating the complete domain rule.
	 *
	 * @param int         $booking_id      Booking ID.
	 * @param string      $to_status       Target lifecycle status.
	 * @param int         $expected_version Expected aggregate version.
	 * @param int         $actor_id         Acting operator.
	 * @param string|null $note             Optional operator note.
	 */
	public function transition( int $booking_id, string $to_status, int $expected_version, int $actor_id, ?string $note = null ) {
		$current = $this->bookings->get( $booking_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$current = $this->holds->reconcile_booking( $current );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		if ( (int) $current['version'] !== $expected_version ) {
			return $this->version_conflict( $current );
		}
		$valid = $this->validate_transition( $current, $to_status );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$payload = array(
			'from_status' => $current['status'],
			'to_status'   => $to_status,
			'note'        => null === $note ? null : mb_substr( sanitize_text_field( $note ), 0, 1000 ),
		);
		if ( in_array( $to_status, array( 'held', 'confirmed' ), true ) || ( 'held' === $current['status'] && in_array( $to_status, array( 'negotiating', 'declined', 'withdrawn', 'cancelled' ), true ) ) ) {
			return $this->holds->transition( $current, $to_status, $expected_version, $actor_id, $payload['note'] );
		}
		return $this->mutate( $current, array( 'status' => $to_status ), $expected_version, 'status_changed', $payload, $actor_id );
	}

	/**
	 * Validate one explicit edge and its target-state prerequisites.
	 *
	 * @param array  $booking   Hydrated booking.
	 * @param string $to_status Target lifecycle status.
	 */
	public function validate_transition( array $booking, string $to_status ) {
		$from_status = (string) ( $booking['status'] ?? '' );
		if ( ! isset( self::TRANSITIONS[ $from_status ] ) || ! in_array( $to_status, self::TRANSITIONS[ $from_status ], true ) ) {
			return new \WP_Error(
				'booking_transition_forbidden',
				__( 'The requested booking transition is not allowed.', 'extrachill-events' ),
				array(
					'status'      => 409,
					'from_status' => $from_status,
					'to_status'   => $to_status,
				)
			);
		}
		if ( 'held' === $to_status && ( empty( $booking['performance_start_at'] ) || empty( $booking['performance_end_at'] ) || empty( $booking['space_key'] ) ) ) {
			return new \WP_Error( 'booking_hold_selection_required', __( 'A hold requires a selected date range and space.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( 'confirmed' === $to_status ) {
			if ( empty( $booking['performance_start_at'] ) || empty( $booking['performance_end_at'] ) || empty( $booking['space_key'] ) ) {
				return new \WP_Error( 'booking_confirmation_selection_required', __( 'Confirmation requires a selected date range and space.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			if ( empty( $booking['deal']['data'] ) ) {
				return new \WP_Error( 'booking_confirmation_deal_required', __( 'Confirmation requires deal terms.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$normalized_deal = BookingMutationService::normalize_deal_document( $booking['deal']['data'] );
			if ( is_wp_error( $normalized_deal ) || ! BookingMutationService::documents_equal( $normalized_deal, $booking['deal']['data'] ) ) {
				return new \WP_Error( 'booking_confirmation_deal_invalid', __( 'Confirmation requires a normalized complete draft deal.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			return true;
		}
		return true;
	}

	/**
	 * Execute one optimistic mutation and activity append in one transaction.
	 *
	 * @param array  $current          Current booking.
	 * @param array  $changes          Database column changes.
	 * @param int    $expected_version Expected aggregate version.
	 * @param string $kind             Activity kind.
	 * @param array  $payload          Activity payload.
	 * @param int    $actor_id         Acting operator.
	 */
	private function mutate( array $current, array $changes, int $expected_version, string $kind, array $payload, int $actor_id ) {
		global $wpdb;
		$started = $this->begin_authorized( $current, $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $current['id'] );
		if ( ! is_array( $locked ) ) {
			return $this->rollback( is_wp_error( $locked ) ? $locked : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		if ( (int) $locked['version'] !== $expected_version ) {
			return $this->rollback( $this->version_conflict( $locked ) );
		}
		$conversion = $this->check_event_conversion_pending( $locked );
		if ( is_wp_error( $conversion ) ) {
			return $this->rollback( $conversion );
		}
		$current = $locked;
		$set     = array();
		$values  = array();
		foreach ( $changes as $column => $value ) {
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
			} elseif ( is_int( $value ) ) {
				$set[]    = "{$column} = %d";
				$values[] = $value;
			} else {
				$set[]    = "{$column} = %s";
				$values[] = $value;
			}
		}
		$set[]    = 'version = version + 1';
		$set[]    = 'updated_at = %s';
		$values[] = gmdate( 'Y-m-d H:i:s' );
		$values[] = $current['id'];
		$values[] = $expected_version;
		$table    = BookingSchema::bookings_table();
		$query    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND version = %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Internal columns and current-prefix table.
		$result   = $wpdb->query( $wpdb->prepare( $query, $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values are prepared.
		if ( false === $result ) {
			return $this->rollback( new \WP_Error( 'booking_update_failed', __( 'The booking could not be updated.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		if ( 0 === $result ) {
			$latest = $this->bookings->get( $current['id'] );
			$error  = null === $latest
				? new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) )
				: new \WP_Error(
					'booking_version_conflict',
					__( 'The booking changed since it was read.', 'extrachill-events' ),
					array(
						'status'          => 409,
						'current_version' => is_array( $latest ) ? $latest['version'] : null,
					)
				);
			return $this->rollback( is_wp_error( $latest ) ? $latest : $error );
		}
		$event = $this->activity->append(
			array(
				'booking_id' => $current['id'],
				'kind'       => $kind,
				'actor_type' => 'user',
				'actor_id'   => $actor_id,
				'payload'    => array_merge( $payload, array( 'version' => $expected_version + 1 ) ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		if ( 'status_changed' === $kind && 'needs_info' === ( $payload['from_status'] ?? '' ) && 'submitted' === ( $payload['to_status'] ?? '' ) ) {
			BookingNotificationService::emit( BookingNotificationService::TYPE_INFORMATION_RECEIVED, (int) $event['id'] );
		}
		return $this->bookings->get( $current['id'] );
	}

	/** Fail closed before any lifecycle-owned version change while conversion runs. */
	private function check_event_conversion_pending( array $booking ) {
		$state = $this->activity->event_conversion_state( $booking['id'], $booking['public_id'] );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		return $state['pending']
			? new \WP_Error( 'booking_event_conversion_pending', __( 'The booking cannot change while event conversion is pending.', 'extrachill-events' ), array( 'status' => 409 ) )
			: true;
	}

	/** Start the aggregate transaction. */
	private function begin() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		return false === $wpdb->query( 'START TRANSACTION' )
			? new \WP_Error( 'booking_transaction_start_failed', __( 'The booking transaction could not be started.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) )
			: true;
	}

	/** Commit the aggregate transaction without guessing after failure. */
	private function commit() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		if ( false !== $wpdb->query( 'COMMIT' ) ) {
			return true;
		}
		return new \WP_Error( 'booking_transaction_commit_uncertain', __( 'The booking transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
	}

	/**
	 * Roll back and preserve the original error when successful.
	 *
	 * @param \WP_Error $cause Original failure.
	 */
	private function rollback( \WP_Error $cause ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
		if ( false === $wpdb->query( 'ROLLBACK' ) ) {
			return new \WP_Error(
				'booking_transaction_rollback_failed',
				__( 'The booking transaction could not be rolled back.', 'extrachill-events' ),
				array(
					'database_error' => $wpdb->last_error,
					'cause'          => $cause->get_error_code(),
				)
			);
		}
		return $cause;
	}

	/**
	 * Build the stable optimistic-concurrency conflict.
	 *
	 * @param array $current Current booking.
	 */
	private function version_conflict( array $current ): \WP_Error {
		return new \WP_Error(
			'booking_version_conflict',
			__( 'The booking changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => $current['version'],
			)
		);
	}

	/**
	 * Start, lock venue authority rows, and reauthorize transaction actors.
	 *
	 * @param array $booking  Current booking.
	 * @param int   $actor_id Acting operator.
	 */
	private function begin_authorized( array $booking, int $actor_id ) {
		global $wpdb;
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$table  = BookingSchema::memberships_table();
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- First transactional read locks and returns current venue authority.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		$actor_allowed = $this->authorization->authorize_locked( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE, (array) $locked );
		if ( true !== $actor_allowed ) {
			return $this->rollback( is_wp_error( $actor_allowed ) ? $actor_allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
		}
		return true;
	}

	/**
	 * Compare a retry without exposing the prior inquiry on key reuse.
	 *
	 * @param array  $booking      Existing booking.
	 * @param string $request_hash Current request fingerprint.
	 */
	private function resolve_retry( array $booking, string $request_hash ) {
		$stored_hash = (string) ( $booking['inquiry_request_hash'] ?? '' );
		return 64 === strlen( $stored_hash ) && hash_equals( $stored_hash, $request_hash )
			? $booking
			: new \WP_Error( 'booking_idempotency_conflict', __( 'The idempotency key was already used for a different request.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/**
	 * Create a deterministic actor-bound HMAC for public inquiry retries.
	 *
	 * @param array    $data     Inquiry request.
	 * @param int|null $actor_id            Authenticated actor.
	 * @param array    $fingerprint_context Admission-only context.
	 */
	private function request_hash( array $data, ?int $actor_id, array $fingerprint_context = array() ) {
		unset( $data['idempotency_key'] );
		$payload = array(
			'actor_id' => $actor_id,
			'request'  => $this->canonicalize( $data ),
		);
		if ( $fingerprint_context ) {
			$payload['context'] = $this->canonicalize( $fingerprint_context );
		}
		$json = wp_json_encode( $payload );
		return false === $json
			? new \WP_Error( 'booking_request_hash_failed', __( 'The booking request could not be fingerprinted.', 'extrachill-events' ) )
			: hash_hmac( 'sha256', $json, wp_salt( 'auth' ) );
	}

	/**
	 * Recursively sort object keys while retaining list order.
	 *
	 * @param mixed $value Value to canonicalize.
	 */
	private function canonicalize( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}
}
