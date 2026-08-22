<?php
/**
 * Post-commit booking receipt and competing-request correspondence.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Recovers lifecycle-backed automatic correspondence through the existing outbox. */
class BookingCorrespondenceAutomationService {

	public const EMIT_HOOK       = 'extrachill_events_emit_booking_correspondence';
	public const RECONCILE_HOOK  = 'extrachill_events_reconcile_booking_correspondence';
	public const SCHEDULER_GROUP = 'extrachill-events-booking-correspondence';
	public const BATCH_SIZE      = 25;

	/** @var bool */
	private static $registered = false;
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var BookingCommunicationService */
	private $communication;
	/** @var VenueBookingConfig */
	private $config;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?BookingCommunicationService $communication = null, ?VenueBookingConfig $config = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->communication = $communication ? $communication : new BookingCommunicationService( $this->bookings, $this->activity );
		$this->config        = $config ? $config : new VenueBookingConfig();
	}

	/** Register immediate and crash-recovery producers. */
	public static function register(): void {
		self::$registered = true;
		add_action( self::EMIT_HOOK, array( self::class, 'emit' ), 10, 1 );
		add_action( self::RECONCILE_HOOK, array( self::class, 'reconcile_scheduled' ) );
		add_action( 'init', array( self::class, 'ensure_reconciliation_schedule' ) );
	}

	/** Ensure a missed post-commit callback is recovered. */
	public static function ensure_reconciliation_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) || as_next_scheduled_action( self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
	}

	/** Best-effort immediate processing after the owning transaction commits. */
	public static function emit( int $source_activity_id ): void {
		if ( ! self::$registered ) {
			return;
		}
		$service = new self();
		$service->schedule_reconciliation();
		$service->reconcile_source( $source_activity_id );
	}

	/** Scheduler callback. */
	public static function reconcile_scheduled(): void {
		$result = ( new self() )->reconcile_pending();
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Scheduler-only diagnostic.
		}
	}

	/** Recover a bounded source page. */
	public function reconcile_pending( int $limit = 25 ) {
		$sources = $this->activity->correspondence_sources_without_completion( $limit );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}
		$completed = 0;
		foreach ( $sources as $source ) {
			$result = $this->process_source( $source );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$completed += ! empty( $result['completed'] ) ? 1 : 0;
		}
		if ( count( $sources ) === max( 1, min( 100, $limit ) ) ) {
			$this->schedule_reconciliation();
		}
		return compact( 'completed' );
	}

	/** Reconcile one exact lifecycle source. */
	public function reconcile_source( int $source_activity_id ) {
		$source = $this->activity->get( $source_activity_id );
		return is_array( $source ) ? $this->process_source( $source ) : new \WP_Error( 'booking_correspondence_source_invalid', __( 'The booking correspondence source is invalid.', 'extrachill-events' ) );
	}

	/** Queue one artist-authorized access recovery through the existing outbox. */
	public function resend_receipt( int $source_activity_id, string $recovery_key ) {
		$source = $this->activity->get( $source_activity_id );
		if ( ! is_array( $source ) || 'inquiry_submitted' !== $source['kind'] ) {
			return new \WP_Error( 'booking_correspondence_source_invalid', __( 'The booking correspondence source is invalid.', 'extrachill-events' ) );
		}
		return $this->process_receipt( $source, $recovery_key );
	}

	private function process_source( array $source ) {
		$done = $this->activity->find_by_external_id( (int) $source['booking_id'], 'booking_correspondence_source_completed', (string) $source['id'] );
		if ( is_wp_error( $done ) || is_array( $done ) ) {
			return is_wp_error( $done ) ? $done : array( 'completed' => true );
		}
		if ( 'inquiry_submitted' === $source['kind'] ) {
			return $this->process_receipt( $source );
		}
		if ( 'deal_confirmed' === $source['kind'] ) {
			return $this->process_competitors( $source );
		}
		return new \WP_Error( 'booking_correspondence_source_invalid', __( 'The booking correspondence source is invalid.', 'extrachill-events' ) );
	}

	private function process_receipt( array $source, ?string $recovery_key = null ) {
		$booking = $this->bookings->get( (int) $source['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_correspondence_booking_missing', __( 'The booking correspondence record is unavailable.', 'extrachill-events' ) );
		}
		$venue = get_term( (int) $booking['venue_term_id'], 'venue' );
		if ( ! $venue || is_wp_error( $venue ) ) {
			return new \WP_Error( 'booking_correspondence_venue_unavailable', __( 'The booking venue could not be resolved for correspondence.', 'extrachill-events' ) );
		}
		$interval = $this->requested_interval( $booking );
		if ( is_wp_error( $interval ) ) {
			return $interval;
		}
		$message  = null === $recovery_key
			? sprintf(
				"We received your booking inquiry and it is pending review.\n\nArtist: %s\nVenue: %s\nRequested interval: %s\nRequested space: %s\nReference: %s\n\nSubmitting an inquiry does not place a hold or confirm the booking. Reply to this email to continue this booking thread.",
				$booking['artist_name'],
				$venue->name,
				$interval['display'],
				$this->space_name( $booking, true ),
				$booking['public_id']
			)
			: sprintf(
				"Here is the private access receipt you requested for your booking inquiry at %s.\n\nReference: %s\n\nThis email does not indicate the inquiry's current status. Open the venue booking form, choose the existing-inquiry access option, and enter the reference and access code below to view the current status. Keep both private.",
				$venue->name,
				$booking['public_id']
			);
		$template = null === $recovery_key ? 'inquiry_receipt' : 'inquiry_access_recovery';
		$result   = $this->communication->request_automatic( (int) $booking['id'], (int) $source['id'], $template, $message, $recovery_key );
		if ( null !== $recovery_key ) {
			return $result;
		}
		return $this->accepted( $result ) ? $this->complete_source( $source, array( 'template' => 'inquiry_receipt' ) ) : $result;
	}

	private function process_competitors( array $source ) {
		$confirmed = $this->bookings->get( (int) $source['booking_id'] );
		if ( ! is_array( $confirmed ) || 'confirmed' !== $confirmed['status'] ) {
			return is_wp_error( $confirmed ) ? $confirmed : $this->complete_source( $source, array( 'reason' => 'confirmation_no_longer_current' ) );
		}
		$batch = $this->activity->find_by_external_id( (int) $confirmed['id'], 'booking_correspondence_batch_completed', (string) $source['id'] );
		if ( is_wp_error( $batch ) ) {
			return $batch;
		}
		$bounds = $this->confirmed_local_bounds( $confirmed );
		if ( is_wp_error( $bounds ) ) {
			return $bounds;
		}
		$after      = is_array( $batch ) ? (int) ( $batch['payload']['data']['after_booking_id'] ?? 0 ) : 0;
		$candidates = $this->bookings->list_competing_requests( $confirmed, $bounds['start_at'], $bounds['end_at'], $after, self::BATCH_SIZE );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		foreach ( $candidates as $candidate ) {
			$current = $this->bookings->get( (int) $candidate['id'] );
			if ( ! is_array( $current ) ) {
				continue;
			}
			$competes = $this->still_competes( $current, $confirmed );
			if ( is_wp_error( $competes ) ) {
				return $competes;
			}
			if ( ! $competes ) {
				continue;
			}
			$interval = $this->requested_interval( $current );
			if ( is_wp_error( $interval ) ) {
				return $interval;
			}
			$message = sprintf(
				'The interval you requested at %s (%s, %s) has been filled. Your inquiry has not been declined: reply to this email with another exact date, start/end time, and space you would like us to review.',
				$this->venue_name( $confirmed ),
				$interval['display'],
				$this->space_name( $current, true )
			);
			$result  = $this->communication->request_automatic( (int) $current['id'], (int) $source['id'], 'date_filled', $message );
			if ( ! $this->accepted( $result ) ) {
				return $result;
			}
		}
		if ( count( $candidates ) < self::BATCH_SIZE ) {
			return $this->complete_source( $source, array( 'template' => 'date_filled' ) );
		}
		$last   = end( $candidates );
		$cursor = (int) $last['id'];
		$marker = $this->activity->append(
			array(
				'booking_id'      => $confirmed['id'],
				'kind'            => 'booking_correspondence_batch_completed',
				'actor_type'      => 'system',
				'external_id'     => (string) $source['id'],
				'idempotency_key' => sprintf( 'booking-correspondence-batch:%d:%d', $source['id'], $cursor ),
				'payload'         => array( 'after_booking_id' => $cursor ),
			)
		);
		$this->schedule_reconciliation();
		return is_wp_error( $marker ) ? $marker : array( 'completed' => false );
	}

	private function still_competes( array $candidate, array $confirmed ) {
		$terminal = array( 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' );
		if ( in_array( $candidate['status'], $terminal, true ) || (int) $candidate['venue_term_id'] !== (int) $confirmed['venue_term_id'] || (string) $candidate['requested_space_key'] !== (string) $confirmed['space_key'] || empty( $candidate['requested_start_at'] ) || empty( $candidate['requested_end_at'] ) ) {
			return false;
		}
		$requested = $this->requested_interval( $candidate );
		if ( is_wp_error( $requested ) ) {
			return $requested;
		}
		$confirmed_start = $this->utc_datetime( (string) $confirmed['performance_start_at'] );
		$confirmed_end   = $this->utc_datetime( (string) $confirmed['performance_end_at'] );
		if ( ! $confirmed_start || ! $confirmed_end || $confirmed_end <= $confirmed_start ) {
			return new \WP_Error( 'booking_correspondence_interval_invalid', __( 'The confirmed booking interval is invalid for correspondence.', 'extrachill-events' ) );
		}
		return $requested['start']->getTimestamp() < $confirmed_end->getTimestamp() && $requested['end']->getTimestamp() > $confirmed_start->getTimestamp();
	}

	/** Resolve one requested venue-local wall-clock interval for display and comparison. */
	private function requested_interval( array $booking ) {
		$timezone = $this->venue_timezone( (int) $booking['venue_term_id'] );
		if ( is_wp_error( $timezone ) ) {
			return $timezone;
		}
		$start_candidates = $this->local_datetime_candidates( (string) ( $booking['requested_start_at'] ?? '' ), $timezone );
		$end_candidates   = $this->local_datetime_candidates( (string) ( $booking['requested_end_at'] ?? '' ), $timezone );
		if ( is_wp_error( $start_candidates ) || is_wp_error( $end_candidates ) || 1 !== count( $start_candidates ) || 1 !== count( $end_candidates ) ) {
			return new \WP_Error( 'booking_correspondence_interval_invalid', __( 'The requested venue-local interval is invalid or ambiguous for correspondence.', 'extrachill-events' ) );
		}
		$start = $start_candidates[0];
		$end   = $end_candidates[0];
		if ( $end <= $start ) {
			return new \WP_Error( 'booking_correspondence_interval_invalid', __( 'The requested venue-local interval is invalid for correspondence.', 'extrachill-events' ) );
		}
		$display  = $start->format( 'l, F j, Y, g:i A' ) . ' to ';
		$display .= $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' ) ? $end->format( 'g:i A T' ) : $end->format( 'l, F j, Y, g:i A T' );
		$display .= ' (' . $timezone->getName() . ')';
		return compact( 'start', 'end', 'display' );
	}

	/** Project one confirmed UTC interval into the venue-local request storage domain. */
	private function confirmed_local_bounds( array $booking ) {
		$timezone = $this->venue_timezone( (int) $booking['venue_term_id'] );
		$start    = $this->utc_datetime( (string) ( $booking['performance_start_at'] ?? '' ) );
		$end      = $this->utc_datetime( (string) ( $booking['performance_end_at'] ?? '' ) );
		if ( is_wp_error( $timezone ) ) {
			return $timezone;
		}
		if ( ! $start || ! $end || $end <= $start ) {
			return new \WP_Error( 'booking_correspondence_interval_invalid', __( 'The confirmed booking interval is invalid for correspondence.', 'extrachill-events' ) );
		}
		return array(
			'start_at' => $start->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
			'end_at'   => $end->setTimezone( $timezone )->format( 'Y-m-d H:i:s' ),
		);
	}

	/** Resolve the canonical timezone owned by the venue data projection. */
	private function venue_timezone( int $venue_id ) {
		$venue_data = function_exists( 'data_machine_events_get_venue_data' ) ? data_machine_events_get_venue_data( $venue_id ) : null;
		$name       = is_array( $venue_data ) ? (string) ( $venue_data['timezone'] ?? '' ) : '';
		if ( '' === $name || ! in_array( $name, timezone_identifiers_list(), true ) ) {
			return new \WP_Error( 'booking_correspondence_timezone_invalid', __( 'The venue timezone is unavailable for correspondence.', 'extrachill-events' ) );
		}
		try {
			return new \DateTimeZone( $name );
		} catch ( \Throwable $exception ) {
			return new \WP_Error( 'booking_correspondence_timezone_invalid', __( 'The venue timezone is unavailable for correspondence.', 'extrachill-events' ) );
		}
	}

	/** Return every UTC instant represented by one strict local wall time. */
	private function local_datetime_candidates( string $value, \DateTimeZone $timezone ) {
		$wall = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $wall || $wall->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'booking_correspondence_interval_invalid', __( 'The requested venue-local interval is invalid for correspondence.', 'extrachill-events' ) );
		}
		$transitions = $timezone->getTransitions( $wall->getTimestamp() - DAY_IN_SECONDS, $wall->getTimestamp() + DAY_IN_SECONDS );
		if ( ! is_array( $transitions ) ) {
			return new \WP_Error( 'booking_correspondence_timezone_invalid', __( 'The venue timezone cannot be checked safely for correspondence.', 'extrachill-events' ) );
		}
		$candidates = array();
		foreach ( array_unique( array_column( $transitions, 'offset' ) ) as $offset ) {
			$candidate = ( new \DateTimeImmutable( '@' . ( $wall->getTimestamp() - (int) $offset ) ) )->setTimezone( $timezone );
			if ( $candidate->format( 'Y-m-d H:i:s' ) === $value ) {
				$candidates[ $candidate->getTimestamp() ] = $candidate;
			}
		}
		ksort( $candidates, SORT_NUMERIC );
		return array_values( $candidates );
	}

	/** Parse one canonical UTC performance timestamp exactly. */
	private function utc_datetime( string $value ) {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value ? $date : false;
	}

	private function complete_source( array $source, array $payload ) {
		$record = $this->activity->append(
			array(
				'booking_id'      => $source['booking_id'],
				'kind'            => 'booking_correspondence_source_completed',
				'actor_type'      => 'system',
				'external_id'     => (string) $source['id'],
				'idempotency_key' => 'booking-correspondence-source:' . $source['id'],
				'payload'         => array_merge( array( 'source_activity_id' => (int) $source['id'] ), $payload ),
			)
		);
		return is_wp_error( $record ) ? $record : array( 'completed' => true );
	}

	private function accepted( $state ): bool {
		return is_array( $state ) && 'queued' === ( $state['status'] ?? '' );
	}

	private function venue_name( array $booking ): string {
		$venue = get_term( (int) $booking['venue_term_id'], 'venue' );
		return $venue && ! is_wp_error( $venue ) ? (string) $venue->name : __( 'the venue', 'extrachill-events' );
	}

	private function space_name( array $booking, bool $requested ): string {
		$key    = (string) ( $requested ? $booking['requested_space_key'] : $booking['space_key'] );
		$config = $this->config->get( (int) $booking['venue_term_id'] );
		if ( is_array( $config ) ) {
			foreach ( $config['spaces'] as $space ) {
				if ( $key === $space['key'] ) {
					return (string) $space['name'];
				}
			}
		}
		return '' !== $key ? $key : __( 'Not specified', 'extrachill-events' );
	}

	private function schedule_reconciliation(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 1, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
		}
	}
}
