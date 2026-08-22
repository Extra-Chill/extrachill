<?php
/**
 * Headless venue booking abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingHoldRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers stable public inquiry and venue-operator booking contracts. */
class VenueBookingAbilities {

	/**
	 * Whether hooks were registered in this request.
	 *
	 * @var bool
	 */
	private static bool $registered = false;
	/**
	 * Booking read repository.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/**
	 * Booking mutation aggregate.
	 *
	 * @var BookingLifecycle
	 */
	private $lifecycle;
	/**
	 * Inquiry admission service, constructed only when an inquiry executes.
	 *
	 * @var BookingInquiryAdmissionService|null
	 */
	private $inquiry_admission;
	/**
	 * Exact venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;
	/** @var BookingHoldRepository */
	private $holds;
	private const CORE_ACTIVITY_KINDS = array(
		'inquiry_submitted',
		'status_changed',
		'artist_bound',
		'intake_corrected',
		'performance_selected',
		'hold_created',
		'hold_released',
		'hold_expired',
		'production_updated',
		'deal_draft_updated',
		'deal_confirmed',
		'event_conversion_started',
		'event_conversion_failed',
		'event_converted',
		'event_sync_started',
		'event_sync_retryable',
		'event_sync_failed',
		'event_sync_conflict',
		'event_sync_succeeded',
		'event_sync_noop',
		'booking_message_requested',
		'booking_message_dispatching',
		'booking_message_queued',
		'booking_message_failed',
		'booking_reminder_scheduling',
		'booking_reminder_scheduled',
		'booking_reminder_suppressed',
		'artist_correction_requested',
		'artist_cancellation_requested',
		'artist_withdrawn',
	);

