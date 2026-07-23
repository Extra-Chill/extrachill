<?php
/**
 * Durable booking holds and venue-space conflict serialization.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns hold persistence, expiration, conflicts, and hold-aware transitions. */
class BookingHoldRepository {

	public const STATUSES        = array( 'active', 'released', 'expired', 'converted' );
	public const EXPIRY_HOOK     = 'extrachill_events_expire_booking_hold';
	public const SCHEDULER_GROUP = 'extrachill-events-booking-holds';

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var VenueBookingConfig */
	private $config;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, ?VenueBookingConfig $config = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig();
	}

	/** Register the cleanup callback outside CLI-only bootstrap paths. */
	public static function register(): void {
		add_action( self::EXPIRY_HOOK, array( self::class, 'expire_scheduled' ), 10, 2 );
	}

	/** Action Scheduler callback. Correctness does not depend on this callback running. */
	public static function expire_scheduled( int $hold_id, int $attempt = 0 ): void {
		$attempt = max( 0, min( 3, $attempt ) );
		$result  = ( new self() )->expire( $hold_id );
		if ( ! is_wp_error( $result ) ) {
			return;
		}
		if ( $attempt < 3 && function_exists( 'as_schedule_single_action' ) ) {
			try {
				$scheduled = as_schedule_single_action( time() + ( MINUTE_IN_SECONDS * ( 2 ** $attempt ) ), self::EXPIRY_HOOK, array( $hold_id, $attempt + 1 ), self::SCHEDULER_GROUP, true );
				if ( ! $scheduled && function_exists( 'do_action' ) ) {
					do_action( 'extrachill_events_booking_hold_schedule_failed', $hold_id, null, new \RuntimeException( 'Booking hold expiration retry was not scheduled.' ) );
				}
			} catch ( \Throwable $throwable ) {
				if ( function_exists( 'do_action' ) ) {
					do_action( 'extrachill_events_booking_hold_schedule_failed', $hold_id, null, $throwable );
				}
			}
		}
		throw new \RuntimeException( 'Booking hold expiration failed.' );
	}

	/** Persist elapsed selected-hold consequences before normal held-state work. */
	public function reconcile_booking( array $booking ) {
		if ( 'held' !== ( $booking['status'] ?? '' ) ) {
			return $booking;
		}
		global $wpdb;
		$table = BookingSchema::holds_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT *, UTC_TIMESTAMP() AS database_now FROM {$table} WHERE booking_id = %d AND venue_term_id = %d AND space_key = %s AND start_at = %s AND end_at = %s AND status = 'active' AND expires_at <= UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1", $booking['id'], $booking['venue_term_id'], $booking['space_key'], $booking['performance_start_at'], $booking['performance_end_at'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Detects only the elapsed exact selected hold by database time.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_reconciliation_failed', __( 'The held booking could not be reconciled.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( ! is_array( $row ) ) {
			return $booking;
		}
		$expired = $this->expire( (int) $row['id'] );
		if ( is_wp_error( $expired ) ) {
			return $expired;
		}
		$refreshed = $this->bookings->get( $booking['id'] );
		return is_array( $refreshed ) ? $refreshed : ( is_wp_error( $refreshed ) ? $refreshed : new \WP_Error( 'booking_not_found', __( 'The booking was not found after reconciliation.', 'extrachill-events' ) ) );
	}

	/** Reconcile bounded stale batches before status filtering and pagination. */
	public function reconcile_venue( int $venue_id ) {
		global $wpdb;
		$venue_id = absint( $venue_id );
		if ( $venue_id < 1 ) {
			return new \WP_Error( 'invalid_booking_hold_venue', __( 'A venue is required.', 'extrachill-events' ) );
		}
		$bookings  = BookingSchema::bookings_table();
		$holds     = BookingSchema::holds_table();
		$processed = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT b.* FROM {$bookings} b INNER JOIN {$holds} h ON h.booking_id = b.id AND h.venue_term_id = b.venue_term_id AND h.space_key = b.space_key AND h.start_at = b.performance_start_at AND h.end_at = b.performance_end_at WHERE b.venue_term_id = %d AND b.status = 'held' AND h.status = 'active' AND h.expires_at <= UTC_TIMESTAMP() ORDER BY b.id ASC LIMIT 100", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Selects only stale exact held aggregates by database time.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'booking_hold_venue_reconciliation_failed', __( 'Held bookings for this venue could not be reconciled.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
			}
			$batch_count = count( (array) $rows );
			foreach ( (array) $rows as $row ) {
				$booking = $this->bookings->hydrate( $row );
				if ( is_wp_error( $booking ) ) {
					return $booking;
				}
				$result = $this->reconcile_booking( $booking );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				++$processed;
			}
			if ( $processed >= 1000 && 100 === $batch_count ) {
				$remaining = $this->venue_has_stale_booking( $venue_id );
				if ( is_wp_error( $remaining ) ) {
					return $remaining;
				}
				if ( $remaining ) {
					return new \WP_Error(
						'booking_hold_venue_reconciliation_limit',
						__( 'More stale held bookings remain for this venue; retry the request.', 'extrachill-events' ),
						array(
							'status'    => 503,
							'processed' => $processed,
						)
					);
				}
				break;
			}
		} while ( 100 === $batch_count );
		return true;
	}

	/** Reconcile, then read one booking while exact venue authority is locked. */
	public function get_booking_authorized( int $booking_id, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$allowed = $this->authorize_venue_now( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// Reconciliation is system invariant maintenance with no operator-authored values. It runs before membership locks to preserve advisory -> membership lock order.
		$booking = $this->reconcile_booking( $booking );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$started = $this->begin_venue_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$result = $this->bookings->get( $booking_id );
		if ( ! is_array( $result ) ) {
			return $this->rollback( is_wp_error( $result ) ? $result : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $result;
	}

	/** Reconcile, then list bookings while exact venue authority is locked. */
	public function list_bookings_authorized( array $filters, int $actor_id ) {
		$venue_id = absint( $filters['venue_term_id'] ?? 0 );
		$allowed  = $this->authorize_venue_now( $venue_id, $actor_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		// System reconciliation stays outside membership transactions; final private output is protected by locked reauthorization below.
		$reconciled = $this->reconcile_venue( $venue_id );
		if ( is_wp_error( $reconciled ) ) {
			return $reconciled;
		}
		$started = $this->begin_venue_authorized( $venue_id, $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$result = $this->bookings->list( $filters );
		if ( ! is_array( $result ) ) {
			return $this->rollback( $result );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $result;
	}

	/** Check whether stale exact held state remains after the operational cap. */
	private function venue_has_stale_booking( int $venue_id ) {
		global $wpdb;
		$bookings = BookingSchema::bookings_table();
		$holds    = BookingSchema::holds_table();
		$row      = $wpdb->get_var( $wpdb->prepare( "SELECT b.id FROM {$bookings} b INNER JOIN {$holds} h ON h.booking_id = b.id AND h.venue_term_id = b.venue_term_id AND h.space_key = b.space_key AND h.start_at = b.performance_start_at AND h.end_at = b.performance_end_at WHERE b.venue_term_id = %d AND b.status = 'held' AND h.status = 'active' AND h.expires_at <= UTC_TIMESTAMP() LIMIT 1", $venue_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cheap database-time post-cap existence check.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_venue_reconciliation_failed', __( 'Remaining stale held bookings could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return null !== $row;
	}

	/** Fresh early authorization before any reconciliation mutation. */
	private function authorize_venue_now( int $venue_id, int $actor_id ) {
		$allowed = $this->authorization->authorize( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : ( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	/** Select the authoritative performance under the venue-space conflict lock. */
	public function select_performance( int $booking_id, int $expected_version, string $space_key, string $start_at, string $end_at, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$allowed = $this->authorize_venue_now( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$space_key = mb_substr( sanitize_key( $space_key ), 0, 64 );
		if ( '' === $space_key || ! $this->valid_datetime( $start_at ) || ! $this->valid_datetime( $end_at ) || $end_at <= $start_at ) {
			return new \WP_Error( 'invalid_booking_performance', __( 'A configured space and strict UTC performance range are required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venue_id = (int) $booking['venue_term_id'];
		return $this->with_booking_locks(
			(int) $booking['venue_term_id'],
			$space_key,
			function () use ( $booking_id, $expected_version, $space_key, $start_at, $end_at, $actor_id, $venue_id ) {
				$started = $this->begin_authorized( $venue_id, $actor_id );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$current = $this->bookings->get( $booking_id );
				if ( ! is_array( $current ) ) {
					return $this->rollback( is_wp_error( $current ) ? $current : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
				}
				if ( (int) $current['version'] !== $expected_version ) {
					return $this->rollback( $this->booking_version_conflict( $current ) );
				}
				if ( ! in_array( $current['status'], array( 'under_review', 'negotiating' ), true ) ) {
					return $this->rollback(
						new \WP_Error(
							'booking_performance_status_forbidden',
							__( 'Performance selection is not allowed in the current booking status.', 'extrachill-events' ),
							array(
								'status'         => 409,
								'booking_status' => $current['status'],
							)
						)
					);
				}
				global $wpdb;
				$locked_venue = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $current['venue_term_id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes configured-space reads.
				if ( '' !== (string) $wpdb->last_error || (int) $locked_venue !== (int) $current['venue_term_id'] ) {
					return $this->rollback( new \WP_Error( 'booking_performance_venue_lock_failed', __( 'The venue performance configuration could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
				}
				wp_cache_delete( $current['venue_term_id'], 'term_meta' );
				$config = $this->config->get( $current['venue_term_id'] );
				if ( is_wp_error( $config ) ) {
					return $this->rollback( $config );
				}
				if ( ! $this->configured_space( $config, $space_key ) ) {
					return $this->rollback( new \WP_Error( 'booking_performance_space_invalid', __( 'The selected booking space is not configured for this venue.', 'extrachill-events' ), array( 'status' => 409 ) ) );
				}
				$proposed                         = $current;
				$proposed['space_key']            = $space_key;
				$proposed['performance_start_at'] = $start_at;
				$proposed['performance_end_at']   = $end_at;
				$conflict                         = $this->find_conflict( $proposed, 0 );
				if ( is_wp_error( $conflict ) || $conflict ) {
					return $this->rollback( is_wp_error( $conflict ) ? $conflict : $this->conflict_error( $conflict ) );
				}
				if ( $current['space_key'] === $space_key && $current['performance_start_at'] === $start_at && $current['performance_end_at'] === $end_at ) {
					$committed = $this->commit();
					return is_wp_error( $committed ) ? $committed : $current;
				}
				$now    = gmdate( 'Y-m-d H:i:s' );
				$table  = BookingSchema::bookings_table();
				$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET space_key = %s, performance_start_at = %s, performance_end_at = %s, version = version + 1, updated_at = %s WHERE id = %d AND version = %d", $space_key, $start_at, $end_at, $now, $booking_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic authoritative selection.
				if ( 1 !== $result ) {
					return $this->rollback( false === $result ? new \WP_Error( 'booking_performance_update_failed', __( 'The performance selection could not be saved.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : $this->booking_version_conflict( $this->bookings->get( $booking_id ) ) );
				}
				$event = $this->activity->append(
					array(
						'booking_id' => $booking_id,
						'kind'       => 'performance_selected',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => array(
							'before'  => array(
								'space_key' => $current['space_key'],
								'start_at'  => $current['performance_start_at'],
								'end_at'    => $current['performance_end_at'],
							),
							'after'   => array(
								'space_key' => $space_key,
								'start_at'  => $start_at,
								'end_at'    => $end_at,
							),
							'version' => $expected_version + 1,
						),
					)
				);
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $this->bookings->get( $booking_id );
			}
		);
	}

	/** Create a hold entirely from persisted booking selection fields. */
	public function create( int $booking_id, int $expected_booking_version, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( is_wp_error( $booking ) || ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		$booking = $this->reconcile_booking( $booking );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		if ( (int) $booking['version'] !== $expected_booking_version ) {
			return $this->booking_version_conflict( $booking );
		}
		$selection = $this->selection( $booking );
		if ( is_wp_error( $selection ) ) {
			return $selection;
		}
		$venue_id = (int) $booking['venue_term_id'];
		$result   = $this->with_booking_locks(
			$venue_id,
			(string) $booking['space_key'],
			function () use ( $booking_id, $expected_booking_version, $actor_id, $venue_id ) {
				$started = $this->begin_authorized( $venue_id, $actor_id );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$booking = $this->bookings->get( $booking_id );
				if ( is_wp_error( $booking ) || ! is_array( $booking ) ) {
					return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
				}
				if ( (int) $booking['version'] !== $expected_booking_version ) {
					return $this->rollback( $this->booking_version_conflict( $booking ) );
				}
				if ( ! in_array( $booking['status'], array( 'negotiating', 'held' ), true ) ) {
					return $this->rollback(
						new \WP_Error(
							'booking_hold_status_forbidden',
							__( 'Holds can only be created for negotiating or held bookings.', 'extrachill-events' ),
							array(
								'status'         => 409,
								'booking_status' => $booking['status'],
							)
						)
					);
				}
				$selection = $this->selection( $booking );
				if ( is_wp_error( $selection ) ) {
					return $this->rollback( $selection );
				}
				$config = $this->config->get( $booking['venue_term_id'] );
				if ( is_wp_error( $config ) ) {
					return $this->rollback( $config );
				}
				if ( ! $this->configured_space( $config, $booking['space_key'] ) ) {
					return $this->rollback( new \WP_Error( 'booking_hold_space_invalid', __( 'The selected booking space is not configured for this venue.', 'extrachill-events' ), array( 'status' => 409 ) ) );
				}
				$existing = $this->has_other_matching_active_hold( $booking, 0 );
				if ( is_wp_error( $existing ) ) {
					return $this->rollback( $existing );
				}
				if ( $existing ) {
					return $this->rollback( new \WP_Error( 'booking_hold_already_active', __( 'An exact active hold already exists for this booking selection.', 'extrachill-events' ), array( 'status' => 409 ) ) );
				}
				$conflict = $this->find_conflict( $booking, 0 );
				if ( is_wp_error( $conflict ) ) {
					return $this->rollback( $conflict );
				}
				if ( $conflict ) {
					return $this->rollback( $this->conflict_error( $conflict ) );
				}

				global $wpdb;
				$clock = $wpdb->get_row( $wpdb->prepare( 'SELECT UTC_TIMESTAMP() AS current_at, DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d MINUTE) AS expires_at', (int) $config['hold_ttl_minutes'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL time defines the complete hold lifetime.
				if ( '' !== (string) $wpdb->last_error || ! is_array( $clock ) || empty( $clock['current_at'] ) || empty( $clock['expires_at'] ) ) {
					return $this->rollback( new \WP_Error( 'booking_hold_clock_read_failed', __( 'The booking hold expiration could not be calculated.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
				}
				$now       = $clock['current_at'];
				$expires   = $clock['expires_at'];
				$bookings  = BookingSchema::bookings_table();
				$increment = $wpdb->query( $wpdb->prepare( "UPDATE {$bookings} SET version = version + 1, updated_at = %s WHERE id = %d AND version = %d", $now, $booking_id, $expected_booking_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate optimistic mutation.
				if ( false === $increment ) {
					return $this->rollback( new \WP_Error( 'booking_hold_booking_update_failed', __( 'The booking could not be updated for the hold.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
				}
				if ( 0 === $increment ) {
					return $this->rollback( $this->booking_version_conflict( $this->bookings->get( $booking_id ) ) );
				}
				$row = array(
					'booking_id'           => $booking_id,
					'venue_term_id'        => $booking['venue_term_id'],
					'space_key'            => $booking['space_key'],
					'start_at'             => $booking['performance_start_at'],
					'end_at'               => $booking['performance_end_at'],
					'expires_at'           => $expires,
					'status'               => 'active',
					'version'              => 1,
					'created_by_user_id'   => $actor_id,
					'created_at'           => $now,
					'updated_at'           => $now,
					'released_at'          => null,
					'released_by_user_id'  => null,
					'release_reason'       => null,
					'expired_at'           => null,
					'converted_at'         => null,
					'converted_by_user_id' => null,
				);
				if ( false === $wpdb->insert( BookingSchema::holds_table(), $row ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private hold insert.
					return $this->rollback( new \WP_Error( 'booking_hold_create_failed', __( 'The booking hold could not be created.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
				}
				$hold_id = (int) $wpdb->insert_id;
				$event   = $this->activity->append(
					array(
						'booking_id' => $booking_id,
						'kind'       => 'hold_created',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => array(
							'hold_id'    => $hold_id,
							'expires_at' => $expires,
							'version'    => $expected_booking_version + 1,
						),
					)
				);
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$hold      = $this->hydrate(
					array_merge(
						$row,
						array(
							'id'           => $hold_id,
							'database_now' => $now,
						)
					)
				);
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
				return array(
					'hold'            => $hold,
					'booking_version' => $expected_booking_version + 1,
					'_schedule'       => array( $hold_id, $expires ),
				);
			}
		);
		if ( is_array( $result ) && isset( $result['_schedule'] ) ) {
			$this->schedule( $result['_schedule'][0], $result['_schedule'][1] );
			unset( $result['_schedule'] );
		}
		return $result;
	}

	/** Release one active hold at its optimistic version. Booking version is unchanged. */
	public function release( int $hold_id, int $expected_version, int $actor_id, string $reason ) {
		$reason = mb_substr( sanitize_text_field( $reason ), 0, 255 );
		if ( '' === $reason ) {
			return new \WP_Error( 'booking_hold_release_reason_required', __( 'A release reason is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$hold = $this->raw_get( $hold_id );
		if ( is_wp_error( $hold ) || ! is_array( $hold ) ) {
			return is_wp_error( $hold ) ? $hold : new \WP_Error( 'booking_hold_not_found', __( 'The booking hold was not found.', 'extrachill-events' ) );
		}
		$elapsed = $this->hold_is_elapsed( $hold_id );
		if ( is_wp_error( $elapsed ) ) {
			return $elapsed;
		}
		if ( 'active' === $hold['status'] && $elapsed ) {
			$reconciled = $this->reconcile_hold_booking( $hold );
			return is_wp_error( $reconciled ) ? $reconciled : $this->hold_not_active_error();
		}
		$venue_id = (int) $hold['venue_term_id'];
		$result   = $this->with_booking_locks(
			$venue_id,
			(string) $hold['space_key'],
			function () use ( $hold_id, $expected_version, $actor_id, $reason, $venue_id ) {
				$started = $this->begin_authorized( $venue_id, $actor_id );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$hold = $this->raw_get( $hold_id );
				if ( is_wp_error( $hold ) || ! is_array( $hold ) ) {
					return $this->rollback( is_wp_error( $hold ) ? $hold : new \WP_Error( 'booking_hold_not_found', __( 'The booking hold was not found.', 'extrachill-events' ) ) );
				}
				$elapsed = $this->hold_is_elapsed( $hold_id );
				if ( is_wp_error( $elapsed ) ) {
					return $this->rollback( $elapsed );
				}
				if ( 'active' !== $hold['status'] || $elapsed ) {
					return $this->rollback( new \WP_Error( 'booking_hold_reconcile_after_release_lock', 'internal' ) );
				}
				if ( (int) $hold['version'] !== $expected_version ) {
					return $this->rollback(
						new \WP_Error(
							'booking_hold_version_conflict',
							__( 'The booking hold changed since it was read.', 'extrachill-events' ),
							array(
								'status'          => 409,
								'current_version' => $hold['version'],
							)
						)
					);
				}
				$booking = $this->bookings->get( $hold['booking_id'] );
				if ( ! is_array( $booking ) ) {
					return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
				}
				if ( 'held' === $booking['status'] && $this->hold_matches_booking( $hold, $booking ) ) {
					return $this->rollback( new \WP_Error( 'booking_held_hold_release_forbidden', __( 'Transition the held booking before releasing its selected hold.', 'extrachill-events' ), array( 'status' => 409 ) ) );
				}
				$released = $this->release_hold( $hold_id, $expected_version, $actor_id, $reason );
				if ( is_wp_error( $released ) ) {
					return $this->rollback( $released );
				}
				if ( ! $released ) {
					$latest = $this->raw_get( $hold_id );
					if ( is_wp_error( $latest ) || ! is_array( $latest ) ) {
						return $this->rollback( is_wp_error( $latest ) ? $latest : new \WP_Error( 'booking_hold_not_found', __( 'The booking hold was not found.', 'extrachill-events' ) ) );
					}
					if ( (int) $latest['version'] !== $expected_version ) {
						return $this->rollback(
							new \WP_Error(
								'booking_hold_version_conflict',
								__( 'The booking hold changed since it was read.', 'extrachill-events' ),
								array(
									'status'          => 409,
									'current_version' => $latest['version'],
								)
							)
						);
					}
					$elapsed = $this->hold_is_elapsed( $hold_id );
					if ( is_wp_error( $elapsed ) ) {
						return $this->rollback( $elapsed );
					}
					if ( 'active' !== $latest['status'] || $elapsed ) {
						return $this->rollback( new \WP_Error( 'booking_hold_reconcile_after_release_lock', 'internal' ) );
					}
					return $this->rollback( new \WP_Error( 'booking_hold_update_failed', __( 'The booking hold could not be released.', 'extrachill-events' ) ) );
				}
				$event = $this->activity->append(
					array(
						'booking_id' => $hold['booking_id'],
						'kind'       => 'hold_released',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => array(
							'hold_id' => $hold_id,
							'reason'  => $reason,
						),
					)
				);
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$output    = $this->hydrate(
					array_merge(
						$hold,
						array(
							'status'              => 'released',
							'version'             => $expected_version + 1,
							'updated_at'          => gmdate( 'Y-m-d H:i:s' ),
							'released_at'         => gmdate( 'Y-m-d H:i:s' ),
							'released_by_user_id' => $actor_id,
							'release_reason'      => $reason,
						)
					)
				);
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $output;
			}
		);
		if ( is_wp_error( $result ) && 'booking_hold_reconcile_after_release_lock' === $result->get_error_code() ) {
			$reconciled = $this->reconcile_hold_booking( $hold );
			return is_wp_error( $reconciled ) ? $reconciled : $this->hold_not_active_error();
		}
		return $result;
	}

	/** Get one stable hold record. */
	public function get( int $hold_id ) {
		$hold = $this->raw_get( $hold_id );
		return is_array( $hold ) ? $this->hydrate( $hold ) : $hold;
	}

	/** Read every converted hold matching the booking's authoritative selection. */
	public function converted_for_booking( array $booking ) {
		global $wpdb;
		$table = BookingSchema::holds_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d AND venue_term_id = %d AND space_key = %s AND start_at = %s AND end_at = %s AND status = 'converted' AND converted_at IS NOT NULL ORDER BY id ASC", $booking['id'], $booking['venue_term_id'], $booking['space_key'], $booking['performance_start_at'], $booking['performance_end_at'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Focused exact converted-selection read.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_converted_hold_read_failed', __( 'The converted booking hold could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return array_map( array( $this, 'hydrate' ), (array) $rows );
	}

	/** Read one stored hold without applying its effective elapsed status. */
	private function raw_get( int $hold_id ) {
		global $wpdb;
		$table = BookingSchema::holds_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT *, UTC_TIMESTAMP() AS database_now FROM {$table} WHERE id = %d LIMIT 1", $hold_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Current-prefix private table with authoritative clock.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_read_failed', __( 'The booking hold could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row, false ) : null;
	}

	/** List bounded holds after transaction-time exact venue reauthorization. */
	public function list( array $filters, int $actor_id ) {
		global $wpdb;
		$venue_id = absint( $filters['venue_term_id'] ?? 0 );
		if ( $venue_id < 1 ) {
			return new \WP_Error( 'invalid_booking_hold_venue', __( 'A venue is required.', 'extrachill-events' ) );
		}
		$where  = array( 'venue_term_id = %d' );
		$values = array( $venue_id );
		if ( ! empty( $filters['booking_id'] ) ) {
			$where[]  = 'booking_id = %d';
			$values[] = absint( $filters['booking_id'] );
		}
		$status = $filters['status'] ?? null;
		if ( ! empty( $status ) ) {
			if ( ! in_array( $filters['status'], self::STATUSES, true ) ) {
				return new \WP_Error( 'invalid_booking_hold_status', __( 'The booking hold status is invalid.', 'extrachill-events' ) );
			}
		}
		foreach ( array(
			'range_start' => 'end_at > %s',
			'range_end'   => 'start_at < %s',
		) as $field => $clause ) {
			if ( ! empty( $filters[ $field ] ) ) {
				if ( ! $this->valid_datetime( $filters[ $field ] ) ) {
					return new \WP_Error( 'invalid_booking_hold_datetime', __( 'Hold range filters must use UTC Y-m-d H:i:s format.', 'extrachill-events' ), array( 'field' => $field ) );
				}
				$where[]  = $clause;
				$values[] = $filters[ $field ];
			}
		}
		$limit   = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) );
		$offset  = max( 0, min( 10000, absint( $filters['offset'] ?? 0 ) ) );
		$started = $this->begin_venue_authorized( $venue_id, $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		if ( 'active' === $status ) {
			$where[] = "status = 'active' AND expires_at > UTC_TIMESTAMP()";
		} elseif ( 'expired' === $status ) {
			$where[] = "(status = 'expired' OR (status = 'active' AND expires_at <= UTC_TIMESTAMP()))";
		} elseif ( ! empty( $status ) ) {
			$where[]  = 'status = %s';
			$values[] = $status;
		}
		$values[] = $limit;
		$values[] = $offset;
		$table    = BookingSchema::holds_table();
		$sql      = "SELECT *, UTC_TIMESTAMP() AS database_now FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses are internal.
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values prepared.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_hold_list_failed', __( 'Booking holds could not be listed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		$output    = array_map( array( $this, 'hydrate' ), (array) $rows );
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $output;
	}

	/** Expire an elapsed active hold idempotently under its venue-space lock. */
	public function expire( int $hold_id ) {
		$hold = $this->raw_get( $hold_id );
		if ( ! is_array( $hold ) || 'active' !== $hold['status'] ) {
			return is_array( $hold ) ? $this->hydrate( $hold ) : $hold;
		}
		$elapsed = $this->hold_is_elapsed( $hold_id );
		if ( is_wp_error( $elapsed ) || ! $elapsed ) {
			return is_wp_error( $elapsed ) ? $elapsed : $this->hydrate( $hold );
		}
		return $this->with_booking_locks(
			(int) $hold['venue_term_id'],
			(string) $hold['space_key'],
			function () use ( $hold_id ) {
				$hold = $this->raw_get( $hold_id );
				if ( ! is_array( $hold ) || 'active' !== $hold['status'] ) {
					return is_array( $hold ) ? $this->hydrate( $hold ) : $hold;
				}
				$elapsed = $this->hold_is_elapsed( $hold_id );
				if ( is_wp_error( $elapsed ) || ! $elapsed ) {
					return is_wp_error( $elapsed ) ? $elapsed : $this->hydrate( $hold );
				}
				global $wpdb;
				$started = $this->begin();
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$hold = $this->raw_get( $hold_id );
				if ( ! is_array( $hold ) || 'active' !== $hold['status'] ) {
					$committed = $this->commit();
					return is_wp_error( $committed ) ? $committed : ( is_array( $hold ) ? $this->hydrate( $hold ) : $hold );
				}
				$elapsed = $this->hold_is_elapsed( $hold_id );
				if ( is_wp_error( $elapsed ) ) {
					return $this->rollback( $elapsed );
				}
				if ( ! $elapsed ) {
					$committed = $this->commit();
					return is_wp_error( $committed ) ? $committed : $this->hydrate( $hold );
				}
				$booking = $this->bookings->get( $hold['booking_id'] );
				if ( ! is_array( $booking ) ) {
					return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
				}
				$now    = gmdate( 'Y-m-d H:i:s' );
				$result = $this->update_hold( $hold_id, $hold['version'], 'expired', array( 'expired_at' => gmdate( 'Y-m-d H:i:s' ) ) );
				if ( is_wp_error( $result ) ) {
					return $this->rollback( $result );
				}
				$event = $this->activity->append(
					array(
						'booking_id' => $hold['booking_id'],
						'kind'       => 'hold_expired',
						'payload'    => array( 'hold_id' => $hold_id ),
					)
				);
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$other_hold = false;
				if ( 'held' === $booking['status'] && $this->hold_matches_booking( $hold, $booking ) ) {
					$other_hold = $this->has_other_matching_active_hold( $booking, $hold_id );
					if ( is_wp_error( $other_hold ) ) {
						return $this->rollback( $other_hold );
					}
				}
				if ( 'held' === $booking['status'] && $this->hold_matches_booking( $hold, $booking ) && ! $other_hold ) {
					$table   = BookingSchema::bookings_table();
					$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'negotiating', version = version + 1, updated_at = %s WHERE id = %d AND version = %d AND status = 'held'", $now, $booking['id'], $booking['version'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Expiry preserves the held-state invariant optimistically.
					if ( 1 !== $changed ) {
						return $this->rollback( false === $changed ? new \WP_Error( 'booking_hold_expiry_booking_update_failed', __( 'The held booking could not be reopened after expiry.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : $this->booking_version_conflict( $this->bookings->get( $booking['id'] ) ) );
					}
					$status_event = $this->activity->append(
						array(
							'booking_id' => $booking['id'],
							'kind'       => 'status_changed',
							'actor_type' => 'system',
							'payload'    => array(
								'from_status' => 'held',
								'to_status'   => 'negotiating',
								'reason'      => 'selected_hold_expired',
								'hold_id'     => $hold_id,
								'version'     => $booking['version'] + 1,
							),
						)
					);
					if ( is_wp_error( $status_event ) ) {
						return $this->rollback( $status_event );
					}
				}
				$output    = $this->hydrate(
					array_merge(
						$hold,
						array(
							'status'     => 'expired',
							'version'    => $hold['version'] + 1,
							'updated_at' => $now,
							'expired_at' => $now,
						)
					)
				);
				$committed = $this->commit();
				if ( is_wp_error( $committed ) ) {
					return $committed;
				}
				return $output;
			}
		);
	}

	/** Apply a hold-aware lifecycle transition as one aggregate mutation. */
	public function transition( array $booking, string $to_status, int $expected_version, int $actor_id, ?string $note ) {
		$hold = $this->matching_active_hold( $booking );
		if ( is_wp_error( $hold ) ) {
			return $hold;
		}
		if ( in_array( $to_status, array( 'held', 'confirmed' ), true ) && ! is_array( $hold ) ) {
			return new \WP_Error( 'booking_matching_hold_required', __( 'This transition requires an exact active unexpired hold.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$space_key = is_array( $hold ) ? (string) $hold['space_key'] : (string) $booking['space_key'];
		return $this->with_booking_locks(
			(int) $booking['venue_term_id'],
			$space_key,
			function () use ( $booking, $to_status, $expected_version, $actor_id, $note ) {
				$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
				if ( is_wp_error( $started ) ) {
					return $started;
				}
				$current = $this->bookings->get( $booking['id'] );
				if ( ! is_array( $current ) || (int) $current['version'] !== $expected_version ) {
					return $this->rollback( $this->booking_version_conflict( $current ) );
				}
				$hold = $this->matching_active_hold( $current );
				if ( in_array( $to_status, array( 'held', 'confirmed' ), true ) && ! is_array( $hold ) ) {
					return $this->rollback( new \WP_Error( 'booking_matching_hold_required', __( 'This transition requires an exact active unexpired hold.', 'extrachill-events' ), array( 'status' => 409 ) ) );
				}
				if ( 'confirmed' === $to_status ) {
					if ( empty( $current['deal']['data'] ) ) {
						return $this->rollback( new \WP_Error( 'booking_confirmation_deal_required', __( 'Confirmation requires deal terms.', 'extrachill-events' ), array( 'status' => 409 ) ) );
					}
					$normalized_deal = BookingMutationService::normalize_deal_document( $current['deal']['data'] );
					if ( is_wp_error( $normalized_deal ) || ! BookingMutationService::documents_equal( $normalized_deal, $current['deal']['data'] ) ) {
						return $this->rollback( new \WP_Error( 'booking_confirmation_deal_invalid', __( 'Confirmation requires a normalized complete draft deal.', 'extrachill-events' ), array( 'status' => 409 ) ) );
					}
					$conflict = $this->find_conflict( $current, $hold['id'] );
					if ( is_wp_error( $conflict ) ) {
						return $this->rollback( $conflict );
					}
					if ( $conflict ) {
						return $this->rollback( $this->conflict_error( $conflict ) );
					}
				}
				global $wpdb;
				$table = BookingSchema::bookings_table();
				$now   = gmdate( 'Y-m-d H:i:s' );
				if ( 'confirmed' === $to_status ) {
					$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, confirmed_deal_payload = deal_payload, version = version + 1, updated_at = %s WHERE id = %d AND version = %d AND confirmed_deal_payload IS NULL", $to_status, $now, $current['id'], $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Freezes the exact stored draft while transitioning.
				} else {
					$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, version = version + 1, updated_at = %s WHERE id = %d AND version = %d", $to_status, $now, $current['id'], $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transition.
				}
				if ( 1 !== $result ) {
					return $this->rollback( false === $result ? new \WP_Error( 'booking_update_failed', __( 'The booking could not be updated.', 'extrachill-events' ) ) : $this->booking_version_conflict( $this->bookings->get( $current['id'] ) ) );
				}
				if ( 'confirmed' === $to_status ) {
					$converted = $this->convert_hold(
						$hold['id'],
						$hold['version'],
						'converted',
						array(
							'converted_at'         => $now,
							'converted_by_user_id' => $actor_id,
						)
					);
					if ( is_wp_error( $converted ) ) {
						return $this->rollback( $converted );
					}
					$released = $this->release_booking_holds( $current['id'], $hold['id'], $actor_id, 'alternative_released_on_confirmation' );
				} elseif ( 'held' === $current['status'] && in_array( $to_status, array( 'negotiating', 'declined', 'withdrawn', 'cancelled' ), true ) ) {
					$released = $this->release_booking_holds( $current['id'], 0, $actor_id, 'released_on_status_change' );
				} else {
					$released = true;
				}
				if ( is_wp_error( $released ) ) {
					return $this->rollback( $released );
				}
				$event = $this->activity->append(
					array(
						'booking_id' => $current['id'],
						'kind'       => 'confirmed' === $to_status ? 'deal_confirmed' : 'status_changed',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => array(
							'from_status'    => $current['status'],
							'to_status'      => $to_status,
							'note'           => $note,
							'hold_id'        => is_array( $hold ) ? $hold['id'] : null,
							'confirmed_deal' => 'confirmed' === $to_status ? $current['deal'] : null,
							'version'        => $expected_version + 1,
						),
					)
				);
				if ( is_wp_error( $event ) ) {
					return $this->rollback( $event );
				}
				$output    = array_merge(
					$current,
					array(
						'status'         => $to_status,
						'version'        => $expected_version + 1,
						'updated_at'     => $now,
						'confirmed_deal' => 'confirmed' === $to_status ? $current['deal'] : $current['confirmed_deal'],
					)
				);
				$committed = $this->commit();
				return is_wp_error( $committed ) ? $committed : $output;
			}
		);
	}

	/** Return an exact active hold; elapsed rows are opportunistically expired and never block. */
	private function matching_active_hold( array $booking ) {
		global $wpdb;
		$table = BookingSchema::holds_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT *, UTC_TIMESTAMP() AS database_now FROM {$table} WHERE booking_id = %d AND venue_term_id = %d AND space_key = %s AND start_at = %s AND end_at = %s AND status = 'active' AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1", $booking['id'], $booking['venue_term_id'], $booking['space_key'], $booking['performance_start_at'], $booking['performance_end_at'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact persisted selection by database time.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_read_failed', __( 'The booking hold could not be read.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** Detect hold, confirmed-booking, then canonical published-event conflicts. */
	private function find_conflict( array $booking, int $excluded_hold_id ) {
		global $wpdb;
		$holds = BookingSchema::holds_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, booking_id, 'hold' AS conflict_type FROM {$holds} WHERE venue_term_id = %d AND space_key = %s AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND start_at < %s AND end_at > %s AND booking_id <> %d AND id <> %d LIMIT 1", $booking['venue_term_id'], $booking['space_key'], $booking['performance_end_at'], $booking['performance_start_at'], $booking['id'], $excluded_hold_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialized database-time overlap check.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_conflict_check_failed', __( 'Booking conflicts could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( is_array( $row ) ) {
			return $row;
		}
		$bookings = BookingSchema::bookings_table();
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT id, 'confirmed_booking' AS conflict_type FROM {$bookings} WHERE venue_term_id = %d AND space_key = %s AND status = 'confirmed' AND performance_start_at < %s AND performance_end_at > %s AND id <> %d LIMIT 1", $booking['venue_term_id'], $booking['space_key'], $booking['performance_end_at'], $booking['performance_start_at'], $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serialized overlap check.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_conflict_check_failed', __( 'Booking conflicts could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( is_array( $row ) ) {
			return $row;
		}
		$timezone_name = get_term_meta( $booking['venue_term_id'], '_venue_timezone', true );
		try {
			$timezone = new \DateTimeZone( (string) $timezone_name );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'booking_venue_timezone_invalid', __( 'The venue timezone is missing or invalid, so conflicts cannot be checked safely.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$window    = $this->canonical_local_window( $booking['performance_start_at'], $booking['performance_end_at'], $timezone );
		$start     = $window['start'];
		$end       = $window['end'];
		$dates     = $wpdb->prefix . 'datamachine_event_dates';
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		// Canonical events have no space identity, so a published venue event safely blocks every configured space.
		$sql = "SELECT p.ID AS id, 'canonical_event' AS conflict_type FROM {$wpdb->posts} p JOIN {$dates} ed ON p.ID = ed.post_id JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id WHERE p.post_type = %s AND p.post_status = 'publish' AND tt.taxonomy = 'venue' AND tt.term_id = %d AND p.ID <> %d AND ed.start_datetime < %s AND ((ed.end_datetime IS NOT NULL AND ed.end_datetime > %s) OR (ed.end_datetime IS NULL AND ed.start_datetime >= %s)) LIMIT 1"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core/current-prefix tables only.
		$row = $wpdb->get_row( $wpdb->prepare( $sql, $post_type, $booking['venue_term_id'], (int) $booking['event_id'], $end, $start, $start ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Values prepared.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_conflict_check_failed', __( 'Canonical event conflicts could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $row : null;
	}

	private function conflict_error( array $conflict ): \WP_Error {
		return new \WP_Error(
			'booking_time_conflict',
			__( 'The selected venue time is unavailable.', 'extrachill-events' ),
			array(
				'status'                       => 409,
				'conflict'                     => $conflict,
				'canonical_event_space_policy' => 'venue_wide',
			)
		);
	}

	private function release_booking_holds( int $booking_id, int $except_id, int $actor_id, string $reason ) {
		global $wpdb;
		$table   = BookingSchema::holds_table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$expired = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'expired', version = version + 1, updated_at = %s, expired_at = %s WHERE booking_id = %d AND status = 'active' AND expires_at <= UTC_TIMESTAMP() AND id <> %d", $now, $now, $booking_id, $except_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Preserve database-time elapsed alternatives as expired.
		if ( false === $expired ) {
			return new \WP_Error( 'booking_hold_expiry_failed', __( 'Elapsed alternative holds could not be expired.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', version = version + 1, updated_at = %s, released_at = %s, released_by_user_id = %d, release_reason = %s WHERE booking_id = %d AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND id <> %d", $now, $now, $actor_id, $reason, $booking_id, $except_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate database-time live alternative release.
		return false === $result ? new \WP_Error( 'booking_hold_release_failed', __( 'Alternative booking holds could not be released.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : true;
	}

	private function update_hold( int $hold_id, int $expected_version, string $status, array $fields ) {
		global $wpdb;
		$set    = array( 'status = %s', 'version = version + 1', 'updated_at = %s' );
		$values = array( $status, gmdate( 'Y-m-d H:i:s' ) );
		foreach ( $fields as $field => $value ) {
			$set[] = null === $value ? "{$field} = NULL" : ( is_int( $value ) ? "{$field} = %d" : "{$field} = %s" );
			if ( null !== $value ) {
				$values[] = $value;
			}
		}
		$values[] = $hold_id;
		$values[] = $expected_version;
		$table    = BookingSchema::holds_table();
		$result   = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND version = %d', $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dynamic internal fields determine the prepared value count.
		if ( false === $result ) {
			return new \WP_Error( 'booking_hold_update_failed', __( 'The booking hold could not be updated.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return 1 === $result ? true : new \WP_Error( 'booking_hold_version_conflict', __( 'The booking hold changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Convert only a lock-current active hold that remains unexpired at database time. */
	private function convert_hold( int $hold_id, int $expected_version, string $status, array $fields ) {
		global $wpdb;
		$set    = array( 'status = %s', 'version = version + 1', 'updated_at = %s' );
		$values = array( $status, gmdate( 'Y-m-d H:i:s' ) );
		foreach ( $fields as $field => $value ) {
			$set[]    = is_int( $value ) ? "{$field} = %d" : "{$field} = %s";
			$values[] = $value;
		}
		$values[] = $hold_id;
		$values[] = $expected_version;
		$table    = BookingSchema::holds_table();
		$result   = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $set ) . " WHERE id = %d AND version = %d AND status = 'active' AND expires_at > UTC_TIMESTAMP()", $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The conversion boundary is enforced by database time.
		if ( false === $result ) {
			return new \WP_Error( 'booking_hold_update_failed', __( 'The booking hold could not be converted.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( 1 === $result ) {
			return true;
		}
		$latest = $this->raw_get( $hold_id );
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		if ( is_array( $latest ) && (int) $latest['version'] === $expected_version && 'active' === $latest['status'] ) {
			return new \WP_Error( 'booking_hold_expired_during_confirmation', __( 'The booking hold expired before confirmation completed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return new \WP_Error( 'booking_hold_version_conflict', __( 'The booking hold changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Conditionally release only a still-active, still-unexpired hold. */
	private function release_hold( int $hold_id, int $expected_version, int $actor_id, string $reason ) {
		global $wpdb;
		$table  = BookingSchema::holds_table();
		$now    = gmdate( 'Y-m-d H:i:s' );
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'released', version = version + 1, updated_at = %s, released_at = %s, released_by_user_id = %d, release_reason = %s WHERE id = %d AND version = %d AND status = 'active' AND expires_at > UTC_TIMESTAMP()", $now, $now, $actor_id, $reason, $hold_id, $expected_version ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- DB-side time closes the release-at-expiry race.
		if ( false === $result ) {
			return new \WP_Error( 'booking_hold_update_failed', __( 'The booking hold could not be released.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return 1 === $result;
	}

	private function begin_authorized( int $venue_id, int $actor_id ) {
		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		return $this->lock_and_authorize_venue( $venue_id, $actor_id );
	}

	private function begin_venue_authorized( int $venue_id, int $actor_id ) {
		$started = $this->begin();
		return is_wp_error( $started ) ? $started : $this->lock_and_authorize_venue( $venue_id, $actor_id );
	}

	private function lock_and_authorize_venue( int $venue_id, int $actor_id ) {
		global $wpdb;
		$table  = BookingSchema::memberships_table();
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- First transactional read locks and returns current venue authority.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_hold_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		$allowed = $this->authorization->authorize_locked( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE, (array) $locked );
		return true === $allowed ? true : $this->rollback( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	private function with_lock( string $name, callable $callback ) {
		global $wpdb;
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Required cross-connection advisory lock.
		if ( 1 !== (int) $acquired || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'booking_hold_lock_not_acquired',
				__( 'The venue-space booking lock could not be acquired.', 'extrachill-events' ),
				array(
					'status'         => 503,
					'database_error' => $wpdb->last_error,
				)
			);
		}
		$result = null;
		try {
			$result = $callback();
		} catch ( \Throwable $throwable ) {
			$error  = new \WP_Error( 'booking_hold_operation_failed', __( 'The booking hold operation failed unexpectedly.', 'extrachill-events' ), array( 'exception' => get_class( $throwable ) ) );
			$result = $this->transaction_active ? $this->rollback( $error ) : $error;
		} finally {
			$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Always release the acquired lock.
		}
		if ( 1 !== (int) $released || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error(
				'booking_hold_lock_release_failed',
				__( 'The venue-space booking lock release could not be confirmed.', 'extrachill-events' ),
				array(
					'database_error'   => $wpdb->last_error,
					'operation_result' => is_wp_error( $result ) ? $result->get_error_code() : 'completed',
				)
			);
		}
		return $result;
	}

	/** Acquire the stable venue domain before its narrower venue-space lock. */
	private function with_booking_locks( int $venue_id, string $space_key, callable $callback ) {
		return $this->with_lock(
			self::venue_lock_name( $venue_id ),
			function () use ( $venue_id, $space_key, $callback ) {
				return $this->with_lock( self::venue_space_lock_name( $venue_id, $space_key ), $callback );
			}
		);
	}

	/** Return the shared advisory-lock name for all booking writes at one venue. */
	public static function venue_lock_name( int $venue_id ): string {
		return 'ecbv:' . sha1( BookingSchema::holds_table() . ':' . $venue_id );
	}

	/** Return the shared advisory-lock name for one venue space. */
	public static function venue_space_lock_name( int $venue_id, string $space_key ): string {
		return 'ecbh:' . sha1( BookingSchema::holds_table() . ':' . $venue_id . ':' . $space_key );
	}

	private function selection( array $booking ) {
		if ( empty( $booking['space_key'] ) || empty( $booking['performance_start_at'] ) || empty( $booking['performance_end_at'] ) ) {
			return new \WP_Error( 'booking_hold_selection_required', __( 'A persisted booking space and date range are required.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $booking['performance_end_at'] <= $booking['performance_start_at'] ) {
			return new \WP_Error( 'invalid_booking_date_range', __( 'The performance end must be later than the performance start.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return true;
	}

	private function configured_space( array $config, string $space_key ): bool {
		foreach ( $config['spaces'] as $space ) {
			if ( $space_key === $space['key'] ) {
				return true;
			}
		}
		return false;
	}

	private function schedule( int $hold_id, string $expires_at ): void {
		try {
			if ( function_exists( 'as_schedule_single_action' ) ) {
				$scheduled = as_schedule_single_action( strtotime( $expires_at . ' UTC' ), self::EXPIRY_HOOK, array( $hold_id, 0 ), self::SCHEDULER_GROUP, true );
				if ( ! $scheduled ) {
					throw new \RuntimeException( 'Booking hold expiration was not scheduled.' );
				}
			}
		} catch ( \Throwable $throwable ) {
			if ( function_exists( 'do_action' ) ) {
				do_action( 'extrachill_events_booking_hold_schedule_failed', $hold_id, $expires_at, $throwable );
			}
		}
	}

	private function hydrate( array $row, bool $effective = true ): array {
		$database_now = $row['database_now'] ?? null;
		if ( $effective ) {
			unset( $row['database_now'] );
		}
		foreach ( array( 'id', 'booking_id', 'venue_term_id', 'version', 'created_by_user_id', 'released_by_user_id', 'converted_by_user_id' ) as $field ) {
			$row[ $field ] = isset( $row[ $field ] ) ? (int) $row[ $field ] : null;
		}
		if ( $effective && null !== $database_now && 'active' === $row['status'] && $row['expires_at'] <= $database_now ) {
			$row['status']     = 'expired';
			$row['expired_at'] = null === $row['expired_at'] ? $row['expires_at'] : $row['expired_at'];
		}
		return $row;
	}

	private function reconcile_hold_booking( array $hold ) {
		$expired = $this->expire( (int) $hold['id'] );
		if ( is_wp_error( $expired ) ) {
			return $expired;
		}
		$booking = $this->bookings->get( $hold['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		return $booking;
	}

	private function hold_not_active_error(): \WP_Error {
		return new \WP_Error( 'booking_hold_not_active', __( 'Only an active unexpired hold can be released.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	private function hold_matches_booking( array $hold, array $booking ): bool {
		return (int) $hold['booking_id'] === (int) $booking['id']
			&& (int) $hold['venue_term_id'] === (int) $booking['venue_term_id']
			&& $hold['space_key'] === $booking['space_key']
			&& $hold['start_at'] === $booking['performance_start_at']
			&& $hold['end_at'] === $booking['performance_end_at'];
	}

	private function has_other_matching_active_hold( array $booking, int $excluded_hold_id ) {
		global $wpdb;
		$table = BookingSchema::holds_table();
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE booking_id = %d AND venue_term_id = %d AND space_key = %s AND start_at = %s AND end_at = %s AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND id <> %d LIMIT 1", $booking['id'], $booking['venue_term_id'], $booking['space_key'], $booking['performance_start_at'], $booking['performance_end_at'], $excluded_hold_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact database-time invariant check under the venue-space lock.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_invariant_check_failed', __( 'Other active holds could not be checked safely.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return null !== $row;
	}

	/** Ask MySQL whether one active hold has reached its expiration boundary. */
	private function hold_is_elapsed( int $hold_id ) {
		global $wpdb;
		$table = BookingSchema::holds_table();
		$row   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d AND status = 'active' AND expires_at <= UTC_TIMESTAMP()", $hold_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Database time is authoritative for expiration.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_hold_expiration_check_failed', __( 'The booking hold expiration could not be checked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return null !== $row;
	}

	private function canonical_local_window( string $start, string $end, \DateTimeZone $timezone ): array {
		$utc         = new \DateTimeZone( 'UTC' );
		$start_utc   = new \DateTimeImmutable( $start, $utc );
		$end_utc     = new \DateTimeImmutable( $end, $utc );
		$start_local = $start_utc->setTimezone( $timezone );
		$end_local   = $end_utc->setTimezone( $timezone );
		$local_start = $start_local->format( 'Y-m-d H:i:s' );
		$local_end   = $end_local->format( 'Y-m-d H:i:s' );
		$transitions = $timezone->getTransitions( $start_utc->getTimestamp(), $end_utc->getTimestamp() );
		if ( is_array( $transitions ) ) {
			$previous_offset = null;
			foreach ( $transitions as $transition ) {
				if ( ! is_array( $transition ) || ! isset( $transition['ts'], $transition['offset'] ) ) {
					continue;
				}
				$new_offset = (int) $transition['offset'];
				if ( null !== $previous_offset && $new_offset < $previous_offset ) {
					// The canonical index lacks offsets. Include the entire repeated wall-clock fold conservatively.
					$fold_start  = gmdate( 'Y-m-d H:i:s', (int) $transition['ts'] + $new_offset );
					$fold_end    = gmdate( 'Y-m-d H:i:s', (int) $transition['ts'] + $previous_offset );
					$local_start = min( $local_start, $fold_start );
					$local_end   = max( $local_end, $fold_end );
				}
				$previous_offset = $new_offset;
			}
		}
		return array(
			'start' => $local_start,
			'end'   => $local_end,
		);
	}

	private function valid_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function booking_version_conflict( $booking ): \WP_Error {
		return new \WP_Error(
			'booking_version_conflict',
			__( 'The booking changed since it was read.', 'extrachill-events' ),
			array(
				'status'          => 409,
				'current_version' => is_array( $booking ) ? $booking['version'] : null,
			)
		);
	}

	private function begin() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction boundary.
			return new \WP_Error( 'booking_hold_transaction_start_failed', __( 'The booking hold transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$this->transaction_active = true;
		return true;
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_hold_transaction_commit_uncertain', __( 'The booking hold transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : true;
	}

	private function rollback( \WP_Error $cause ) {
		global $wpdb;
		if ( ! $this->transaction_active ) {
			return $cause;
		}
		if ( false === $wpdb->query( 'ROLLBACK' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Aggregate transaction.
			$this->transaction_active = false;
			return new \WP_Error(
				'booking_hold_transaction_rollback_failed',
				__( 'The booking hold transaction could not be rolled back.', 'extrachill-events' ),
				array(
					'cause'          => $cause->get_error_code(),
					'database_error' => $wpdb->last_error,
				)
			);
		}
		$this->transaction_active = false;
		return $cause;
	}
}
