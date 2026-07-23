<?php
/**
 * Serializes canonical event publication with venue booking writers.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Prevents venue-wide canonical events from racing booking holds or confirmations. */
class CanonicalEventPublicationGuard {

	private const DEFAULT_DURATION_SECONDS = 3 * HOUR_IN_SECONDS;

	/** @var array<int,string> */
	private $acquired_locks = array();
	/** @var string|null */
	private $active_context;
	/** @var int */
	private $active_post_id = 0;
	/** @var array|null */
	private $active_publication;
	/** @var string */
	private $active_lifecycle_key = '';
	/** @var bool */
	private $active_poisoned = false;
	/** @var bool */
	private $active_boundary_consumed = false;
	/** @var object|null Exact REST request which acquired the active lock. */
	private $active_rest_request;

	public function __construct() {
		add_filter( 'datamachine_events_before_event_upsert_persistence', array( $this, 'preflight_dme_persistence' ), 10, 2 );
		add_action( 'datamachine_events_after_event_upsert_persistence', array( $this, 'complete_dme_persistence' ), 10, 3 );
		add_action( 'datamachine_upsert_post_identity_before_population', array( $this, 'bind_dme_post_id' ), PHP_INT_MAX );
		add_filter( 'rest_pre_insert_data_machine_events', array( $this, 'preflight_rest_insert' ), PHP_INT_MAX, 2 );
		add_action( 'rest_after_insert_data_machine_events', array( $this, 'complete_rest_insert' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'complete_rest_request' ), 10, 3 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'preflight_direct_post_insert' ), PHP_INT_MAX, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'complete_post_insert' ), 10, 4 );
		add_filter( 'datamachine_events_before_event_update_persistence', array( $this, 'preflight_event_update_persistence' ), 10, 2 );
		add_action( 'datamachine_events_after_event_update_persistence', array( $this, 'complete_event_update_persistence' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'release' ) );
	}

	/** Preflight the generic DME persistence lifecycle after exact venue resolution. */
	public function preflight_dme_persistence( $preflight, array $context ) {
		if ( is_wp_error( $preflight ) || false === $preflight || 'publish' !== ( $context['post_status'] ?? '' ) ) {
			return $preflight;
		}
		if ( null !== $this->active_context ) {
			$error = new \WP_Error( 'canonical_event_publication_reentrant', __( 'Another canonical event publication is already active in this request.', 'extrachill-events' ), array( 'status' => 409 ) );
			$this->audit_denial( 'dme', $error );
			return $error;
		}

		$venue_id    = absint( $context['venue_term_id'] ?? 0 );
		$event       = is_array( $context['event'] ?? null ) ? $context['event'] : array();
		$publication = $venue_id > 0 ? $this->publication_window( $venue_id, $event ) : null;
		if ( null === $publication ) {
			return $preflight;
		}
		if ( is_wp_error( $publication ) ) {
			$this->audit_denial( 'dme', $publication );
			return $publication;
		}

		$result = $this->acquire_for_publication(
			$publication['venue_id'],
			$publication['start_at'],
			$publication['end_at'],
			(int) ( $context['existing_post_id'] ?? 0 ),
			(int) apply_filters( 'extrachill_events_canonical_event_excluded_booking_id', 0, $context, $publication ),
			$publication['_candidate_intervals']
		);
		if ( is_wp_error( $result ) ) {
			$this->audit_denial( 'dme', $result );
			return $result;
		}

		$this->active_context       = 'dme';
		$this->active_post_id       = (int) ( $context['existing_post_id'] ?? 0 );
		$this->active_publication   = $publication;
		$this->active_lifecycle_key = $this->lifecycle_key( $context );
		$this->active_poisoned      = false;
		$this->active_boundary_consumed = false;
		return $preflight;
	}

	/** Release only the DME lifecycle which acquired the active lock. */
	public function complete_dme_persistence( array $context, int $post_id, $result = null ): void {
		unset( $result );
		if ( 'dme' === $this->active_context && $this->lifecycle_key( $context ) === $this->active_lifecycle_key && ( 0 === $this->active_post_id || 0 === $post_id || $post_id === $this->active_post_id ) ) {
			$this->release();
		}
	}

	/** Bind the DME permission preflight to Data Machine's reserved identity post. */
	public function bind_dme_post_id( int $post_id ): void {
		if ( 'dme' !== $this->active_context ) {
			return;
		}
		if ( $post_id < 1 || ( $this->active_post_id > 0 && $post_id !== $this->active_post_id ) ) {
			$error = new \WP_Error( 'canonical_event_publication_identity_mismatch', __( 'Canonical event publication could not bind its reserved post identity.', 'extrachill-events' ), array( 'status' => 409, 'post_id' => $post_id ) );
			$this->active_poisoned = true;
			$this->audit_denial( 'dme', $error );
			return;
		}
		$this->active_post_id = $post_id;
	}

	/** Reject a conflicting REST/editor publication before WordPress persists it. */
	public function preflight_rest_insert( $prepared_post, $request ) {
		$post_id          = absint( $prepared_post->ID ?? 0 );
		$existing         = $post_id > 0 ? get_post( $post_id ) : null;
		$effective_status = (string) ( $prepared_post->post_status ?? ( $existing->post_status ?? '' ) );
		if ( 'publish' !== $effective_status ) {
			return $prepared_post;
		}
		if ( null !== $this->active_context ) {
			return new \WP_Error( 'canonical_event_publication_reentrant', __( 'Another canonical event publication is already active in this request.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$publication_post = $prepared_post;
		if ( $existing && ! isset( $publication_post->post_content ) ) {
			$publication_post               = clone $prepared_post;
			$publication_post->post_content = $existing->post_content;
		}
		$publication = $this->publication_from_post( $publication_post, $request, $post_id );
		if ( is_wp_error( $publication ) ) {
			$this->audit_denial( 'rest', $publication );
			return $publication;
		}
		if ( null === $publication ) {
			return $prepared_post;
		}

		$result = $this->acquire_for_publication( $publication['venue_id'], $publication['start_at'], $publication['end_at'], $post_id, 0, $publication['_candidate_intervals'] );
		if ( is_wp_error( $result ) ) {
			$this->audit_denial( 'rest', $result );
			return $result;
		}

		$this->active_context     = 'rest';
		$this->active_post_id     = $post_id;
		$this->active_publication = $publication;
		$this->active_boundary_consumed = false;
		$this->active_rest_request = is_object( $request ) ? $request : null;
		return $prepared_post;
	}

	/** Keep the REST locks through all controller persistence. */
	public function complete_rest_insert( $post, $request = null, $creating = null ): void {
		unset( $creating );
		if ( 'rest' === $this->active_context && $this->owns_rest_request( $request ) && ( 0 === $this->active_post_id || (int) ( $post->ID ?? 0 ) === $this->active_post_id ) ) {
			$this->release();
		}
	}

	/** Release a REST lock when the controller returns before rest_after_insert. */
	public function complete_rest_request( $response, $handler = null, $request = null ) {
		unset( $handler );
		if ( 'rest' === $this->active_context && $this->owns_rest_request( $request ) ) {
			$this->release();
		}
		return $response;
	}

	/** Abort an entire direct insert/update before post or taxonomy persistence. */
	public function preflight_direct_post_insert( bool $maybe_empty, array $postarr ): bool {
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( $maybe_empty ) {
			return true;
		}
		if ( $post_type !== ( $postarr['post_type'] ?? '' ) || 'publish' !== ( $postarr['post_status'] ?? '' ) ) {
			return $maybe_empty;
		}

		$post_id = absint( $postarr['ID'] ?? 0 );
		if ( 'dme' === $this->active_context ) {
			$matches_reserved = $post_id > 0 && $post_id === $this->active_post_id;
			$matches_sourceless_new = 0 === $post_id && 0 === $this->active_post_id;
			if ( ! $this->active_poisoned && ! $this->active_boundary_consumed && ( $matches_reserved || $matches_sourceless_new ) ) {
				$this->active_boundary_consumed = true;
				return false;
			}
			$error = new \WP_Error( 'canonical_event_publication_post_mismatch', __( 'This event write does not match the active canonical publication.', 'extrachill-events' ), array( 'status' => 409, 'post_id' => $post_id ) );
			$this->active_poisoned = true;
			$this->audit_denial( 'wp_insert_post_empty_content', $error );
			return true;
		}
		if ( 'event_update' === $this->active_context ) {
			if ( ! $this->active_poisoned && ! $this->active_boundary_consumed && $post_id > 0 && $post_id === $this->active_post_id ) {
				$this->active_boundary_consumed = true;
				return false;
			}
			$error = new \WP_Error( 'canonical_event_publication_post_mismatch', __( 'This event write does not match the active canonical publication.', 'extrachill-events' ), array( 'status' => 409, 'post_id' => $post_id ) );
			$this->active_poisoned = true;
			$this->audit_denial( 'wp_insert_post_empty_content', $error );
			return true;
		}
		if ( null !== $this->active_context ) {
			if ( 'rest' === $this->active_context && ! $this->active_boundary_consumed && ( 0 === $this->active_post_id || $post_id === $this->active_post_id ) ) {
				$this->active_boundary_consumed = true;
				return false;
			}
			return true;
		}

		$publication = $this->publication_from_post( (object) $postarr, $postarr, $post_id );
		$result      = null === $publication || is_wp_error( $publication )
			? $publication
			: $this->acquire_for_publication( $publication['venue_id'], $publication['start_at'], $publication['end_at'], $post_id, 0, $publication['_candidate_intervals'] );

		if ( is_wp_error( $result ) ) {
			$this->audit_denial( 'wp_insert_post_empty_content', $result );
			return true;
		}
		if ( true === $result ) {
			$this->active_context     = 'post';
			$this->active_post_id     = $post_id;
			$this->active_publication = $publication;
			$this->active_boundary_consumed = true;
		}
		return false;
	}

	/** Release direct-write locks after save_post/date synchronization. */
	public function complete_post_insert( int $post_id, $post = null, bool $update = false, $post_before = null ): void {
		unset( $update, $post_before );
		$post      = is_object( $post ) ? $post : get_post( $post_id );
		$post_type = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( 'post' === $this->active_context && $post && $post_type === ( $post->post_type ?? '' ) && ( 0 === $this->active_post_id || $post_id === $this->active_post_id ) ) {
			$this->active_post_id = $post_id;
			$this->release();
		}
	}

	/** Preflight one complete DME event update after all proposed values are known. */
	public function preflight_event_update_persistence( $preflight, array $context ) {
		if ( is_wp_error( $preflight ) || false === $preflight || 'publish' !== ( $context['post_status'] ?? '' ) ) {
			return $preflight;
		}
		$post_id  = absint( $context['post_id'] ?? 0 );
		$venue_id = absint( $context['next_venue_id'] ?? 0 );
		$previous_venue_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $context['previous_venue_ids'] ?? array() ) ) ) ) );
		if ( $post_id < 1 ) {
			return $preflight;
		}
		if ( $venue_id < 1 ) {
			if ( count( $previous_venue_ids ) > 1 ) {
				$error = new \WP_Error( 'canonical_event_venue_ambiguous', __( 'The published event has multiple retained venue assignments and cannot be updated safely.', 'extrachill-events' ), array( 'status' => 409, 'venue_ids' => $previous_venue_ids ) );
				$this->audit_denial( 'event_update', $error );
				return $error;
			}
			return $preflight;
		}
		if ( null !== $this->active_context ) {
			return new \WP_Error( 'canonical_event_publication_reentrant', __( 'Another canonical event publication is already active in this request.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$event       = is_array( $context['event'] ?? null ) ? $context['event'] : array();
		$publication = $this->publication_window( $venue_id, $event );
		if ( is_wp_error( $publication ) ) {
			$this->audit_denial( 'event_update', $publication );
			return $publication;
		}
		$result = $this->acquire_for_publication( $publication['venue_id'], $publication['start_at'], $publication['end_at'], $post_id, 0, $publication['_candidate_intervals'] );
		if ( is_wp_error( $result ) ) {
			$this->audit_denial( 'event_update', $result );
			return $result;
		}
		$this->active_context       = 'event_update';
		$this->active_post_id       = $post_id;
		$this->active_publication   = $publication;
		$this->active_lifecycle_key = $this->lifecycle_key( $context );
		$this->active_poisoned      = false;
		$this->active_boundary_consumed = false;
		return $preflight;
	}

	/** Release only the complete event update invocation which acquired the lock. */
	public function complete_event_update_persistence( array $context, $result = null ): void {
		unset( $result );
		if ( 'event_update' === $this->active_context && (int) ( $context['post_id'] ?? 0 ) === $this->active_post_id && $this->lifecycle_key( $context ) === $this->active_lifecycle_key ) {
			$this->release();
		}
	}

	/** Acquire the stable venue lock and reject booking overlaps. */
	public function acquire_for_publication( int $venue_id, string $start_at, string $end_at, int $post_id = 0, int $excluded_booking_id = 0, array $candidate_intervals = array() ) {
		global $wpdb;
		$name     = BookingHoldRepository::venue_lock_name( $venue_id );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 5 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-connection venue booking serialization.
		if ( 1 !== (int) $acquired || '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'canonical_event_booking_lock_not_acquired', __( 'Venue booking availability is busy; retry publication.', 'extrachill-events' ), array( 'status' => 503, 'database_error' => $wpdb->last_error ) );
		}
		$this->acquired_locks[] = $name;

		$candidate_intervals = empty( $candidate_intervals ) ? array( array( 'start_at' => $start_at, 'end_at' => $end_at ) ) : $candidate_intervals;
		$conflict = $this->find_booking_conflict( $venue_id, $start_at, $end_at, $post_id, $excluded_booking_id, $candidate_intervals );
		if ( is_wp_error( $conflict ) ) {
			$this->release();
			return $conflict;
		}
		if ( $conflict ) {
			$this->release();
			return new \WP_Error(
				'canonical_event_booking_conflict',
				__( 'This venue already has an overlapping booking hold or confirmed booking.', 'extrachill-events' ),
				array(
					'status'                       => 409,
					'conflict'                     => $conflict,
					'canonical_event_space_policy' => 'venue_wide',
					'post_id'                      => $post_id,
				)
			);
		}

		return true;
	}

	/** Release all acquired locks in reverse global acquisition order. */
	public function release(): void {
		global $wpdb;
		foreach ( array_reverse( $this->acquired_locks ) as $name ) {
			$released       = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only locks acquired by this request.
			$database_error = (string) $wpdb->last_error;
			if ( 1 === (int) $released && '' === $database_error ) {
				continue;
			}
			do_action( 'extrachill_events_canonical_event_booking_lock_release_failed', $name, $database_error );
			do_action(
				'datamachine_log',
				'error',
				'Canonical event booking lock release could not be confirmed',
				array(
					'lock_name'      => $name,
					'database_error' => $database_error,
				)
			);
		}
		$this->acquired_locks = array();
		$this->active_context = null;
		$this->active_post_id = 0;
		$this->active_publication = null;
		$this->active_lifecycle_key = '';
		$this->active_poisoned = false;
		$this->active_boundary_consumed = false;
		$this->active_rest_request = null;
	}

	/** Match only the REST request object which acquired the active lock. */
	private function owns_rest_request( $request ): bool {
		return is_object( $request ) && is_object( $this->active_rest_request ) && $request === $this->active_rest_request;
	}

	private function find_booking_conflict( int $venue_id, string $start_at, string $end_at, int $post_id, int $excluded_booking_id, array $candidate_intervals ) {
		global $wpdb;
		$hold_exact       = array();
		$booking_exact    = array();
		$exact_values     = array();
		foreach ( $candidate_intervals as $interval ) {
			$hold_exact[]    = '(start_at = %s AND end_at = %s)';
			$booking_exact[] = '(performance_start_at = %s AND performance_end_at = %s)';
			$exact_values[]  = (string) $interval['start_at'];
			$exact_values[]  = (string) $interval['end_at'];
		}
		$hold_exact_sql    = implode( ' OR ', $hold_exact );
		$booking_exact_sql = implode( ' OR ', $booking_exact );
		$bookings          = BookingSchema::bookings_table();
		if ( $post_id > 0 ) {
			$linked = $wpdb->get_row( $wpdb->prepare( "SELECT id, venue_term_id, space_key, performance_start_at, performance_end_at, 'confirmed_booking' AS conflict_type FROM {$bookings} WHERE event_id = %d AND status = 'confirmed' LIMIT 1", $post_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A linked booking may not be silently detached by moving its event.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error( 'canonical_event_booking_conflict_check_failed', __( 'The event-linked booking could not be checked safely.', 'extrachill-events' ), array( 'status' => 503, 'database_error' => $wpdb->last_error ) );
			}
			if ( is_array( $linked ) ) {
				$exact = (int) $linked['venue_term_id'] === $venue_id;
				foreach ( $candidate_intervals as $interval ) {
					if ( $exact && $linked['performance_start_at'] === $interval['start_at'] && $linked['performance_end_at'] === $interval['end_at'] ) {
						$linked = null;
						break;
					}
				}
				if ( is_array( $linked ) ) {
					return $linked;
				}
			}
		}
		$holds = BookingSchema::holds_table();
		$hold_values = array_merge( array( $venue_id, $end_at, $start_at, $excluded_booking_id ), $exact_values );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, booking_id, space_key, 'hold' AS conflict_type FROM {$holds} WHERE venue_term_id = %d AND status = 'active' AND expires_at > UTC_TIMESTAMP() AND start_at < %s AND end_at > %s AND NOT (booking_id = %d AND ({$hold_exact_sql})) LIMIT 1", $hold_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Checked while the stable venue-wide lock is held.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'canonical_event_booking_conflict_check_failed', __( 'Venue booking holds could not be checked safely.', 'extrachill-events' ), array( 'status' => 503, 'database_error' => $wpdb->last_error ) );
		}
		if ( is_array( $row ) ) {
			return $row;
		}

		$booking_values = array_merge( array( $venue_id, $end_at, $start_at, $excluded_booking_id, $post_id ), $exact_values );
		$row      = $wpdb->get_row( $wpdb->prepare( "SELECT id, space_key, 'confirmed_booking' AS conflict_type FROM {$bookings} WHERE venue_term_id = %d AND status = 'confirmed' AND performance_start_at < %s AND performance_end_at > %s AND NOT ((id = %d OR event_id = %d) AND ({$booking_exact_sql})) LIMIT 1", $booking_values ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Only an exact originating booking is exempt from the venue-wide policy.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'canonical_event_booking_conflict_check_failed', __( 'Confirmed venue bookings could not be checked safely.', 'extrachill-events' ), array( 'status' => 503, 'database_error' => $wpdb->last_error ) );
		}
		return is_array( $row ) ? $row : null;
	}

	private function publication_from_post( $post, $request, int $post_id ) {
		$venue_id = $this->venue_id_from_request( $request );
		if ( $venue_id < 1 && $post_id > 0 ) {
			$venues   = wp_get_object_terms( $post_id, 'venue', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $venues ) ) {
				return new \WP_Error(
					'canonical_event_venue_read_failed',
					__( 'The published event venue could not be read safely.', 'extrachill-events' ),
					array(
						'status'         => 503,
						'database_error' => $venues->get_error_message(),
						'cause'          => $venues->get_error_code(),
					)
				);
			}
			$venue_id = 1 === count( (array) $venues ) ? (int) reset( $venues ) : 0;
		}
		if ( $venue_id < 1 ) {
			return null;
		}

		$content = (string) ( $post->post_content ?? '' );
		$event   = $this->event_details_from_content( $content );
		if ( empty( $event['startDate'] ) && $post_id > 0 && class_exists( '\\DataMachineEvents\\Core\\EventDatesTable' ) ) {
			$dates = \DataMachineEvents\Core\EventDatesTable::get( $post_id );
			if ( $dates && ! empty( $dates->start_datetime ) ) {
				$event['startDate'] = substr( (string) $dates->start_datetime, 0, 10 );
				$event['startTime'] = substr( (string) $dates->start_datetime, 11 );
				if ( ! empty( $dates->end_datetime ) ) {
					$event['endDate'] = substr( (string) $dates->end_datetime, 0, 10 );
					$event['endTime'] = substr( (string) $dates->end_datetime, 11 );
				}
			}
		}
		return $this->publication_window( $venue_id, $event );
	}

	private function venue_id_from_request( $request ): int {
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( 'venue' );
		} elseif ( is_array( $request ) ) {
			$value = $request['venue'] ?? ( $request['tax_input']['venue'] ?? null );
		} else {
			$value = null;
		}
		$ids   = array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		return 1 === count( $ids ) ? $ids[0] : 0;
	}

	private function event_details_from_content( string $content ): array {
		if ( '' === $content || ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		foreach ( parse_blocks( $content ) as $block ) {
			if ( 'data-machine-events/event-details' === ( $block['blockName'] ?? '' ) ) {
				return is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
			}
		}
		return array();
	}

	private function publication_window( int $venue_id, array $event ) {
		$timezone_name = (string) get_term_meta( $venue_id, '_venue_timezone', true );
		try {
			$timezone = new \DateTimeZone( $timezone_name );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'canonical_event_venue_timezone_invalid', __( 'The venue timezone is missing or invalid, so publication cannot be checked safely.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$start_date       = (string) ( $event['startDate'] ?? '' );
		$start_time       = (string) ( $event['startTime'] ?? '00:00:00' );
		$start_candidates = $this->strict_local_datetime_candidates( $start_date, $start_time, $timezone );
		if ( is_wp_error( $start_candidates ) ) {
			return $start_candidates;
		}

		$end_candidates = array();
		if ( ! empty( $event['endDate'] ) ) {
			$end_candidates = $this->strict_local_datetime_candidates( (string) $event['endDate'], (string) ( $event['endTime'] ?? '23:59:59' ), $timezone );
		} elseif ( ! empty( $event['endTime'] ) ) {
			$end_date = $start_date;
			$end_time = (string) $event['endTime'];
			if ( $this->normalized_time( $end_time ) <= $this->normalized_time( $start_time ) ) {
				$end_date = ( new \DateTimeImmutable( $start_date, $timezone ) )->modify( '+1 day' )->format( 'Y-m-d' );
			}
			$end_candidates = $this->strict_local_datetime_candidates( $end_date, $end_time, $timezone );
		} else {
			foreach ( $start_candidates as $candidate ) {
				$end_candidates[] = $candidate->modify( '+' . self::DEFAULT_DURATION_SECONDS . ' seconds' );
			}
		}
		if ( is_wp_error( $end_candidates ) ) {
			return $end_candidates;
		}

		$start_timestamps = array_map( static function ( \DateTimeImmutable $date ): int { return $date->getTimestamp(); }, $start_candidates );
		$end_timestamps   = array_map( static function ( \DateTimeImmutable $date ): int { return $date->getTimestamp(); }, $end_candidates );
		$start_timestamp  = min( $start_timestamps );
		$end_timestamp    = max( $end_timestamps );
		if ( $end_timestamp <= $start_timestamp ) {
			return new \WP_Error( 'canonical_event_datetime_invalid', __( 'The event end must be later than its start.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$utc = new \DateTimeZone( 'UTC' );
		$candidate_intervals = array();
		foreach ( $start_timestamps as $candidate_start ) {
			foreach ( $end_timestamps as $candidate_end ) {
				if ( $candidate_end <= $candidate_start ) {
					continue;
				}
				$key = $candidate_start . ':' . $candidate_end;
				$candidate_intervals[ $key ] = array(
					'start_at' => ( new \DateTimeImmutable( '@' . $candidate_start ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
					'end_at'   => ( new \DateTimeImmutable( '@' . $candidate_end ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
				);
			}
		}
		return array(
			'venue_id' => $venue_id,
			'start_at' => ( new \DateTimeImmutable( '@' . $start_timestamp ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'end_at'   => ( new \DateTimeImmutable( '@' . $end_timestamp ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
			'_candidate_intervals' => array_values( $candidate_intervals ),
		);
	}

	/** Return every UTC instant represented by one strict local wall time. */
	private function strict_local_datetime_candidates( string $date, string $time, \DateTimeZone $timezone ) {
		$time  = $this->normalized_time( $time );
		$value = $date . ' ' . $time;
		$wall  = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $wall || $wall->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'canonical_event_datetime_invalid', __( 'Event dates must be valid local venue wall times.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$offsets = array();
		foreach ( (array) $timezone->getTransitions( $wall->getTimestamp() - DAY_IN_SECONDS, $wall->getTimestamp() + DAY_IN_SECONDS ) as $transition ) {
			$offsets[] = (int) $transition['offset'];
		}
		$candidates = array();
		foreach ( array_unique( $offsets ) as $offset ) {
			$candidate = ( new \DateTimeImmutable( '@' . ( $wall->getTimestamp() - $offset ) ) )->setTimezone( $timezone );
			if ( $candidate->format( 'Y-m-d H:i:s' ) === $value ) {
				$candidates[ $candidate->getTimestamp() ] = $candidate;
			}
		}
		if ( empty( $candidates ) ) {
			return new \WP_Error( 'canonical_event_datetime_invalid', __( 'Event dates must be valid local venue wall times.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		ksort( $candidates, SORT_NUMERIC );
		return array_values( $candidates );
	}

	private function normalized_time( string $time ): string {
		return preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time . ':00' : $time;
	}

	private function lifecycle_key( array $context ): string {
		return hash( 'sha256', wp_json_encode( array(
			'invocation_id'    => (string) ( $context['invocation_id'] ?? '' ),
			'venue_term_id'   => (int) ( $context['venue_term_id'] ?? $context['next_venue_id'] ?? 0 ),
			'existing_post_id' => (int) ( $context['existing_post_id'] ?? $context['post_id'] ?? 0 ),
			'source'          => (string) ( $context['source'] ?? '' ),
			'source_id'       => (string) ( $context['source_id'] ?? '' ),
			'source_identity' => (string) ( $context['source_identity'] ?? '' ),
			'event'           => $context['event'] ?? array(),
		) ) );
	}

	private function audit_denial( string $context, \WP_Error $error ): void {
		do_action( 'extrachill_events_canonical_event_publication_denied', $context, $error );
		do_action(
			'datamachine_log',
			'warning',
			'Canonical event publication denied by venue booking policy',
			array(
				'context'    => $context,
				'error_code' => $error->get_error_code(),
				'error_data' => $error->get_error_data(),
			)
		);
	}
}
