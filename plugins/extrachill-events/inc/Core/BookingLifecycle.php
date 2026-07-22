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

	/**
	 * Build the aggregate from its two owned repositories.
	 *
	 * @param BookingRepository|null         $bookings Booking persistence.
	 * @param BookingActivityRepository|null $activity      Activity persistence.
	 * @param VenueAuthorization|null        $authorization Exact venue authorization.
	 * @param VenueBookingConfig|null        $config        Admission configuration.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?VenueBookingConfig $config = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig();
	}

	/**
	 * Create a submitted inquiry and its receipt event exactly once.
	 *
	 * @param array    $data     Inquiry fields.
	 * @param int|null $actor_id Authenticated submitter, when present.
	 */
	public function create_inquiry( array $data, ?int $actor_id = null ) {
		$key = mb_substr( sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) ), 0, 191 );
		if ( '' === $key ) {
			return new \WP_Error( 'booking_idempotency_key_required', __( 'Inquiry creation requires an idempotency key.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venue_id = absint( $data['venue_term_id'] ?? 0 );
		$hash     = $this->request_hash( $data, $actor_id );
		if ( is_wp_error( $hash ) ) {
			return $hash;
		}
		$existing = $this->bookings->find_inquiry( $venue_id, $key );
		if ( is_wp_error( $existing ) ) {
			return $existing;
		}
		if ( is_array( $existing ) ) {
			return $this->resolve_retry( $existing, $hash );
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
		$booking = $this->bookings->create(
			array_merge(
				$data,
				array(
					'status'                  => 'submitted',
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
		$event = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'inquiry_submitted',
				'actor_type'      => $actor_id ? 'user' : 'anonymous',
				'actor_id'        => $actor_id,
				'idempotency_key' => 'inquiry:' . $key,
				'payload'         => array( 'status' => 'submitted' ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->bookings->get( $booking['id'] );
	}

	/**
	 * Assign or unassign an operator at an expected booking version.
	 *
	 * @param int      $booking_id      Booking ID.
	 * @param int|null $assignee_user_id Assignee, or null to unassign.
	 * @param int      $expected_version Expected aggregate version.
	 * @param int      $actor_id         Acting operator.
	 */
	public function assign( int $booking_id, $assignee_user_id, int $expected_version, int $actor_id ) {
		if ( null !== $assignee_user_id && ( ( ! is_int( $assignee_user_id ) && ! ( is_string( $assignee_user_id ) && ctype_digit( $assignee_user_id ) ) ) || (int) $assignee_user_id < 1 ) ) {
			return new \WP_Error( 'invalid_booking_assignee', __( 'The assignee is not authorized for this venue.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$assignee_user_id = null === $assignee_user_id ? null : (int) $assignee_user_id;
		$current          = $this->bookings->get( $booking_id );
		if ( is_wp_error( $current ) || null === $current ) {
			return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		if ( (int) $current['version'] !== $expected_version ) {
			return $this->version_conflict( $current );
		}
		if ( null !== $assignee_user_id && true !== $this->authorization->authorize( $assignee_user_id, $current['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) ) {
			return new \WP_Error( 'invalid_booking_assignee', __( 'The assignee is not authorized for this venue.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		if ( $current['assignee_user_id'] === $assignee_user_id ) {
			$started = $this->begin_authorized( $current, $actor_id, $assignee_user_id );
			if ( is_wp_error( $started ) ) {
				return $started;
			}
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $current;
		}

		return $this->mutate(
			$current,
			array( 'assignee_user_id' => $assignee_user_id ),
			$expected_version,
			'assignment_changed',
			array(
				'from_assignee_user_id' => $current['assignee_user_id'],
				'to_assignee_user_id'   => $assignee_user_id,
			),
			$actor_id,
			$assignee_user_id
		);
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
		if ( 'held' === $to_status ) {
			return new \WP_Error(
				'booking_hold_repository_unavailable',
				__( 'A booking cannot be held until the active-hold repository is available.', 'extrachill-events' ),
				array(
					'status'             => 503,
					'prerequisite_issue' => 295,
				)
			);
		}
		if ( 'confirmed' === $to_status ) {
			if ( empty( $booking['requested_start_at'] ) || empty( $booking['requested_end_at'] ) || empty( $booking['space_key'] ) ) {
				return new \WP_Error( 'booking_confirmation_selection_required', __( 'Confirmation requires a selected date range and space.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			if ( empty( $booking['deal']['data'] ) ) {
				return new \WP_Error( 'booking_confirmation_deal_required', __( 'Confirmation requires deal terms.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			return new \WP_Error( 'booking_conflict_repository_unavailable', __( 'A booking cannot be confirmed until conflict detection is available.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return true;
	}

	/**
	 * Execute one optimistic mutation and activity append in one transaction.
	 *
	 * @param array    $current          Current booking.
	 * @param array    $changes          Database column changes.
	 * @param int      $expected_version Expected aggregate version.
	 * @param string   $kind             Activity kind.
	 * @param array    $payload          Activity payload.
	 * @param int      $actor_id         Acting operator.
	 * @param int|null $target_user_id Assignment target when applicable.
	 */
	private function mutate( array $current, array $changes, int $expected_version, string $kind, array $payload, int $actor_id, ?int $target_user_id = null ) {
		global $wpdb;
		$started = $this->begin_authorized( $current, $actor_id, $target_user_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$set    = array();
		$values = array();
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
		return is_wp_error( $committed ) ? $committed : $this->bookings->get( $current['id'] );
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
	 * @param array    $booking        Current booking.
	 * @param int      $actor_id       Acting operator.
	 * @param int|null $target_user_id Assignment target when applicable.
	 */
	private function begin_authorized( array $booking, int $actor_id, ?int $target_user_id = null ) {
		global $wpdb;
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$table = BookingSchema::memberships_table();
		$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact venue authority range.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		$actor_allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $actor_allowed ) {
			return $this->rollback( is_wp_error( $actor_allowed ) ? $actor_allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
		}
		if ( null !== $target_user_id && true !== $this->authorization->authorize( $target_user_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) ) {
			return $this->rollback( new \WP_Error( 'invalid_booking_assignee', __( 'The assignee is not authorized for this venue.', 'extrachill-events' ), array( 'status' => 403 ) ) );
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
	 * @param int|null $actor_id Authenticated actor.
	 */
	private function request_hash( array $data, ?int $actor_id ) {
		unset( $data['idempotency_key'] );
		$payload = array(
			'actor_id' => $actor_id,
			'request'  => $this->canonicalize( $data ),
		);
		$json    = wp_json_encode( $payload );
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
