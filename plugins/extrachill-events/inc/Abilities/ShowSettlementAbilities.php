<?php
/**
 * Complete show-settlement abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

// Repository convention uses concise method comments and translated registrar labels.
// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing,WordPress.WP.I18n.NonSingularStringLiteralText

use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\ShowSettlementService;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers bounded private finance operations over immutable show revisions. */
class ShowSettlementAbilities {
	private static bool $registered = false;
	/** @var ShowSettlementService|null */
	private $service;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var LocalSupportAuthorization */
	private $artist_authorization;

	public function __construct( ?ShowSettlementService $service = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null, ?LocalSupportAuthorization $artist_authorization = null ) {
		$this->bookings             = $bookings ? $bookings : new BookingRepository();
		$this->authorization        = $authorization ? $authorization : new VenueAuthorization();
		$this->artist_authorization = $artist_authorization ? $artist_authorization : new LocalSupportAuthorization( $this->authorization );
		$this->service              = $service;
		if ( ! self::$registered ) {
			// @phpstan-ignore arguments.count
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the complete private show-settlement lifecycle. */
	public function register(): void {
		$this->register_ability( 'extrachill/draft-booking-show-settlement', 'Draft Booking Show Settlement', $this->revision_input( false ), 'draft', false, true );
		$this->register_ability( 'extrachill/read-booking-show-settlement', 'Read Booking Show Settlement', $this->read_input(), 'read', true, true );
		$this->register_ability( 'extrachill/revise-booking-show-settlement', 'Revise Booking Show Settlement', $this->revision_input( true ), 'revise', false, true );
		$this->register_ability( 'extrachill/finalize-booking-show-settlement', 'Finalize Booking Show Settlement', $this->transition_input(), 'finalize', false, true );
		$this->register_ability( 'extrachill/acknowledge-booking-show-settlement', 'Acknowledge Booking Show Settlement', $this->acknowledgement_input(), 'acknowledge', false, true, 'can_acknowledge' );
		$this->register_ability( 'extrachill/dispute-booking-show-settlement', 'Dispute Booking Show Settlement', $this->transition_input( 'reason' ), 'dispute', false, true );
		$this->register_ability( 'extrachill/correct-booking-show-settlement', 'Correct Booking Show Settlement', $this->revision_input( true, true ), 'correct', false, true );
		$this->register_ability( 'extrachill/mark-booking-artist-payout-paid', 'Mark Booking Artist Payout Paid', $this->payment_input(), 'mark_paid', false, true );
		$this->register_ability( 'extrachill/void-booking-show-settlement', 'Void Booking Show Settlement', $this->transition_input( 'reason' ), 'void_settlement', false, true );
	}

	private function register_ability( string $name, string $label, array $input, string $execute, bool $is_readonly, bool $idempotent, string $permission = 'can_manage' ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => __( $label, 'extrachill-events' ),
				'description'         => __( $label, 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => $this->output_schema(),
				'execute_callback'    => array( $this, $execute ),
				'permission_callback' => array( $this, $permission ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => $is_readonly,
						'idempotent'  => $idempotent,
						'destructive' => ! $is_readonly,
					),
				),
			)
		);
	}

	public function draft( array $input ) {
		return $this->service()->draft( $input, get_current_user_id() );
	}

	public function read( array $input ) {
		return $this->service()->get( (int) $input['booking_id'], get_current_user_id(), isset( $input['revision'] ) ? (int) $input['revision'] : null );
	}

	public function revise( array $input ) {
		return $this->service()->revise( $input, get_current_user_id() );
	}

	public function finalize( array $input ) {
		return $this->service()->finalize( $input, get_current_user_id() );
	}

	public function acknowledge( array $input ) {
		return $this->service()->acknowledge( $input, get_current_user_id() );
	}

	public function dispute( array $input ) {
		return $this->service()->dispute( $input, get_current_user_id() );
	}

	public function correct( array $input ) {
		return $this->service()->correct( $input, get_current_user_id() );
	}

	public function mark_paid( array $input ) {
		return $this->service()->mark_paid( $input, get_current_user_id() );
	}

	public function void_settlement( array $input ) {
		return $this->service()->void( $input, get_current_user_id() );
	}

	private function service(): ShowSettlementService {
		if ( null === $this->service ) {
			$this->service = new ShowSettlementService( $this->bookings, null, $this->authorization, null, null, null, $this->artist_authorization );
		}

		return $this->service;
	}

