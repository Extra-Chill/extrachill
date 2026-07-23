<?php
/**
 * Confirmed booking to canonical event conversion.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates the two-phase booking/event handoff without owning DME internals. */
class BookingEventConversionService {
	public const SOURCE = 'extrachill-events-booking';

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingHoldRepository */
	private $holds;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingHoldRepository $holds = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->holds         = $holds ? $holds : new BookingHoldRepository( $this->bookings, $this->activity, $this->authorization );
	}

	/** Convert a confirmed booking through DME's public idempotent ability. */
	public function convert( int $booking_id, int $expected_version, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : $this->not_found();
		}
		$allowed = $this->authorize( $actor_id, $booking['venue_term_id'] );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'data-machine-events/upsert-event' ) : null;
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			return new \WP_Error(
				'booking_event_ability_unavailable',
				__( 'Canonical event conversion is temporarily unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$preflight = $this->locked_preflight( $booking_id, $expected_version, $actor_id );
		if ( is_wp_error( $preflight ) || isset( $preflight['event_action'] ) ) {
			return $preflight;
		}

		$upstream = $ability->execute( $preflight['input'] );
		if ( is_wp_error( $upstream ) ) {
			return $this->finalize_failure( $booking_id, $actor_id, $preflight['attempt'], $upstream );
		}
		$verified = $this->verify_upstream( $upstream, $preflight['booking'] );
		if ( is_wp_error( $verified ) ) {
			return $verified;
		}
		$verified['attempt'] = $preflight['attempt'];

		return $this->finalize( $booking_id, $expected_version, $actor_id, $verified );
	}

	/** Complete authorization and data validation under the aggregate locks. */
	private function locked_preflight( int $booking_id, int $expected_version, int $actor_id ) {
		$initial = $this->bookings->get( $booking_id );
		if ( ! is_array( $initial ) ) {
			return is_wp_error( $initial ) ? $initial : $this->not_found();
		}
		$started = $this->begin_authorized( $initial['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : $this->not_found() );
		}
		if ( null !== $booking['event_id'] ) {
			$existing = $this->existing_result( $booking );
			if ( is_wp_error( $existing ) ) {
				return $this->rollback( $existing );
			}
			$committed = $this->commit( false );
			return is_wp_error( $committed ) ? $committed : $existing;
		}
		$validated = $this->validate_preflight( $booking, $expected_version );
		if ( is_wp_error( $validated ) ) {
			return $this->rollback( $validated );
		}
		$state = $this->activity->event_conversion_state( $booking_id, $booking['public_id'] );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		if ( 'completed' === $state['status'] ) {
			return $this->rollback(
				new \WP_Error(
					'booking_event_conversion_state_invalid',
					__( 'Completed event conversion is missing its booking event claim.', 'extrachill-events' ),
					array(
						'status'     => 409,
						'repairable' => true,
						'detail'     => 'completed_without_event',
					)
				)
			);
		}
		$attempt = $state['pending'] ? $state['attempt'] : $state['attempt'] + 1;
		if ( ! $state['pending'] ) {
			$source_identity = hash( 'sha256', self::SOURCE . "\0" . $booking['public_id'] );
			$intent          = $this->activity->append(
				array(
					'booking_id'      => $booking_id,
					'kind'            => 'event_conversion_started',
					'actor_type'      => 'user',
					'actor_id'        => $actor_id,
					'idempotency_key' => $this->marker_key( $booking['public_id'], $attempt, 'event_conversion_started' ),
					'payload'         => array(
						'attempt'          => $attempt,
						'source'           => self::SOURCE,
						'source_id'        => $booking['public_id'],
						'source_identity'  => $source_identity,
						'expected_version' => $expected_version,
					),
				)
			);
			if ( is_wp_error( $intent ) ) {
				return $this->rollback( $intent );
			}
			$state = $this->activity->event_conversion_state( $booking_id, $booking['public_id'] );
			if ( is_wp_error( $state ) || ! $state['pending'] || $attempt !== $state['attempt'] ) {
				return $this->rollback(
					is_wp_error( $state ) ? $state : new \WP_Error(
						'booking_event_conversion_state_invalid',
						__( 'Booking event conversion start could not be verified.', 'extrachill-events' ),
						array(
							'status'     => 409,
							'repairable' => true,
						)
					)
				);
			}
		}
		$committed = $this->commit( false );
		return is_wp_error( $committed ) ? $committed : array(
			'input'   => $validated,
			'booking' => $booking,
			'attempt' => $attempt,
		);
	}

	/** Claim the external event and append its sole activity in one transaction. */
	private function finalize( int $booking_id, int $expected_version, int $actor_id, array $upstream ) {
		$event_id  = $upstream['event_id'];
		$event_url = $upstream['event_url'];
		$action    = $upstream['action'];
		$attempt   = $upstream['attempt'];
		$initial   = $this->bookings->get( $booking_id );
		if ( ! is_array( $initial ) ) {
			return is_wp_error( $initial ) ? $initial : $this->not_found();
		}
		$started = $this->begin_authorized( $initial['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : $this->not_found() );
		}
		if ( null !== $booking['event_id'] ) {
			if ( $event_id !== (int) $booking['event_id'] ) {
				return $this->rollback( $this->different_event( $booking['event_id'] ) );
			}
			$existing  = $this->existing_result( $booking );
			$committed = is_wp_error( $existing ) ? $this->rollback( $existing ) : $this->commit( true );
			return is_wp_error( $committed ) ? $committed : $existing;
		}
		$validated = $this->validate_preflight( $booking, $expected_version );
		if ( is_wp_error( $validated ) ) {
			return $this->rollback( $validated );
		}
		$state = $this->activity->event_conversion_state( $booking_id, $booking['public_id'] );
		if ( is_wp_error( $state ) || ! $state['pending'] || $attempt !== $state['attempt'] ) {
			return $this->rollback(
				is_wp_error( $state ) ? $state : new \WP_Error(
					'booking_event_conversion_state_invalid',
					__( 'The pending event conversion attempt changed before finalization.', 'extrachill-events' ),
					array(
						'status'     => 409,
						'repairable' => true,
					)
				)
			);
		}
		$claimed = $this->bookings->claim_event( $booking_id, $event_id, $expected_version );
		if ( is_wp_error( $claimed ) ) {
			if ( 'booking_event_already_linked' === $claimed->get_error_code() ) {
				return $this->rollback( $this->different_event( $claimed->get_error_data()['event_id'] ?? 0 ) );
			}
			return $this->rollback( $claimed );
		}
		$event_url = '' !== $event_url ? $event_url : (string) get_permalink( $event_id );
		$activity  = $this->activity->append(
			array(
				'booking_id'      => $booking_id,
				'kind'            => 'event_converted',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'idempotency_key' => $this->marker_key( $booking['public_id'], $attempt, 'event_converted' ),
				'external_id'     => (string) $event_id,
				'payload'         => array(
					'attempt'         => $attempt,
					'event_id'        => $event_id,
					'event_url'       => $event_url,
					'source'          => $upstream['source']['name'],
					'source_id'       => $upstream['source']['id'],
					'source_identity' => $upstream['source']['identity'],
					'upstream_action' => $action,
					'version'         => $expected_version + 1,
				),
			)
		);
		if ( is_wp_error( $activity ) ) {
			return $this->rollback( $activity );
		}
		$committed = $this->commit( true );
		return is_wp_error( $committed ) ? $committed : $this->result( $booking_id, $expected_version + 1, $event_id, $event_url, $action, false );
	}

	/** Persist an explicit failed DME response as the terminal marker for its attempt. */
	private function finalize_failure( int $booking_id, int $actor_id, int $attempt, \WP_Error $upstream ) {
		$initial = $this->bookings->get( $booking_id );
		if ( ! is_array( $initial ) ) {
			return $this->failure_finalize_error( $upstream, $attempt, is_wp_error( $initial ) ? $initial : $this->not_found() );
		}
		$started = $this->begin_authorized( $initial['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $this->failure_finalize_error( $upstream, $attempt, $started );
		}
		$booking = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $booking ) ) {
			$cause = is_wp_error( $booking ) ? $booking : $this->not_found();
			return $this->failure_finalize_error( $upstream, $attempt, $this->rollback( $cause ) );
		}
		$state = $this->activity->event_conversion_state( $booking_id, $booking['public_id'] );
		if ( is_wp_error( $state ) || ! $state['pending'] || $attempt !== $state['attempt'] ) {
			$cause = is_wp_error( $state ) ? $state : new \WP_Error(
				'booking_event_conversion_state_invalid',
				__( 'The failed event conversion attempt is no longer pending.', 'extrachill-events' ),
				array(
					'status'     => 409,
					'repairable' => true,
				)
			);
			return $this->failure_finalize_error( $upstream, $attempt, $this->rollback( $cause ) );
		}
		$data      = $upstream->get_error_data();
		$retryable = $this->upstream_retryable( $data );
		$failed    = $this->activity->append(
			array(
				'booking_id'      => $booking_id,
				'kind'            => 'event_conversion_failed',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'idempotency_key' => $this->marker_key( $booking['public_id'], $attempt, 'event_conversion_failed' ),
				'payload'         => array(
					'attempt'         => $attempt,
					'source'          => self::SOURCE,
					'source_id'       => $booking['public_id'],
					'source_identity' => hash( 'sha256', self::SOURCE . "\0" . $booking['public_id'] ),
					'upstream_code'   => $upstream->get_error_code(),
					'upstream_data'   => $data,
					'retryable'       => $retryable,
					'booking_version' => $booking['version'],
				),
			)
		);
		if ( is_wp_error( $failed ) ) {
			return $this->failure_finalize_error( $upstream, $attempt, $this->rollback( $failed ) );
		}
		$verified = $this->activity->event_conversion_state( $booking_id, $booking['public_id'] );
		if ( is_wp_error( $verified ) || 'failed' !== $verified['status'] || $attempt !== $verified['attempt'] ) {
			$cause = is_wp_error( $verified ) ? $verified : new \WP_Error(
				'booking_event_conversion_state_invalid',
				__( 'The failed event conversion marker could not be verified.', 'extrachill-events' ),
				array(
					'status'     => 409,
					'repairable' => true,
				)
			);
			return $this->failure_finalize_error( $upstream, $attempt, $this->rollback( $cause ) );
		}
		$committed = $this->commit( true );
		if ( is_wp_error( $committed ) ) {
			return $this->failure_finalize_error( $upstream, $attempt, $committed );
		}
		return $this->upstream_error( $upstream, $attempt, $booking['version'] );
	}

	/** Validate immutable public conversion facts and build deterministic input. */
	private function validate_preflight( array $booking, int $expected_version ) {
		if ( (int) $booking['version'] !== $expected_version ) {
			return new \WP_Error(
				'booking_version_conflict',
				__( 'The booking changed since it was read.', 'extrachill-events' ),
				array(
					'status'          => 409,
					'current_version' => $booking['version'],
				)
			);
		}
		if ( 'confirmed' !== $booking['status'] ) {
			return new \WP_Error(
				'booking_event_status_forbidden',
				__( 'Only confirmed bookings can become events.', 'extrachill-events' ),
				array(
					'status'         => 409,
					'booking_status' => $booking['status'],
				)
			);
		}
		foreach ( array( 'space_key', 'performance_start_at', 'performance_end_at' ) as $field ) {
			if ( empty( $booking[ $field ] ) ) {
				return new \WP_Error(
					'booking_event_selection_incomplete',
					__( 'The authoritative booking performance is incomplete.', 'extrachill-events' ),
					array(
						'status' => 409,
						'field'  => $field,
					)
				);
			}
		}
		$deal = $booking['confirmed_deal']['data'] ?? null;
		if ( ! is_array( $deal ) || is_wp_error( BookingMutationService::normalize_deal_document( $deal ) ) || ! BookingMutationService::documents_equal( BookingMutationService::normalize_deal_document( $deal ), $deal ) ) {
			return new \WP_Error( 'booking_event_confirmed_deal_invalid', __( 'A normalized frozen confirmed deal is required.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue = get_term( $booking['venue_term_id'], 'venue' );
		if ( ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'booking_event_venue_invalid', __( 'The canonical venue is unavailable.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$metadata = $this->venue_metadata( $booking['venue_term_id'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		$holds = $this->holds->converted_for_booking( $booking );
		if ( is_wp_error( $holds ) ) {
			return $holds;
		}
		if ( 1 !== count( $holds ) ) {
			return new \WP_Error(
				'booking_event_converted_hold_invalid',
				__( 'Exactly one converted hold must match the confirmed performance.', 'extrachill-events' ),
				array(
					'status'         => 409,
					'matching_holds' => count( $holds ),
				)
			);
		}
		$start = $this->local_datetime( $booking['performance_start_at'], $metadata['venueTimezone'] );
		$end   = $this->local_datetime( $booking['performance_end_at'], $metadata['venueTimezone'] );
		if ( is_wp_error( $start ) || is_wp_error( $end ) ) {
			return is_wp_error( $start ) ? $start : $end;
		}
		$event = array_merge(
			array(
				'title'         => $booking['artist_name'] . ' at ' . $venue->name,
				'startDate'     => $start->format( 'Y-m-d' ),
				'startTime'     => $start->format( 'H:i' ),
				'endDate'       => $end->format( 'Y-m-d' ),
				'endTime'       => $end->format( 'H:i' ),
				'performer'     => $booking['artist_name'],
				'performerType' => 'PerformingGroup',
				'venue'         => $venue->name,
				'eventStatus'   => 'EventScheduled',
				'eventType'     => 'MusicEvent',
			),
			$metadata
		);
		$price = $this->public_price( $deal );
		if ( null !== $price ) {
			$event['price']         = $price;
			$event['priceCurrency'] = $deal['currency'];
		}
		if ( ! empty( $deal['ticket_url'] ) ) {
			$event['ticketUrl'] = $deal['ticket_url'];
		}
		return array(
			'source'      => self::SOURCE,
			'source_id'   => $booking['public_id'],
			'post_status' => 'publish',
			'event'       => $event,
		);
	}

	/** Require DME to return the exact canonical identity and venue proof requested. */
	private function verify_upstream( $upstream, array $booking ) {
		$identity = hash( 'sha256', self::SOURCE . "\0" . $booking['public_id'] );
		$valid    = is_array( $upstream )
			&& true === ( $upstream['success'] ?? null )
			&& 0 < (int) ( $upstream['event_id'] ?? 0 )
			&& in_array( $upstream['action'] ?? '', array( 'created', 'updated', 'no_change' ), true )
			&& self::SOURCE === ( $upstream['source']['name'] ?? null )
			&& ( $upstream['source']['id'] ?? null ) === $booking['public_id']
			&& ( $upstream['source']['identity'] ?? null ) === $identity
			&& (int) ( $upstream['normalized']['venue_id'] ?? 0 ) === (int) $booking['venue_term_id']
			&& 'publish' === ( $upstream['normalized']['post_status'] ?? null );
		if ( ! $valid ) {
			return new \WP_Error(
				'booking_event_upsert_failed',
				__( 'Canonical event conversion returned an invalid result.', 'extrachill-events' ),
				array(
					'status'        => 502,
					'retryable'     => true,
					'upstream_code' => 'invalid_response',
					'upstream_data' => $upstream,
				)
			);
		}
		return array(
			'event_id'  => (int) $upstream['event_id'],
			'event_url' => (string) ( $upstream['event_url'] ?? '' ),
			'action'    => (string) $upstream['action'],
			'source'    => $upstream['source'],
		);
	}

	/** Read only DME-established canonical venue metadata. */
	private function venue_metadata( int $venue_id ) {
		$map    = array(
			'_venue_address'     => 'venueAddress',
			'_venue_city'        => 'venueCity',
			'_venue_state'       => 'venueState',
			'_venue_zip'         => 'venueZip',
			'_venue_country'     => 'venueCountry',
			'_venue_phone'       => 'venuePhone',
			'_venue_website'     => 'venueWebsite',
			'_venue_coordinates' => 'venueCoordinates',
			'_venue_capacity'    => 'venueCapacity',
			'_venue_timezone'    => 'venueTimezone',
		);
		$output = array();
		foreach ( $map as $meta_key => $event_key ) {
			$value = get_term_meta( $venue_id, $meta_key, true );
			if ( '' !== trim( (string) $value ) ) {
				$output[ $event_key ] = (string) $value;
			}
		}
		foreach ( array( 'venueAddress', 'venueCity', 'venueState', 'venueCountry', 'venueTimezone' ) as $required ) {
			if ( empty( $output[ $required ] ) ) {
				return new \WP_Error(
					'booking_event_venue_incomplete',
					__( 'Canonical venue metadata is incomplete.', 'extrachill-events' ),
					array(
						'status' => 409,
						'field'  => $required,
					)
				);
			}
		}
		try {
			new \DateTimeZone( $output['venueTimezone'] );
		} catch ( \Exception $exception ) {
			return new \WP_Error( 'booking_event_venue_timezone_invalid', __( 'The canonical venue timezone is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $output;
	}

	private function local_datetime( string $value, string $timezone ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'booking_event_datetime_invalid', __( 'The performance time is not strict UTC.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $date->setTimezone( new \DateTimeZone( $timezone ) );
	}

	private function public_price( array $deal ): ?string {
		$advance = $deal['advance_ticket_price_cents'];
		$door    = $deal['door_ticket_price_cents'];
		if ( null === $advance && null === $door ) {
			return null;
		}
		if ( null !== $advance && null !== $door && $advance === $door ) {
			return number_format( $advance / 100, 2, '.', '' );
		}
		if ( null === $door ) {
			return number_format( $advance / 100, 2, '.', '' ) . ' adv';
		}
		if ( null === $advance ) {
			return number_format( $door / 100, 2, '.', '' ) . ' door';
		}
		return number_format( $advance / 100, 2, '.', '' ) . ' adv / ' . number_format( $door / 100, 2, '.', '' ) . ' door';
	}

	private function begin_authorized( int $venue_id, int $actor_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'booking_event_transaction_start_failed', __( 'The booking event transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$this->transaction_active = true;
		$table                    = BookingSchema::memberships_table();
		$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existing exact-venue authority lock order.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_event_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorize( $actor_id, $venue_id );
		return is_wp_error( $allowed ) ? $this->rollback( $allowed ) : true;
	}

	private function authorize( int $actor_id, int $venue_id ) {
		$allowed = $this->authorization->authorize( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : ( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	private function commit( bool $finalize ) {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' );
		$this->transaction_active = false;
		if ( false !== $result ) {
			return true;
		}
		return new \WP_Error(
			$finalize ? 'booking_event_finalize_uncertain' : 'booking_event_preflight_uncertain',
			__( 'The booking event transaction outcome could not be confirmed.', 'extrachill-events' ),
			array(
				'status'         => 503,
				'retryable'      => true,
				'database_error' => $wpdb->last_error,
			)
		);
	}

	private function rollback( \WP_Error $cause ) {
		global $wpdb;
		if ( ! $this->transaction_active ) {
			return $cause;
		}
		$result                   = $wpdb->query( 'ROLLBACK' );
		$this->transaction_active = false;
		return false === $result ? new \WP_Error(
			'booking_event_transaction_rollback_failed',
			__( 'The booking event transaction could not be rolled back.', 'extrachill-events' ),
			array(
				'cause'          => $cause->get_error_code(),
				'database_error' => $wpdb->last_error,
			)
		) : $cause;
	}

	private function existing_result( array $booking ) {
		$post       = get_post( $booking['event_id'] );
		$type       = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		$identity   = hash( 'sha256', self::SOURCE . "\0" . $booking['public_id'] );
		$venue_ids  = wp_get_object_terms( $booking['event_id'], 'venue', array( 'fields' => 'ids' ) );
		$conversion = $this->activity->event_conversion_state( $booking['id'], $booking['public_id'] );
		if ( is_wp_error( $conversion ) ) {
			return $conversion;
		}
		$integrity = array();
		if ( ! $post || ( $post->post_type ?? null ) !== $type ) {
			$integrity[] = 'post_type';
		} elseif ( 'publish' !== $post->post_status ) {
			$integrity[] = 'post_status';
		}
		if ( self::SOURCE !== get_post_meta( $booking['event_id'], '_datamachine_event_source', true ) ) {
			$integrity[] = 'source_name';
		}
		if ( get_post_meta( $booking['event_id'], '_datamachine_event_source_id', true ) !== $booking['public_id'] ) {
			$integrity[] = 'source_id';
		}
		if ( get_post_meta( $booking['event_id'], '_datamachine_event_source_identity', true ) !== $identity ) {
			$integrity[] = 'source_identity';
		}
		if ( is_wp_error( $venue_ids ) || array_map( 'intval', (array) $venue_ids ) !== array( (int) $booking['venue_term_id'] ) ) {
			$integrity[] = 'venue';
		}
		$completed = is_array( $conversion ) ? $conversion['completed'] : null;
		if ( ! is_array( $completed ) || (int) ( $completed['payload']['data']['event_id'] ?? 0 ) !== (int) $booking['event_id'] || ( $completed['payload']['data']['source_identity'] ?? null ) !== $identity ) {
			$integrity[] = 'event_converted_activity';
		}
		if ( $integrity ) {
			return new \WP_Error(
				'booking_event_existing_invalid',
				__( 'The linked site-local event is invalid.', 'extrachill-events' ),
				array(
					'status'     => 409,
					'repairable' => true,
					'event_id'   => $booking['event_id'],
					'integrity'  => $integrity,
				)
			);
		}
		return $this->result( $booking['id'], $booking['version'], $booking['event_id'], (string) get_permalink( $booking['event_id'] ), 'existing', true );
	}

	private function result( int $booking_id, int $version, int $event_id, string $url, string $action, bool $existing ): array {
		return array(
			'booking_id'        => $booking_id,
			'booking_version'   => $version,
			'event_id'          => $event_id,
			'event_url'         => $url,
			'event_action'      => $action,
			'already_converted' => $existing,
		);
	}

	private function upstream_error( \WP_Error $error, int $attempt, int $booking_version ): \WP_Error {
		$data      = $error->get_error_data();
		$retryable = $this->upstream_retryable( $data );
		return new \WP_Error(
			'booking_event_upsert_failed',
			__( 'Canonical event conversion failed.', 'extrachill-events' ),
			array(
				'status'          => $retryable ? 503 : 422,
				'retryable'       => $retryable,
				'upstream_code'   => $error->get_error_code(),
				'upstream_data'   => $data,
				'attempt'         => $attempt,
				'booking_version' => $booking_version,
			)
		);
	}

	private function failure_finalize_error( \WP_Error $upstream, int $attempt, \WP_Error $cause ): \WP_Error {
		return new \WP_Error(
			'booking_event_failure_finalize_failed',
			__( 'The failed event conversion attempt could not be finalized.', 'extrachill-events' ),
			array(
				'status'        => 503,
				'retryable'     => true,
				'attempt'       => $attempt,
				'upstream_code' => $upstream->get_error_code(),
				'upstream_data' => $upstream->get_error_data(),
				'cause'         => $cause->get_error_code(),
				'cause_data'    => $cause->get_error_data(),
			)
		);
	}

	private function upstream_retryable( $data ): bool {
		return is_array( $data ) && ( ! empty( $data['retryable'] ) || ! empty( $data['transient'] ) || (int) ( $data['status'] ?? 0 ) >= 500 );
	}

	private function marker_key( string $public_id, int $attempt, string $kind ): string {
		return sprintf( 'event-conversion:%s:%d:%s', $public_id, $attempt, $kind );
	}

	private function different_event( int $event_id ): \WP_Error {
		return new \WP_Error(
			'booking_event_already_linked',
			__( 'The booking is already linked to a different event.', 'extrachill-events' ),
			array(
				'status'   => 409,
				'event_id' => $event_id,
			)
		);
	}

	private function not_found(): \WP_Error {
		return new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
	}
}
