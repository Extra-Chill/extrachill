<?php
/**
 * Hidden artist booking inquiry abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\ArtistBookingInquiryService;
use ExtraChillEvents\Core\BookingRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers route-affine artist inquiry domain operations. */
final class ArtistBookingInquiryAbilities {

	/** @var ArtistBookingInquiryService */
	private $service;

	public function __construct( ?ArtistBookingInquiryService $service = null ) {
		$this->service = $service ? $service : new ArtistBookingInquiryService();
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	public function register(): void {
		$this->register_ability( 'get-artist-booking-inquiry', __( 'Get Artist Booking Inquiry', 'extrachill-events' ), $this->access_schema(), $this->status_output_schema(), array( $this, 'status' ), true, false );
		$this->register_ability( 'request-artist-booking-correction', __( 'Request Artist Booking Correction', 'extrachill-events' ), $this->mutation_schema( true ), $this->correction_output_schema(), array( $this, 'request_correction' ), false, false );
		$this->register_ability( 'withdraw-artist-booking-inquiry', __( 'Withdraw Artist Booking Inquiry', 'extrachill-events' ), $this->mutation_schema( false ), $this->withdrawal_output_schema(), array( $this, 'withdraw' ), false, true );
		$this->register_ability( 'recover-artist-booking-inquiry-receipt', __( 'Recover Artist Booking Inquiry Receipt', 'extrachill-events' ), $this->recovery_schema(), $this->recovery_output_schema(), array( $this, 'recover_receipt' ), false, false );
	}

	public function status( array $input ) {
		return $this->service->status( (string) $input['public_id'], (int) $input['venue_term_id'], (string) ( $input['capability'] ?? '' ), get_current_user_id() );
	}

	public function request_correction( array $input ) {
		return $this->service->request_correction( (string) $input['public_id'], (int) $input['venue_term_id'], (string) ( $input['capability'] ?? '' ), get_current_user_id(), (int) $input['expected_version'], (string) $input['idempotency_key'], (string) $input['correction'] );
	}

	public function withdraw( array $input ) {
		return $this->service->withdraw( (string) $input['public_id'], (int) $input['venue_term_id'], (string) ( $input['capability'] ?? '' ), get_current_user_id(), (int) $input['expected_version'], (string) $input['idempotency_key'] );
	}

	/** Verify claimed contact control against the persisted booking recipient. */
	public function recover_receipt( array $input ) {
		return $this->service->resend_receipt( (string) $input['public_id'], (int) $input['venue_term_id'], (string) $input['contact_email'], (string) $input['idempotency_key'] );
	}

	private function register_ability( string $slug, string $label, array $input, array $output, callable $callback, bool $read_only, bool $destructive ): void {
		wp_register_ability(
			'extrachill-events/' . $slug,
			array(
				'label'               => $label,
				'description'         => __( 'Artist-authorized booking inquiry follow-through.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $callback,
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => $read_only,
						'idempotent'  => true,
						'destructive' => $destructive,
					),
				),
			)
		);
	}

	private function access_schema(): array {
		return $this->object_schema(
			array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id' => $this->venue_id_schema(),
				'capability'    => array(
					'type'      => 'string',
					'minLength' => 64,
					'maxLength' => 64,
				),
			),
			array( 'public_id', 'venue_term_id' )
		);
	}

	private function mutation_schema( bool $correction ): array {
		$schema                                   = $this->access_schema();
		$schema['properties']['expected_version'] = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$schema['properties']['idempotency_key']  = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 120,
		);
		$schema['required']                       = array_merge( $schema['required'], array( 'expected_version', 'idempotency_key' ) );
		if ( $correction ) {
			$schema['properties']['correction'] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 2000,
			);
			$schema['required'][]               = 'correction';
		}
		return $schema;
	}

	/** Internal adapter input after transport-owned challenge and rate admission. */
	private function recovery_schema(): array {
		return $this->object_schema(
			array(
				'public_id'       => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id'   => $this->venue_id_schema(),
				'contact_email'   => array(
					'type'      => 'string',
					'format'    => 'email',
					'maxLength' => 255,
				),
				'idempotency_key' => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 120,
				),
			),
			array( 'public_id', 'venue_term_id', 'contact_email', 'idempotency_key' )
		);
	}

	private function status_output_schema(): array {
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		return $this->object_schema(
			array(
				'public_id'          => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id'      => $this->venue_id_schema(),
				'venue'              => $this->object_schema( array( 'name' => array( 'type' => 'string' ) ), array( 'name' ) ),
				'submitted_at'       => array( 'type' => 'string' ),
				'updated_at'         => array( 'type' => 'string' ),
				'status'             => array(
					'type' => 'string',
					'enum' => BookingRepository::STATUSES,
				),
				'status_label'       => array( 'type' => 'string' ),
				'version'            => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'requested_interval' => $this->object_schema(
					array(
						'start_at' => $nullable_string,
						'end_at'   => $nullable_string,
					),
					array( 'start_at', 'end_at' )
				),
				'requested_space'    => $this->object_schema(
					array(
						'key'   => $nullable_string,
						'label' => array( 'type' => 'string' ),
					),
					array( 'key', 'label' )
				),
				'permitted_actions'  => array(
					'type'     => 'array',
					'items'    => array(
						'type' => 'string',
						'enum' => array( 'request_correction', 'withdraw', 'request_cancellation' ),
					),
					'maxItems' => 2,
				),
			),
			array( 'public_id', 'venue_term_id', 'venue', 'submitted_at', 'updated_at', 'status', 'status_label', 'version', 'requested_interval', 'requested_space', 'permitted_actions' )
		);
	}

	private function correction_output_schema(): array {
		return $this->object_schema(
			array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id' => $this->venue_id_schema(),
				'operation'     => array(
					'type' => 'string',
					'enum' => array( 'correction_requested' ),
				),
				'version'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			array( 'public_id', 'venue_term_id', 'operation', 'version' )
		);
	}

	private function withdrawal_output_schema(): array {
		return $this->object_schema(
			array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id' => $this->venue_id_schema(),
				'operation'     => array(
					'type' => 'string',
					'enum' => array( 'withdrawn', 'cancellation_requested' ),
				),
				'status'        => array(
					'type' => 'string',
					'enum' => array( 'withdrawn' ),
				),
				'status_label'  => array( 'type' => 'string' ),
				'version'       => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
			array( 'public_id', 'venue_term_id', 'operation', 'version' )
		);
	}

	private function recovery_output_schema(): array {
		return $this->object_schema(
			array(
				'public_id'     => array(
					'type'   => 'string',
					'format' => 'uuid',
				),
				'venue_term_id' => $this->venue_id_schema(),
				'operation'     => array(
					'type' => 'string',
					'enum' => array( 'receipt_resend_requested' ),
				),
			),
			array( 'public_id', 'venue_term_id', 'operation' )
		);
	}

	private function venue_id_schema(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	private function object_schema( array $properties, array $required ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}
}
