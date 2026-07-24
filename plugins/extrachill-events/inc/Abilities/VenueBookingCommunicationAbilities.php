<?php
/**
 * Booking communication abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingCommunicationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers bounded outbound booking message and read contracts. */
class VenueBookingCommunicationAbilities {

	private static bool $registered = false;
	/** @var BookingCommunicationService */
	private $communications;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?BookingCommunicationService $communications = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null ) {
		$this->authorization  = $authorization ? $authorization : new VenueAuthorization();
		$this->bookings       = $bookings ? $bookings : new BookingRepository();
		$this->communications = $communications ? $communications : new BookingCommunicationService( $this->bookings, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			add_filter( 'datamachine_pending_action_handlers', array( $this, 'pending_action_handlers' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/send-booking-message',
			array(
				'label'               => __( 'Send Booking Message', 'extrachill-events' ),
				'description'         => __( 'Record and delegate an immediate booking message or lifecycle-aware reminder.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->message_input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => array( $this, 'send' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => $this->meta( false, true ),
			)
		);
		wp_register_ability(
			'extrachill/list-booking-communications',
			array(
				'label'               => __( 'List Booking Communications', 'extrachill-events' ),
				'description'         => __( 'Read the authorized durable correspondence ledger for one booking.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->booking_id_schema(),
				'output_schema'       => array(
					'type'     => 'array',
					'maxItems' => 200,
					'items'    => $this->communication_output_schema(),
				),
				'execute_callback'    => array( $this, 'list_communications' ),
				'permission_callback' => array( $this, 'can_access_booking' ),
				'meta'                => $this->meta( true, true ),
			)
		);
		wp_register_ability(
			'extrachill/reconcile-booking-message',
			array(
				'label'               => __( 'Reconcile Booking Message', 'extrachill-events' ),
				'description'         => __( 'Recover a missing queued or scheduled receipt from exact Action Scheduler evidence without claiming delivery.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->intent_id_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => array( $this, 'reconcile' ),
				'permission_callback' => array( $this, 'can_access_intent' ),
				'meta'                => $this->meta( false, true ),
			)
		);
	}

	public function send( array $input ) {
		$approval = $input['approval'] ?? 'direct';
		unset( $input['approval'] );
		if ( 'required' === $approval ) {
			if ( ! class_exists( '\DataMachine\Engine\AI\Actions\PendingActionHelper' ) ) {
				return new \WP_Error( 'booking_message_approval_unavailable', __( 'The approval substrate is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			return \DataMachine\Engine\AI\Actions\PendingActionHelper::stage(
				array(
					'kind'          => 'extrachill_send_booking_message',
					'summary'       => sprintf( 'Send booking email to %s.', $input['recipient'] ),
					'apply_input'   => $input,
					'preview_data'  => array(
						'booking_id' => $input['booking_id'],
						'recipient'  => $input['recipient'],
						'subject'    => $input['subject'],
						'message'    => $input['message'],
						'send_at'    => $input['send_at'] ?? null,
					),
					'user_id'       => get_current_user_id(),
					'authorization' => array(
						'operation' => 'send_booking_message',
						'target'    => array( 'booking_id' => $input['booking_id'] ),
					),
				)
			);
		}
		return $this->communications->request( $input, get_current_user_id() );
	}

	public function list_communications( array $input ) {
		return $this->communications->list_for_booking( (int) $input['booking_id'], get_current_user_id() );
	}

	public function reconcile( array $input ) {
		return $this->communications->reconcile( (int) $input['intent_id'], get_current_user_id() );
	}

	/** Register replay and fresh authorization for generic Data Machine approval. */
	public function pending_action_handlers( $handlers ) {
		$handlers                                    = is_array( $handlers ) ? $handlers : array();
		$handlers['extrachill_send_booking_message'] = array(
			'apply'       => function ( array $input ) {
				return $this->communications->request( $input, get_current_user_id() );
			},
			'can_resolve' => function ( array $payload, string $decision, int $user_id ) {
				unset( $decision );
				$input   = is_array( $payload['apply_input'] ?? null ) ? $payload['apply_input'] : array();
				$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
				return is_array( $booking ) ? $this->authorization->authorize( $user_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : new \WP_Error( 'venue_action_forbidden' );
			},
		);
		return $handlers;
	}

	public function can_access_booking( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking ) ? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	public function can_access_intent( array $input ) {
		$intent = ( new \ExtraChillEvents\Core\BookingActivityRepository() )->get( absint( $input['intent_id'] ?? 0 ) );
		return is_array( $intent ) && 'booking_message_requested' === $intent['kind']
			? $this->can_access_booking( array( 'booking_id' => $intent['booking_id'] ) )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function message_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'idempotency_key'   => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 120,
				),
				'template'          => array(
					'type' => 'string',
					'enum' => BookingCommunicationService::TEMPLATES,
				),
				'recipient'         => array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 255,
				),
				'subject'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'message'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 10000,
				),
				'reply_to'          => array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 255,
				),
				'send_at'           => array(
					'type'    => array( 'string', 'null' ),
					'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
				),
				'expected_statuses' => array(
					'type'        => 'array',
					'uniqueItems' => true,
					'items'       => array(
						'type' => 'string',
						'enum' => BookingRepository::STATUSES,
					),
				),
				'approval'          => array(
					'type'    => 'string',
					'enum'    => array( 'direct', 'required' ),
					'default' => 'direct',
				),
			),
			'required'             => array( 'booking_id', 'idempotency_key', 'template', 'recipient', 'subject', 'message', 'reply_to' ),
			'additionalProperties' => false,
		);
	}

	private function communication_output_schema(): array {
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'activity_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'booking_id'  => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'kind'        => array( 'type' => 'string' ),
				'direction'   => array(
					'type' => 'string',
					'enum' => array( 'outbound' ),
				),
				'channel'     => array(
					'type' => 'string',
					'enum' => array( 'email' ),
				),
				'occurred_at' => array( 'type' => 'string' ),
				'message'     => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'template'  => array(
							'type' => 'string',
							'enum' => BookingCommunicationService::TEMPLATES,
						),
						'recipient' => array(
							'type'   => 'string',
							'format' => 'email',
						),
						'subject'   => array( 'type' => 'string' ),
						'message'   => array( 'type' => 'string' ),
						'reply_to'  => array(
							'type'   => 'string',
							'format' => 'email',
						),
						'send_at'   => $nullable_string,
					),
					'required'             => array( 'template', 'recipient', 'subject', 'message', 'reply_to', 'send_at' ),
					'additionalProperties' => false,
				),
				'state'       => array(
					'type'                 => array( 'object', 'null' ),
					'properties'           => array(
						'intent_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'status'    => array( 'type' => 'string' ),
						'reason'    => $nullable_string,
					),
					'required'             => array( 'intent_id', 'status', 'reason' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'activity_id', 'booking_id', 'kind', 'direction', 'channel', 'occurred_at', 'message', 'state' ),
			'additionalProperties' => false,
		);
	}

	private function booking_id_schema(): array {
		return array(
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
	}

	private function intent_id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'intent_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			'required'             => array( 'intent_id' ),
			'additionalProperties' => false,
		);
	}

	private function meta( bool $is_readonly, bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => $is_readonly,
				'idempotent'  => $idempotent,
				'destructive' => ! $is_readonly,
			),
		);
	}
}
