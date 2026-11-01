<?php
/**
 * Authorized venue booking hold abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers create, release, and bounded venue hold operations. */
class VenueBookingHoldAbilities {

	private static bool $registered = false;
	/** @var BookingHoldRepository */
	private $holds;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?BookingHoldRepository $holds = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->holds         = $holds ? $holds : new BookingHoldRepository( $this->bookings, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/create-booking-hold',
			array(
				'label'               => __( 'Create Booking Hold', 'extrachill-events' ),
				'description'         => __( 'Create a venue-space hold from a booking persisted selection.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->create_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'hold'            => $this->hold_schema(),
						'booking_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'hold', 'booking_version' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => $this->meta( false ),
			)
		);
		wp_register_ability(
			'extrachill/release-booking-hold',
			array(
				'label'               => __( 'Release Booking Hold', 'extrachill-events' ),
				'description'         => __( 'Release an active booking hold at an expected hold version.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->release_schema(),
				'output_schema'       => $this->hold_schema(),
				'execute_callback'    => array( $this, 'release' ),
				'permission_callback' => array( $this, 'can_access_hold' ),
				'meta'                => $this->meta( false ),
			)
		);
		wp_register_ability(
			'extrachill/list-booking-holds',
			array(
				'label'               => __( 'List Booking Holds', 'extrachill-events' ),
				'description'         => __( 'List bounded booking holds for one authorized venue.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->list_schema(),
				'output_schema'       => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => $this->hold_schema(),
				),
				'execute_callback'    => array( $this, 'list_holds' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => $this->meta( true ),
			)
		);
	}

	public function create( array $input ) {
		return $this->holds->create( (int) $input['booking_id'], (int) $input['expected_booking_version'], get_current_user_id() );
	}

	public function release( array $input ) {
		return $this->holds->release( (int) $input['hold_id'], (int) $input['expected_version'], get_current_user_id(), (string) $input['reason'] );
	}

	public function list_holds( array $input ) {
		return $this->holds->list( $input, get_current_user_id() );
	}

	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking )
			? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	public function can_access_hold( array $input ) {
		$hold = $this->holds->get( absint( $input['hold_id'] ?? 0 ) );
		return is_array( $hold )
			? $this->authorization->authorize( get_current_user_id(), $hold['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	public function can_access_venue( array $input ) {
		return $this->authorization->authorize( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	private function create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'               => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_booking_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id', 'expected_booking_version' ),
			'additionalProperties' => false,
		);
	}

	private function release_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'hold_id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'expected_version' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'reason'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
			),
			'required'             => array( 'hold_id', 'expected_version', 'reason' ),
			'additionalProperties' => false,
		);
	}

	private function list_schema(): array {
		$datetime = array(
			'type'    => array( 'string', 'null' ),
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'booking_id'    => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'status'        => array(
					'type' => array( 'string', 'null' ),
					'enum' => array_merge( BookingHoldRepository::STATUSES, array( null ) ),
				),
				'range_start'   => $datetime,
				'range_end'     => $datetime,
				'limit'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'offset'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
			),
			'required'             => array( 'venue_term_id' ),
			'additionalProperties' => false,
		);
	}

	private function hold_schema(): array {
		$nullable_id     = array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 1,
		);
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'booking_id'           => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'venue_term_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'space_key'            => array( 'type' => 'string' ),
				'start_at'             => array( 'type' => 'string' ),
				'end_at'               => array( 'type' => 'string' ),
				'expires_at'           => array( 'type' => 'string' ),
				'status'               => array(
					'type' => 'string',
					'enum' => BookingHoldRepository::STATUSES,
				),
				'version'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'created_by_user_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'created_at'           => array( 'type' => 'string' ),
				'updated_at'           => array( 'type' => 'string' ),
				'released_at'          => $nullable_string,
				'released_by_user_id'  => $nullable_id,
				'release_reason'       => $nullable_string,
				'expired_at'           => $nullable_string,
				'converted_at'         => $nullable_string,
				'converted_by_user_id' => $nullable_id,
			),
			'required'             => array( 'id', 'booking_id', 'venue_term_id', 'space_key', 'start_at', 'end_at', 'expires_at', 'status', 'version', 'created_by_user_id', 'created_at', 'updated_at', 'released_at', 'released_by_user_id', 'release_reason', 'expired_at', 'converted_at', 'converted_by_user_id' ),
			'additionalProperties' => false,
		);
	}

	private function meta( bool $is_readonly ): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => $is_readonly,
				'idempotent'  => $is_readonly,
				'destructive' => ! $is_readonly,
			),
		);
	}
}
