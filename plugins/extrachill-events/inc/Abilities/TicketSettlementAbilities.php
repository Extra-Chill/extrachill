<?php
/**
 * Ticket-sales evidence and booking settlement abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

// Repository convention uses concise method comments.
// phpcs:disable Generic.Commenting.DocComment.MissingShort,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.VariableComment.Missing

use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\VenueAuthorization;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers stable finance contracts over the ticket settlement service. */
class TicketSettlementAbilities {
	private static bool $registered = false;
	/** @var TicketSettlementService */
	private $service;
	/** @var BookingRepository */
	private $bookings;
	/** @var VenueAuthorization */
	private $authorization;

	public function __construct( ?TicketSettlementService $service = null, ?BookingRepository $bookings = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->service       = $service ? $service : new TicketSettlementService( $this->bookings, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register the six issue-owned operations. */
	public function register(): void {
		$this->register_ability( 'extrachill/record-booking-ticket-sales', __( 'Record Booking Ticket Sales', 'extrachill-events' ), $this->report_input_schema(), $this->report_schema(), 'record_sales', 'can_access_booking', false, true );
		$this->register_ability(
			'extrachill/list-booking-ticket-sales',
			__( 'List Booking Ticket Sales', 'extrachill-events' ),
			$this->booking_page_schema(),
			array(
				'type'     => 'array',
				'maxItems' => 200,
				'items'    => $this->report_schema(),
			),
			'list_sales',
			'can_access_booking',
			true,
			true
		);
		$this->register_ability( 'extrachill/calculate-booking-settlement', __( 'Calculate Booking Settlement', 'extrachill-events' ), $this->calculation_input_schema(), $this->calculation_schema(), 'calculate', 'can_access_booking', true, true );
		$this->register_ability( 'extrachill/finalize-booking-settlement', __( 'Finalize Booking Settlement', 'extrachill-events' ), $this->finalize_input_schema(), $this->settlement_schema(), 'finalize', 'can_manage_booking_finances', false, true );
		$this->register_ability( 'extrachill/mark-booking-settlement-paid', __( 'Mark Booking Settlement Paid', 'extrachill-events' ), $this->terminal_input_schema( 'payment_reference' ), $this->settlement_schema(), 'mark_paid', 'can_manage_booking_finances', false, false );
		$this->register_ability( 'extrachill/void-booking-settlement', __( 'Void Booking Settlement', 'extrachill-events' ), $this->terminal_input_schema( 'reason' ), $this->settlement_schema(), 'void_settlement', 'can_manage_booking_finances', false, false );
	}

	private function register_ability( string $name, string $label, array $input, array $output, string $execute, string $permission, bool $is_readonly, bool $idempotent ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => $input,
				'output_schema'       => $output,
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

	public function record_sales( array $input ) {
		return $this->service->record_sales( $input, get_current_user_id() );
	}

	public function list_sales( array $input ) {
		return $this->service->list_sales( (int) $input['booking_id'], get_current_user_id(), (int) ( $input['limit'] ?? 100 ), (int) ( $input['offset'] ?? 0 ) );
	}

	public function calculate( array $input ) {
		return $this->service->calculate( $input, get_current_user_id() );
	}

	public function finalize( array $input ) {
		return $this->service->finalize( $input, get_current_user_id() );
	}

	public function mark_paid( array $input ) {
		return $this->service->mark_paid( $input, get_current_user_id() );
	}

	public function void_settlement( array $input ) {
		return $this->service->void( $input, get_current_user_id() );
	}

	public function can_access_booking( array $input ) {
		return $this->authorize_booking( $input, VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	public function can_manage_booking_finances( array $input ) {
		return $this->authorize_booking( $input, VenueAuthorization::ACTION_MANAGE_FINANCES );
	}

	private function authorize_booking( array $input, string $action ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking )
			? $this->authorization->authorize( get_current_user_id(), $booking['venue_term_id'], $action )
			: new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	private function report_input_schema(): array {
		$properties = array(
			'booking_id'         => $this->positive_id_schema(),
			'provider'           => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 64,
				'pattern'   => '^[a-z0-9_-]+$',
			),
			'external_report_id' => array(
				'type'      => 'string',
				'minLength' => 1,
				'maxLength' => 191,
			),
			'source_type'        => array(
				'type' => 'string',
				'enum' => TicketSettlementService::SOURCE_TYPES,
			),
			'period_start'       => $this->datetime_schema(),
			'period_end'         => $this->datetime_schema(),
			'tickets_sold'       => array( 'type' => 'integer' ),
			'tickets_refunded'   => array( 'type' => 'integer' ),
			'gross_minor'        => array( 'type' => 'integer' ),
			'fees_minor'         => array( 'type' => 'integer' ),
			'tax_minor'          => array( 'type' => 'integer' ),
			'refunds_minor'      => array( 'type' => 'integer' ),
			'net_minor'          => array( 'type' => 'integer' ),
			'currency'           => $this->currency_schema(),
			'corrects_report_id' => array(
				'type'    => array( 'integer', 'null' ),
				'minimum' => 1,
			),
			'source'             => array(
				'type'                 => 'object',
				'minProperties'        => 1,
				'additionalProperties' => true,
			),
		);
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array( 'booking_id', 'provider', 'external_report_id', 'source_type', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency', 'source' ),
			'additionalProperties' => false,
		);
	}

	private function booking_page_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id' => $this->positive_id_schema(),
				'limit'      => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 200,
				),
				'offset'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 100000,
				),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	private function calculation_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'       => $this->positive_id_schema(),
				'basis'            => array(
					'type' => 'string',
					'enum' => TicketSettlementService::BASES,
				),
				'basis_points'     => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'currency'         => $this->currency_schema(),
				'adjustment_minor' => array( 'type' => 'integer' ),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	private function finalize_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'               => $this->positive_id_schema(),
				'expected_booking_version' => $this->positive_id_schema(),
				'expected_report_ids'      => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 10000,
					'uniqueItems' => true,
					'items'       => $this->positive_id_schema(),
				),
				'basis'                    => array(
					'type' => 'string',
					'enum' => TicketSettlementService::BASES,
				),
				'basis_points'             => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'currency'                 => $this->currency_schema(),
				'formula_version'          => array(
					'type' => 'integer',
					'enum' => array( TicketSettlementService::FORMULA_VERSION ),
				),
				'adjustment_minor'         => array( 'type' => 'integer' ),
			),
			'required'             => array( 'booking_id', 'expected_booking_version', 'expected_report_ids', 'basis', 'basis_points', 'currency', 'formula_version', 'adjustment_minor' ),
			'additionalProperties' => false,
		);
	}

	private function terminal_input_schema( string $field ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'               => $this->positive_id_schema(),
				'expected_booking_version' => $this->positive_id_schema(),
				'expected_version'         => $this->positive_id_schema(),
				$field                     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 'reason' === $field ? 1000 : 191,
				),
			),
			'required'             => array( 'booking_id', 'expected_booking_version', 'expected_version', $field ),
			'additionalProperties' => false,
		);
	}

	private function report_schema(): array {
		$nullable_id = array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 1,
		);
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                 => $this->positive_id_schema(),
				'booking_id'         => $this->positive_id_schema(),
				'event_id'           => $this->positive_id_schema(),
				'venue_term_id'      => $this->positive_id_schema(),
				'provider'           => array( 'type' => 'string' ),
				'external_report_id' => array( 'type' => 'string' ),
				'source_type'        => array(
					'type' => 'string',
					'enum' => TicketSettlementService::SOURCE_TYPES,
				),
				'period_start'       => $this->datetime_schema(),
				'period_end'         => $this->datetime_schema(),
				'tickets_sold'       => array( 'type' => 'integer' ),
				'tickets_refunded'   => array( 'type' => 'integer' ),
				'gross_minor'        => array( 'type' => 'integer' ),
				'fees_minor'         => array( 'type' => 'integer' ),
				'tax_minor'          => array( 'type' => 'integer' ),
				'refunds_minor'      => array( 'type' => 'integer' ),
				'net_minor'          => array( 'type' => 'integer' ),
				'currency'           => $this->currency_schema(),
				'corrects_report_id' => $nullable_id,
				'source'             => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'request_hash'       => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'created_by_user_id' => $this->positive_id_schema(),
				'created_at'         => $this->datetime_schema(),
			),
			'required'             => array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'provider', 'external_report_id', 'source_type', 'period_start', 'period_end', 'tickets_sold', 'tickets_refunded', 'gross_minor', 'fees_minor', 'tax_minor', 'refunds_minor', 'net_minor', 'currency', 'corrects_report_id', 'source', 'request_hash', 'created_by_user_id', 'created_at' ),
			'additionalProperties' => false,
		);
	}

	private function calculation_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'          => $this->positive_id_schema(),
				'booking_version'     => $this->positive_id_schema(),
				'event_id'            => $this->positive_id_schema(),
				'venue_term_id'       => $this->positive_id_schema(),
				'basis'               => array(
					'type' => 'string',
					'enum' => TicketSettlementService::BASES,
				),
				'basis_points'        => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'currency'            => $this->currency_schema(),
				'formula_version'     => array(
					'type' => 'integer',
					'enum' => array( TicketSettlementService::FORMULA_VERSION ),
				),
				'included_report_ids' => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => $this->positive_id_schema(),
				),
				'evidence_hash'       => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'basis_amount_minor'  => array( 'type' => 'integer' ),
				'share_amount_minor'  => array( 'type' => 'integer' ),
				'adjustment_minor'    => array( 'type' => 'integer' ),
				'amount_due_minor'    => array( 'type' => 'integer' ),
			),
			'required'             => array( 'booking_id', 'booking_version', 'event_id', 'venue_term_id', 'basis', 'basis_points', 'currency', 'formula_version', 'included_report_ids', 'evidence_hash', 'basis_amount_minor', 'share_amount_minor', 'adjustment_minor', 'amount_due_minor' ),
			'additionalProperties' => false,
		);
	}

	private function settlement_schema(): array {
		$nullable_id     = array(
			'type'    => array( 'integer', 'null' ),
			'minimum' => 1,
		);
		$nullable_string = array( 'type' => array( 'string', 'null' ) );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                   => $this->positive_id_schema(),
				'booking_id'           => $this->positive_id_schema(),
				'event_id'             => $this->positive_id_schema(),
				'venue_term_id'        => $this->positive_id_schema(),
				'status'               => array(
					'type' => 'string',
					'enum' => TicketSettlementService::STATUSES,
				),
				'version'              => $this->positive_id_schema(),
				'booking_version'      => $this->positive_id_schema(),
				'basis'                => array(
					'type' => 'string',
					'enum' => TicketSettlementService::BASES,
				),
				'basis_points'         => array(
					'type'    => 'integer',
					'minimum' => 0,
					'maximum' => 10000,
				),
				'currency'             => $this->currency_schema(),
				'formula_version'      => array(
					'type' => 'integer',
					'enum' => array( TicketSettlementService::FORMULA_VERSION ),
				),
				'included_report_ids'  => array(
					'type'     => 'array',
					'minItems' => 1,
					'items'    => $this->positive_id_schema(),
				),
				'evidence_hash'        => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'integrity_hash'       => array(
					'type'    => 'string',
					'pattern' => '^[a-f0-9]{64}$',
				),
				'basis_amount_minor'   => array( 'type' => 'integer' ),
				'adjustment_minor'     => array( 'type' => 'integer' ),
				'amount_due_minor'     => array( 'type' => 'integer' ),
				'finalized_by_user_id' => $this->positive_id_schema(),
				'finalized_at'         => $this->datetime_schema(),
				'paid_by_user_id'      => $nullable_id,
				'paid_at'              => $nullable_string,
				'payment_reference'    => $nullable_string,
				'voided_by_user_id'    => $nullable_id,
				'voided_at'            => $nullable_string,
				'void_reason'          => $nullable_string,
				'created_at'           => $this->datetime_schema(),
				'updated_at'           => $this->datetime_schema(),
			),
			'required'             => array( 'id', 'booking_id', 'event_id', 'venue_term_id', 'status', 'version', 'booking_version', 'basis', 'basis_points', 'currency', 'formula_version', 'included_report_ids', 'evidence_hash', 'integrity_hash', 'basis_amount_minor', 'adjustment_minor', 'amount_due_minor', 'finalized_by_user_id', 'finalized_at', 'paid_by_user_id', 'paid_at', 'payment_reference', 'voided_by_user_id', 'voided_at', 'void_reason', 'created_at', 'updated_at' ),
			'additionalProperties' => false,
		);
	}

	private function positive_id_schema(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
		);
	}

	private function currency_schema(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^[A-Z]{3}$',
		);
	}

	private function datetime_schema(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
	}
}
