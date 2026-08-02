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

	private function process_receipt( array $source ) {
		$booking = $this->bookings->get( (int) $source['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_correspondence_booking_missing', __( 'The booking correspondence record is unavailable.', 'extrachill-events' ) );
		}
		$venue = get_term( (int) $booking['venue_term_id'], 'venue' );
		if ( ! $venue || is_wp_error( $venue ) ) {
			return new \WP_Error( 'booking_correspondence_venue_unavailable', __( 'The booking venue could not be resolved for correspondence.', 'extrachill-events' ) );
		}
		$message = sprintf(
			"We received your booking inquiry and it is pending review.\n\nArtist: %s\nVenue: %s\nRequested interval: %s to %s UTC\nRequested space: %s\nReference: %s\n\nSubmitting an inquiry does not place a hold or confirm the booking. Reply to this email to continue this booking thread.",
			$booking['artist_name'],
			$venue->name,
			$booking['requested_start_at'] ? $booking['requested_start_at'] : 'Not specified',
			$booking['requested_end_at'] ? $booking['requested_end_at'] : 'Not specified',
			$this->space_name( $booking, true ),
			$booking['public_id']
		);
		$result  = $this->communication->request_automatic( (int) $booking['id'], (int) $source['id'], 'inquiry_receipt', $message );
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
		$after      = is_array( $batch ) ? (int) ( $batch['payload']['data']['after_booking_id'] ?? 0 ) : 0;
		$candidates = $this->bookings->list_competing_requests( $confirmed, $after, self::BATCH_SIZE );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		foreach ( $candidates as $candidate ) {
			$current = $this->bookings->get( (int) $candidate['id'] );
			if ( ! is_array( $current ) || ! $this->still_competes( $current, $confirmed ) ) {
				continue;
			}
			$message = sprintf(
				'The interval you requested at %s (%s to %s UTC, %s) has been filled. Your inquiry has not been declined: reply to this email with another exact date, start/end time, and space you would like us to review.',
				$this->venue_name( $confirmed ),
				$current['requested_start_at'],
				$current['requested_end_at'],
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

	private function still_competes( array $candidate, array $confirmed ): bool {
		$terminal = array( 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' );
		return ! in_array( $candidate['status'], $terminal, true )
			&& (int) $candidate['venue_term_id'] === (int) $confirmed['venue_term_id']
			&& (string) $candidate['requested_space_key'] === (string) $confirmed['space_key']
			&& ! empty( $candidate['requested_start_at'] )
			&& ! empty( $candidate['requested_end_at'] )
			&& $candidate['requested_start_at'] < $confirmed['performance_end_at']
			&& $candidate['requested_end_at'] > $confirmed['performance_start_at'];
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