	/**
	 * Build and hook the booking ability surface.
	 *
	 * @param BookingRepository|null              $bookings      Booking reads.
	 * @param BookingLifecycle|null               $lifecycle     Booking mutations.
	 * @param VenueAuthorization|null             $authorization Venue authorization.
	 * @param BookingHoldRepository|null          $holds             Hold reconciliation.
	 * @param BookingInquiryAdmissionService|null $inquiry_admission Inquiry admission.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingLifecycle $lifecycle = null, ?VenueAuthorization $authorization = null, ?BookingHoldRepository $holds = null, ?BookingInquiryAdmissionService $inquiry_admission = null ) {
		$this->bookings          = $bookings ? $bookings : new BookingRepository();
		$this->authorization     = $authorization ? $authorization : new VenueAuthorization();
		$this->holds             = $holds ? $holds : new BookingHoldRepository( $this->bookings, null, $this->authorization );
		$this->lifecycle         = $lifecycle ? $lifecycle : new BookingLifecycle( $this->bookings, null, $this->authorization, null, $this->holds );
		$this->inquiry_admission = $inquiry_admission;
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register public intake and authorized venue-operator operations. */
	public function register(): void {
		wp_register_ability(
			'extrachill/create-booking-inquiry',
			array(
				'label'               => __( 'Create Booking Inquiry', 'extrachill-events' ),
				'description'         => __( 'Submit an idempotent venue booking inquiry without creating a WordPress user.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->inquiry_schema(),
				'output_schema'       => $this->inquiry_receipt_schema(),
				'execute_callback'    => array( $this, 'create_inquiry' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/check-booking-availability',
			array(
				'label'               => __( 'Check Booking Availability', 'extrachill-events' ),
				'description'         => __( 'Return only whether a requested venue-space interval is filled by a confirmed booking or canonical published event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->availability_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'properties'           => array( 'available' => array( 'type' => 'boolean' ) ),
					'required'             => array( 'available' ),
					'additionalProperties' => false,
				),
				'execute_callback'    => array( $this, 'check_availability' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/list-venue-bookings',
			array(
				'label'               => __( 'List Venue Bookings', 'extrachill-events' ),
				'description'         => __( 'List bounded bookings for one authorized venue.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->list_schema(),
				'output_schema'       => array(
					'type'     => 'array',
					'maxItems' => 100,
					'items'    => $this->booking_schema(),
				),
				'execute_callback'    => array( $this, 'list_bookings' ),
				'permission_callback' => array( $this, 'can_access_venue' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		$booking_id_input = array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
		wp_register_ability(
			'extrachill/get-venue-booking',
			array(
				'label'               => __( 'Get Venue Booking', 'extrachill-events' ),
				'description'         => __( 'Get one booking within the operator\'s exact venue scope.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $booking_id_input,
				'output_schema'       => $this->booking_schema(),
				'execute_callback'    => array( $this, 'get_booking' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/get-venue-booking-activity',
			array(
				'label'               => __( 'Get Venue Booking Activity', 'extrachill-events' ),
				'description'         => __( 'Get the authoritative activity and event synchronization state for one authorized booking.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $booking_id_input,
				'output_schema'       => $this->activity_schema(),
				'execute_callback'    => array( $this, 'get_booking_activity' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => true,
						'idempotent'  => true,
						'destructive' => false,
					),
				),
			)
		);

		wp_register_ability(
			'extrachill/transition-venue-booking',
			array(
				'label'               => __( 'Transition Venue Booking', 'extrachill-events' ),
				'description'         => __( 'Apply an explicit booking lifecycle transition at an expected version.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'booking_id'       => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'to_status'        => array(
							'type' => 'string',
							'enum' => BookingLifecycle::STATUSES,
						),
						'expected_version' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'note'             => array(
							'type'      => array( 'string', 'null' ),
							'maxLength' => 1000,
						),
					),
					'required'             => array( 'booking_id', 'to_status', 'expected_version' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->booking_schema(),
				'execute_callback'    => array( $this, 'transition_booking' ),
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

		wp_register_ability(
			'extrachill/bind-venue-booking-artist',
			array(
				'label'               => __( 'Bind Venue Booking Artist', 'extrachill-events' ),
				'description'         => __( 'Bind an unresolved booking to existing canonical artist identities.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'booking_id'        => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'artist_term_id'    => array(
							'type'    => array( 'integer', 'null' ),
							'minimum' => 1,
						),
						'artist_profile_id' => array(
							'type'    => array( 'integer', 'null' ),
							'minimum' => 1,
						),
						'expected_version'  => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'booking_id', 'expected_version' ),
					'additionalProperties' => false,
				),
				'output_schema'       => $this->booking_schema(),
				'execute_callback'    => array( $this, 'bind_artist' ),
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

	/**
	 * Execute public inquiry creation.
	 *
	 * @param array $input Ability input.
	 */
	public function create_inquiry( array $input ) {
		if ( null === $this->inquiry_admission ) {
			$this->inquiry_admission = new BookingInquiryAdmissionService( $this->lifecycle );
		}
		$user_id = get_current_user_id();
		return $this->inquiry_admission->admit( $input, $user_id > 0 ? $user_id : null );
	}

	/**
	 * Return the privacy-safe public interval projection.
	 *
	 * @param array $input Exact venue interval input.
	 */
	public function check_availability( array $input ) {
		return $this->holds->public_interval_availability(
			(int) $input['venue_term_id'],
			(string) $input['requested_space_key'],
			(string) $input['requested_start_at'],
			(string) $input['requested_end_at']
		);
	}

	/**
	 * Execute one bounded venue booking list.
	 *
	 * @param array $input Ability input.
	 */
	public function list_bookings( array $input ) {
		$filters = array(
			'venue_term_id'      => $input['venue_term_id'],
			'status'             => $input['status'] ?? null,
			'requested_start_at' => $input['requested_from'] ?? null,
			'requested_end_at'   => $input['requested_to'] ?? null,
			'range_start'        => $input['range_start'] ?? null,
			'range_end'          => $input['range_end'] ?? null,
			'limit'              => $input['limit'] ?? 50,
			'offset'             => $input['offset'] ?? 0,
		);
		$result  = $this->holds->list_bookings_authorized( $filters, get_current_user_id() );
		if ( ! is_array( $result ) ) {
			return $result;
		}
		return array_map( array( $this, 'present' ), $result );
	}

	/**
	 * Execute one venue booking read.
	 *
	 * @param array $input Ability input.
	 */
	public function get_booking( array $input ) {
		$result = $this->holds->get_booking_authorized( absint( $input['booking_id'] ?? 0 ), get_current_user_id() );
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Get sanitized activity plus canonical conversion and synchronization state.
	 *
	 * @param array $input Ability input.
	 */
	public function get_booking_activity( array $input ) {
		$state = $this->holds->get_booking_activity_authorized( absint( $input['booking_id'] ?? 0 ), get_current_user_id(), self::CORE_ACTIVITY_KINDS );
		if ( ! is_array( $state ) ) {
			return $state;
		}

		return array(
			'activity'   => array_map( array( $this, 'present_activity' ), $state['activity'] ),
			'conversion' => array(
				'status'       => $state['conversion']['status'],
				'attempt'      => (int) $state['conversion']['attempt'],
				'failure_code' => $state['conversion']['failed']['payload']['data']['upstream_code'] ?? null,
				'retryable'    => (bool) ( $state['conversion']['failed']['payload']['data']['retryable'] ?? false ),
			),
			'sync'       => $this->present_sync_state( $state['sync'] ),
		);
	}

	/**
	 * Execute an optimistic lifecycle transition.
	 *
	 * @param array $input Ability input.
	 */
	public function transition_booking( array $input ) {
		$before = $this->bookings->get( (int) $input['booking_id'] );
		if ( is_array( $before ) && null !== $before['event_id'] && 'cancelled' === (string) $input['to_status'] ) {
			$sync_ability = wp_get_ability( 'extrachill/reconcile-booking-event' );
			if ( ! $sync_ability ) {
				return new \WP_Error(
					'booking_event_sync_unavailable',
					__( 'Booking event synchronization is unavailable.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
					)
				);
			}
			$result = $sync_ability->execute(
				array(
					'booking_id'       => (int) $input['booking_id'],
					'expected_version' => (int) $input['expected_version'],
					'changes'          => array( 'cancelled' => true ),
				)
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$suppressed = ( new \ExtraChillEvents\Core\BookingCommunicationService() )->suppress_pending_reminders( (int) $input['booking_id'], 'booking_status_changed' );
			if ( is_wp_error( $suppressed ) ) {
				$suppressed->add_data( array_merge( (array) $suppressed->get_error_data(), array( 'booking_committed' => true ) ) );
				return $suppressed;
			}
			$current = $this->bookings->get( (int) $input['booking_id'] );
			return is_array( $current ) ? $this->present( $current ) : $current;
		}
		$result = $this->lifecycle->transition( (int) $input['booking_id'], (string) $input['to_status'], (int) $input['expected_version'], get_current_user_id(), $input['note'] ?? null );
		if ( is_array( $before ) && is_array( $result ) && $before['status'] !== $result['status'] ) {
			$suppressed = ( new \ExtraChillEvents\Core\BookingCommunicationService() )->suppress_pending_reminders( (int) $input['booking_id'], 'booking_status_changed' );
			if ( is_wp_error( $suppressed ) ) {
				$suppressed->add_data( array_merge( (array) $suppressed->get_error_data(), array( 'booking_committed' => true ) ) );
				return $suppressed;
			}
		}
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Execute an optimistic existing-artist binding.
	 *
	 * @param array $input Ability input.
	 */
	public function bind_artist( array $input ) {
		$result = $this->lifecycle->bind_artist( (int) $input['booking_id'], $input['artist_term_id'] ?? null, $input['artist_profile_id'] ?? null, (int) $input['expected_version'], get_current_user_id() );
		return is_array( $result ) ? $this->present( $result ) : $result;
	}

	/**
	 * Authorize a venue supplied directly by a bounded list request.
	 *
	 * @param array $input Ability input.
	 */
	public function can_access_venue( array $input ) {
		return $this->authorization->authorize( get_current_user_id(), absint( $input['venue_term_id'] ?? 0 ), VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/**
	 * Resolve the booking internally, then authorize its venue without disclosure.
	 *
	 * @param array $input Ability input.
	 */
	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		if ( ! is_array( $booking ) ) {
			return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	/** Return the strict public inquiry input schema. */
	private function inquiry_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'idempotency_key'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 191,
				),
				'venue_term_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'artist_term_id'      => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'artist_profile_id'   => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'artist_name'         => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 255,
				),
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
				'requested_start_at'  => $this->nullable_datetime_schema(),
				'requested_end_at'    => $this->nullable_datetime_schema(),
				'intake'              => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'attachments'         => array(
					'type'     => 'array',
					'maxItems' => BookingInquiryAdmissionService::MAX_FILES,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'     => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 255,
							),
							'tmp_name' => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 4096,
							),
							'error'    => array( 'type' => 'integer' ),
							'size'     => array(
								'type'    => 'integer',
								'minimum' => 1,
								'maximum' => BookingAttachmentPolicy::MAX_BYTES,
							),
							'purpose'  => array(
								'type' => 'string',
								'enum' => BookingAttachmentPolicy::PURPOSES,
							),
						),
						'required'             => array( 'name', 'tmp_name', 'error', 'size', 'purpose' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'idempotency_key', 'venue_term_id', 'intake' ),
			'additionalProperties' => false,
		);
	}

	/** Return the exact local venue interval accepted by the public facade. */
	private function availability_schema(): array {
		$datetime = array(
			'type'    => 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_id'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'requested_space_key' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 64,
				),
				'requested_start_at'  => $datetime,
				'requested_end_at'    => $datetime,
			),
			'required'             => array( 'venue_term_id', 'requested_space_key', 'requested_start_at', 'requested_end_at' ),
			'additionalProperties' => false,
		);
	}

	/** Return the immutable public inquiry receipt schema. */
	private function inquiry_receipt_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'submitted_at'  => array( 'type' => 'string' ),
				'capability'    => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			'required'             => array( 'public_id', 'venue_term_id', 'submitted_at' ),
			'additionalProperties' => false,
		);
	}

	/** Return the bounded operator-list input schema. */
	private function list_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'status'         => array(
					'type' => array( 'string', 'null' ),
					'enum' => array_merge( BookingLifecycle::STATUSES, array( null ) ),
				),
				'requested_from' => $this->nullable_datetime_schema(),
				'requested_to'   => $this->nullable_datetime_schema(),
				'range_start'    => $this->nullable_datetime_schema(),
				'range_end'      => $this->nullable_datetime_schema(),
				'limit'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 100,
				),
				'offset'         => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
			),
			'required'             => array( 'venue_term_id' ),
			'additionalProperties' => false,
		);
	}

	/** Return the stable hydrated booking output schema. */
	public function booking_schema(): array {
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
				'public_id'            => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'artist_term_id'       => $nullable_id,
				'artist_profile_id'    => $nullable_id,
				'artist_name'          => array( 'type' => 'string' ),
				'submitter_user_id'    => $nullable_id,
				'contact_name'         => $nullable_string,
				'contact_email'        => $nullable_string,
				'contact_phone'        => $nullable_string,
				'requested_space_key'  => $nullable_string,
				'space_key'            => $nullable_string,
				'status'               => array(
					'type' => 'string',
					'enum' => BookingLifecycle::STATUSES,
				),
				'version'              => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'requested_start_at'   => $this->nullable_datetime_schema(),
				'requested_end_at'     => $this->nullable_datetime_schema(),
				'performance_start_at' => $this->nullable_datetime_schema(),
				'performance_end_at'   => $this->nullable_datetime_schema(),
				'intake'               => $this->payload_schema( false ),
				'production'           => $this->payload_schema( true, $this->production_document_schema() ),
				'deal'                 => $this->payload_schema( true, $this->deal_document_schema() ),
				'confirmed_deal'       => $this->payload_schema( true, $this->deal_document_schema() ),
				'event_id'             => $nullable_id,
				'created_at'           => array( 'type' => 'string' ),
				'updated_at'           => array( 'type' => 'string' ),
			),
			'required'             => array( 'id', 'public_id', 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'artist_name', 'submitter_user_id', 'contact_name', 'contact_email', 'contact_phone', 'requested_space_key', 'space_key', 'status', 'version', 'requested_start_at', 'requested_end_at', 'performance_start_at', 'performance_end_at', 'intake', 'production', 'deal', 'confirmed_deal', 'event_id', 'created_at', 'updated_at' ),
			'additionalProperties' => false,
		);
	}

	/** Return the bounded operator activity projection schema. */
	private function activity_schema(): array {
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'activity'   => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'                    => array( 'type' => 'integer' ),
							'kind'                  => array( 'type' => 'string' ),
							'occurred_at'           => array( 'type' => 'string' ),
							'artist_request_detail' => array(
								'type'      => 'string',
								'maxLength' => 2000,
							),
						),
						'required'             => array( 'id', 'kind', 'occurred_at' ),
						'additionalProperties' => false,
					),
				),
				'conversion' => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'       => array(
							'type' => 'string',
							'enum' => array( 'none', 'pending', 'failed', 'completed' ),
						),
						'attempt'      => array(
							'type'    => 'integer',
							'minimum' => 0,
						),
						'failure_code' => $nullable_string,
						'retryable'    => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'status', 'attempt', 'failure_code', 'retryable' ),
					'additionalProperties' => false,
				),
				'sync'       => array(
					'type'                 => 'object',
					'properties'           => array(
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'none', 'pending', 'succeeded', 'no_change', 'conflict', 'failed', 'retryable' ),
						),
						'code'      => $nullable_string,
						'retryable' => array( 'type' => 'boolean' ),
					),
					'required'             => array( 'status', 'code', 'retryable' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'activity', 'conversion', 'sync' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Remove activity payloads outside this console's operational scope.
	 *
	 * @param array $activity Hydrated activity record.
	 */
	public function present_activity( array $activity ): array {
		$presented = array(
			'id'          => $activity['id'],
			'kind'        => $activity['kind'],
			'occurred_at' => $activity['occurred_at'],
		);
		if ( 'artist_correction_requested' === $activity['kind'] ) {
			$detail = $activity['payload']['data']['correction'] ?? null;
			if ( is_string( $detail ) && '' !== $detail ) {
				$presented['artist_request_detail'] = mb_substr( $detail, 0, 2000 );
			}
		}
		return $presented;
	}

	/**
	 * Present the durable latest synchronization state without private payload data.
	 *
	 * @param array $sync Canonical event synchronization state.
	 */
	private function present_sync_state( array $sync ): array {
		$terminal = $sync['terminal'] ?? null;
		if ( is_array( $terminal ) ) {
			$status = str_replace( 'event_sync_', '', $terminal['kind'] );
			if ( 'noop' === $status ) {
				$status = 'no_change';
			}
			return array(
				'status'    => $status,
				'code'      => $terminal['payload']['data']['code'] ?? null,
				'retryable' => (bool) ( $terminal['payload']['data']['retryable'] ?? false ),
			);
		}
		if ( is_array( $sync['retry'] ?? null ) ) {
			return array(
				'status'    => 'retryable',
				'code'      => $sync['retry']['payload']['data']['code'] ?? null,
				'retryable' => true,
			);
		}
		return array(
			'status'    => ! empty( $sync['pending'] ) ? 'pending' : 'none',
			'code'      => null,
			'retryable' => false,
		);
	}

	/**
	 * Return a versioned JSON payload envelope schema.
	 *
	 * @param bool $nullable Whether null is accepted.
	 */
	private function payload_schema( bool $nullable, ?array $data_schema = null ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'version' => array(
					'type' => 'integer',
					'enum' => array( 1 ),
				),
				'data'    => $data_schema ? $data_schema : array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'version', 'data' ),
			'additionalProperties' => false,
		);
		if ( $nullable ) {
			$schema['type'] = array( 'object', 'null' );
		}
		return $schema;
	}

	/** Strict production data-document schema shared by mutations and output. */
	public function production_document_schema(): array {
		$list = array(
			'type'     => 'array',
			'maxItems' => 50,
			'items'    => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 500,
			),
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version'              => array(
					'type' => 'integer',
					'enum' => array( 1 ),
				),
				'support_requirements' => $list,
				'support_offers'       => $list,
				'production_notes'     => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 10000,
				),
			),
			'required'             => array( 'version', 'support_requirements', 'support_offers', 'production_notes' ),
			'additionalProperties' => false,
		);
	}

	/** Strict complete draft/confirmed deal data-document schema. */
	public function deal_document_schema(): array {
		$nullable_money = array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 0,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'version'                    => array(
					'type' => 'integer',
					'enum' => array( 1 ),
				),
				'type'                       => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 32,
				),
				'guarantee_cents'            => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'revenue_share_basis_points' => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'revenue_share_basis'        => array(
					'type' => 'string',
					'enum' => array( 'gross_ticket_sales', 'net_ticket_sales', 'door_receipts' ),
				),
				'currency'                   => array(
					'type'    => 'string',
					'pattern' => '^[A-Z]{3}$',
				),
				'capacity'                   => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'advance_ticket_price_cents' => $nullable_money,
				'door_ticket_price_cents'    => $nullable_money,
				'ticket_fee_cents'           => $nullable_money,
				'tickets_on_sale_at'         => $this->nullable_datetime_schema(),
				'ticket_url'                 => array(
					'type'      => array( 'string', 'null' ),
					'format'    => 'uri',
					'maxLength' => 2048,
				),
				'additional_terms'           => array(
					'type'      => array( 'string', 'null' ),
					'maxLength' => 10000,
				),
			),
			'required'             => array( 'version', 'type', 'guarantee_cents', 'revenue_share_basis_points', 'revenue_share_basis', 'currency', 'capacity', 'advance_ticket_price_cents', 'door_ticket_price_cents', 'ticket_fee_cents', 'tickets_on_sale_at', 'ticket_url', 'additional_terms' ),
			'additionalProperties' => false,
		);
	}

	/** Return the UTC database datetime schema. */
	private function nullable_datetime_schema(): array {
		return array(
			'type'    => array( 'string', 'null' ),
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
	}

	/**
	 * Remove storage-only idempotency material from the public shape.
	 *
	 * @param array $booking Hydrated storage record.
	 */
	public function present( array $booking ): array {
		unset( $booking['inquiry_idempotency_key'], $booking['inquiry_request_hash'], $booking['admission_owner_token'] );
		return $booking;
	}

	/** Present only immutable public submission fields. */
	private function present_receipt( array $booking ): array {
		return array(
			'public_id'     => $booking['public_id'],
			'venue_term_id' => $booking['venue_term_id'],
			'submitted_at'  => $booking['created_at'],
		);
	}
}
