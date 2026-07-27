<?php
/**
 * Venue booking event conversion ability.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingEventConversionService;
use ExtraChillEvents\Core\BookingEventSyncService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the narrow idempotent booking-to-event contract. */
class VenueBookingEventAbilities {
	private static bool $registered = false;
	/** @var BookingEventConversionService */
	private $conversion;
	/** @var BookingEventSyncService */
	private $sync;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var array|null Exact booking context active during nested event upsert. */
	private $active_conversion;

	public function __construct( ?BookingEventConversionService $conversion = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null, ?BookingEventSyncService $sync = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->conversion    = $conversion ? $conversion : new BookingEventConversionService( $this->bookings, null, null, $this->authorization );
		$this->sync          = $sync ? $sync : new BookingEventSyncService( $this->bookings, null, $this->authorization );
		add_filter( 'datamachine_events_upsert_event_permission', array( $this, 'can_upsert_booking_event' ), 10, 2 );
		add_filter( 'extrachill_events_canonical_event_excluded_booking_id', array( $this, 'excluded_booking_id_for_active_conversion' ), 10, 3 );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/convert-booking-to-event',
			array(
				'label'               => __( 'Convert Booking to Event', 'extrachill-events' ),
				'description'         => __( 'Idempotently converts a confirmed venue booking into its canonical event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
		wp_register_ability(
			'extrachill/reconcile-booking-event',
			array(
				'label'               => __( 'Reconcile Booking Event', 'extrachill-events' ),
				'description'         => __( 'Applies approved booking-authoritative corrections and idempotently reconciles the linked canonical event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->sync_input_schema(),
				'output_schema'       => $this->sync_output_schema(),
				'execute_callback'    => array( $this, 'reconcile' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => true,
						'idempotent'  => true,
					),
				),
			)
		);
	}

	public function execute( array $input ) {
		$booking                 = $this->bookings->get( (int) $input['booking_id'] );
		$this->active_conversion = is_array( $booking ) ? $booking : null;
		try {
			return $this->conversion->convert( (int) $input['booking_id'], (int) $input['expected_version'], get_current_user_id() );
		} finally {
			$this->active_conversion = null;
		}
	}

	/** Execute the bounded public reconciliation/operator entrypoint. */
	public function reconcile( array $input ) {
		return $this->sync->reconcile( (int) $input['booking_id'], (int) $input['expected_version'], get_current_user_id(), (array) ( $input['changes'] ?? array() ) );
	}

	/** Grant only the exact nested DME write performed by this conversion. */
	public function can_upsert_booking_event( bool $allowed, array $input ): bool {
		if ( $allowed ) {
			return true;
		}
		if ( ! is_array( $this->active_conversion )
			|| BookingEventConversionService::SOURCE !== ( $input['source'] ?? '' )
			|| ( $input['source_id'] ?? '' ) !== $this->active_conversion['public_id'] ) {
			return false;
		}

		return true === $this->authorization->authorize(
			get_current_user_id(),
			(int) $this->active_conversion['venue_term_id'],
			VenueAuthorization::ACTION_ACCESS_VENUE
		);
	}

	/** Exempt only the exact booking in the active authorized conversion wrapper. */
	public function excluded_booking_id_for_active_conversion( int $booking_id, array $input, array $publication ): int {
		unset( $booking_id );
		if ( ! is_array( $this->active_conversion ) ) {
			return 0;
		}
		$candidate_match     = false;
		$candidate_intervals = (array) ( $publication['_candidate_intervals'] ?? array() );
		if ( empty( $candidate_intervals ) && isset( $publication['start_at'], $publication['end_at'] ) ) {
			$candidate_intervals[] = array(
				'start_at' => $publication['start_at'],
				'end_at'   => $publication['end_at'],
			);
		}
		foreach ( $candidate_intervals as $interval ) {
			if ( ( $interval['start_at'] ?? '' ) === $this->active_conversion['performance_start_at']
				&& ( $interval['end_at'] ?? '' ) === $this->active_conversion['performance_end_at'] ) {
				$candidate_match = true;
				break;
			}
		}
		if ( BookingEventConversionService::SOURCE !== ( $input['source'] ?? '' )
			|| ( $input['source_id'] ?? '' ) !== $this->active_conversion['public_id']
			|| (int) ( $publication['venue_id'] ?? 0 ) !== (int) $this->active_conversion['venue_term_id']
			|| ! $candidate_match ) {
			return 0;
		}

		return (int) $this->active_conversion['id'];
	}

	/** Missing records deliberately use the same denial as inaccessible records. */
	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking ) ? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id', 'expected_version' ),
			'additionalProperties' => false,
		);
	}

	private function output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'        => array( 'type' => 'integer' ),
				'booking_version'   => array( 'type' => 'integer' ),
				'event_id'          => array( 'type' => 'integer' ),
				'event_url'         => array( 'type' => 'string' ),
				'event_action'      => array(
					'type' => 'string',
					'enum' => array( 'created', 'updated', 'no_change', 'existing' ),
				),
				'already_converted' => array( 'type' => 'boolean' ),
			),
			'required'             => array( 'booking_id', 'booking_version', 'event_id', 'event_url', 'event_action', 'already_converted' ),
			'additionalProperties' => false,
		);
	}

	private function sync_input_schema(): array {
		$datetime = array(
			'type'    => 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'changes'          => array(
					'type'                 => 'object',
					'properties'           => array(
						'venue_term_id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'space_key'            => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 64,
						),
						'performance_start_at' => $datetime,
						'performance_end_at'   => $datetime,
						'performer'            => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 255,
						),
						'ticket_url'           => array(
							'type'   => array( 'string', 'null' ),
							'format' => 'uri',
						),
						'cancelled'            => array( 'type' => 'boolean' ),
					),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'booking_id', 'expected_version' ),
			'additionalProperties' => false,
		);
	}

	private function sync_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'      => array( 'type' => 'integer' ),
				'booking_version' => array( 'type' => 'integer' ),
				'event_id'        => array( 'type' => 'integer' ),
				'status'          => array(
					'type' => 'string',
					'enum' => array( 'succeeded', 'no_change' ),
				),
				'code'            => array( 'type' => 'string' ),
				'retryable'       => array( 'type' => 'boolean' ),
				'conflicts'       => array(
					'type'     => 'array',
					'maxItems' => 20,
				),
			),
			'required'             => array( 'booking_id', 'booking_version', 'event_id', 'status', 'code', 'retryable', 'conflicts' ),
			'additionalProperties' => false,
		);
	}
}