	public function can_manage( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking )
			? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to manage this venue settlement.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	public function can_acknowledge( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		if ( ! is_array( $booking ) ) {
			return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to acknowledge this settlement.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		if ( 'counterparty_verified' === ( $input['acknowledgement_type'] ?? '' ) && null !== $booking['artist_term_id'] ) {
			return $this->artist_authorization->authorize_artist( $booking['artist_term_id'], get_current_user_id() );
		}
		return 'venue_recorded' === ( $input['acknowledgement_type'] ?? '' )
			? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], VenueAuthorization::ACTION_MANAGE_FINANCES )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to acknowledge this settlement.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function revision_input( bool $replacement, bool $correction = false ): array {
		$properties = array(
			'booking_id'                 => $this->id(),
			'commission_settlement_id'   => $this->id(),
			'currency'                   => $this->currency(),
			'ticket_gross_minor'         => array( 'type' => 'integer' ),
			'door_gross_minor'           => array( 'type' => 'integer' ),
			'fees_minor'                 => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'taxes_minor'                => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'refunds_minor'              => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'venue_expenses_minor'       => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'production_expenses_minor'  => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'artist_guarantee_minor'     => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'artist_split_basis_points'  => array(
				'type'    => 'integer',
				'minimum' => 0,
				'maximum' => 10000,
			),
			'adjustments'                => array(
				'type'     => 'array',
				'maxItems' => 100,
				'items'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'amount_minor' => array( 'type' => 'integer' ),
						'reason'       => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 500,
						),
					),
					'required'             => array( 'amount_minor', 'reason' ),
					'additionalProperties' => false,
				),
			),
			'door_report_attachment_ids' => $this->ids( 20, false ),
			'idempotency_key'            => $this->key(),
		);
		$required   = array( 'booking_id', 'commission_settlement_id', 'currency', 'ticket_gross_minor', 'door_gross_minor', 'fees_minor', 'taxes_minor', 'refunds_minor', 'venue_expenses_minor', 'production_expenses_minor', 'artist_guarantee_minor', 'artist_split_basis_points', 'adjustments', 'door_report_attachment_ids', 'idempotency_key' );
		if ( $replacement ) {
			$properties['expected_revision_id'] = $this->id();
			$required[]                         = 'expected_revision_id';
		}
		if ( $correction ) {
			$properties['expected_version'] = $this->id();
			$properties['reason']           = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 1000,
			);
			$required[]                     = 'expected_version';
			$required[]                     = 'reason';
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	private function read_input(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id' => $this->id(),
				'revision'   => $this->id(),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	private function transition_input( ?string $field = null, bool $required = true ): array {
		$properties      = array(
			'booking_id'       => $this->id(),
			'revision_id'      => $this->id(),
			'expected_version' => $this->id(),
			'idempotency_key'  => $this->key(),
		);
		$required_fields = array_keys( $properties );
		if ( null !== $field ) {
			$properties[ $field ] = array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 1000,
			);
			if ( $required ) {
				$required_fields[] = $field;
			}
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required_fields,
			'additionalProperties' => false,
		);
	}

	private function acknowledgement_input(): array {
		$schema                                       = $this->transition_input( 'note', false );
		$schema['properties']['acknowledgement_type'] = array(
			'type' => 'string',
			'enum' => array( 'counterparty_verified', 'venue_recorded' ),
		);
		$schema['properties']['acknowledgement_evidence_attachment_ids'] = $this->ids( 20, false );
		$schema['required'][] = 'acknowledgement_type';
		$schema['required'][] = 'acknowledgement_evidence_attachment_ids';
		return $schema;
	}

	private function payment_input(): array {
		$schema                                    = $this->transition_input();
		$schema['properties']['payment_reference'] = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 191,
		);
		$schema['properties']['payment_date']      = array(
			'type'    => 'string',
			'pattern' => '^\d{4}-\d{2}-\d{2}$',
		);
		$schema['properties']['payout_evidence_attachment_ids'] = $this->ids( 20, true );
		$schema['required'][]                                   = 'payment_reference';
		$schema['required'][]                                   = 'payment_date';
		$schema['required'][]                                   = 'payout_evidence_attachment_ids';
		return $schema;
	}

	private function output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                        => $this->id(),
				'public_id'                 => array(
					'type'    => 'string',
					'pattern' => '^[a-fA-F0-9-]{36}$',
				),
				'booking_id'                => $this->id(),
				'event_id'                  => $this->id(),
				'venue_term_id'             => $this->id(),
				'revision'                  => $this->id(),
				'corrects_revision_id'      => array(
					'type'    => array( 'integer', 'null' ),
					'minimum' => 1,
				),
				'commission_settlement_id'  => $this->id(),
				'commission_integrity_hash' => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'currency'                  => $this->currency(),
				'formula_version'           => array(
					'type' => 'integer',
					'enum' => array( ShowSettlementService::FORMULA_VERSION ),
				),
				'terms'                     => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'evidence'                  => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
				'calculation'               => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'integrity_hash'            => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'created_by_user_id'        => $this->id(),
				'created_at'                => array( 'type' => 'string' ),
				'status'                    => array(
					'type' => 'string',
					'enum' => array_merge( array( 'draft' ), ShowSettlementService::ACTIONS ),
				),
				'version'                   => $this->id(),
				'actions'                   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
				),
			),
			'required'             => array( 'id', 'public_id', 'booking_id', 'event_id', 'venue_term_id', 'revision', 'corrects_revision_id', 'commission_settlement_id', 'commission_integrity_hash', 'currency', 'formula_version', 'terms', 'evidence', 'calculation', 'integrity_hash', 'created_by_user_id', 'created_at', 'status', 'version', 'actions' ),
			'additionalProperties' => false,
		);
	}

	private function id(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	private function currency(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[A-Z]{3}$',
		);
	}

	private function key(): array {
		return array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 191,
		);
	}

	private function ids( int $max, bool $required ): array {
		return array(
			'type'        => 'array',
			'minItems'    => $required ? 1 : 0,
			'maxItems'    => $max,
			'uniqueItems' => true,
			'items'       => $this->id(),
		);
	}
}
