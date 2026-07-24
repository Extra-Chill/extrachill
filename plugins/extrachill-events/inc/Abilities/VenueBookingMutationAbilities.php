<?php
/**
 * Authorized booking detail and deal mutation abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingMutationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the four focused operator mutation contracts. */
class VenueBookingMutationAbilities {
	private static bool $registered = false;
	/** @var BookingMutationService */
	private $mutations;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var VenueBookingAbilities */
	private $schemas;

	public function __construct( ?BookingMutationService $mutations = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null, ?VenueBookingAbilities $schemas = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->mutations     = $mutations ? $mutations : new BookingMutationService( $this->bookings, null, $this->authorization );
		$this->schemas       = $schemas ? $schemas : new VenueBookingAbilities( $this->bookings, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		$this->register_ability( 'extrachill/correct-venue-booking-intake', __( 'Correct Venue Booking Intake', 'extrachill-events' ), $this->intake_schema(), 'correct_intake' );
		$this->register_ability( 'extrachill/select-venue-booking-performance', __( 'Select Venue Booking Performance', 'extrachill-events' ), $this->performance_schema(), 'select_performance' );
		$this->register_ability( 'extrachill/update-venue-booking-production', __( 'Update Venue Booking Production', 'extrachill-events' ), $this->document_input( 'production', $this->schemas->production_document_schema() ), 'update_production' );
		$this->register_ability( 'extrachill/update-venue-booking-deal', __( 'Update Venue Booking Deal', 'extrachill-events' ), $this->document_input( 'deal', $this->schemas->deal_document_schema() ), 'update_deal' );
	}

	private function register_ability( string $name, string $label, array $input, string $callback ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => $this->schemas->booking_schema(),
				'execute_callback'    => array( $this, $callback ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => false,
						'destructive' => true,
					),
				),
			)
		);
	}

	public function correct_intake( array $input ) {
		$id      = (int) $input['booking_id'];
		$version = (int) $input['expected_version'];
		$before  = $this->bookings->get( $id );
		unset( $input['booking_id'], $input['expected_version'] );
		$result = $this->mutations->correct_intake( $id, $version, $input, get_current_user_id() );
		if ( is_array( $before ) && is_array( $result ) && strtolower( (string) $before['contact_email'] ) !== strtolower( (string) $result['contact_email'] ) ) {
			$suppressed = ( new \ExtraChillEvents\Core\BookingCommunicationService() )->suppress_pending_reminders( $id, 'booking_recipient_changed' );
			if ( is_wp_error( $suppressed ) ) {
				$suppressed->add_data( array_merge( (array) $suppressed->get_error_data(), array( 'booking_committed' => true ) ) );
				return $suppressed;
			}
		}
		return is_array( $result ) ? $this->schemas->present( $result ) : $result;
	}

	public function select_performance( array $input ) {
		$result = $this->mutations->select_performance( (int) $input['booking_id'], (int) $input['expected_version'], (string) $input['space_key'], (string) $input['start_at'], (string) $input['end_at'], get_current_user_id() );
		return is_array( $result ) ? $this->schemas->present( $result ) : $result;
	}

	public function update_production( array $input ) {
		$result = $this->mutations->update_production( (int) $input['booking_id'], (int) $input['expected_version'], $input['production'], get_current_user_id() );
		return is_array( $result ) ? $this->schemas->present( $result ) : $result;
	}

	public function update_deal( array $input ) {
		$result = $this->mutations->update_deal( (int) $input['booking_id'], (int) $input['expected_version'], $input['deal'], get_current_user_id() );
		return is_array( $result ) ? $this->schemas->present( $result ) : $result;
	}

	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking ) ? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function base_properties(): array {
		return array(
			'booking_id'       => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'expected_version' => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
	}

	private function intake_schema(): array {
		$properties = array_merge(
			$this->base_properties(),
			array(
				'contact_name'        => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 255,
				),
				'contact_email'       => array(
					'type'      => array( 'string', 'null' ),
					'format'    => 'email',
					'maxLength' => 255,
				),
				'contact_phone'       => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 64,
				),
				'requested_space_key' => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 64,
				),
				'requested_start_at'  => $this->datetime(),
				'requested_end_at'    => $this->datetime(),
				'intake'              => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			)
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'booking_id', 'expected_version' ),
			'minProperties'        => 3,
			'additionalProperties' => false,
		);
	}

	private function performance_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->base_properties(),
				array(
					'space_key' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 64,
					),
					'start_at'  => $this->datetime( false ),
					'end_at'    => $this->datetime( false ),
				)
			),
			'required'             => array( 'booking_id', 'expected_version', 'space_key', 'start_at', 'end_at' ),
			'additionalProperties' => false,
		);
	}

	private function document_input( string $key, array $schema ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge( $this->base_properties(), array( $key => $schema ) ),
			'required'             => array( 'booking_id', 'expected_version', $key ),
			'additionalProperties' => false,
		);
	}

	private function datetime( bool $nullable = true ): array {
		return array(
			'type'    => $nullable ? array( 'string', 'null' ) : 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
	}
}
