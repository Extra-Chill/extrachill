<?php
/**
 * Venue booking performance and finance reporting abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

use ExtraChillEvents\Core\BookingReportingService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers stable read-only reports over canonical booking records. */
class BookingReportingAbilities {
	private static bool $registered = false;
	/** @var BookingReportingService|null */
	private $service;

	public function __construct( ?BookingReportingService $service = null ) {
		$this->service = $service;
		if ( ! self::$registered ) {
			// @phpstan-ignore arguments.count
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	/** Register operational and finance projections. */
	public function register(): void {
		$this->register_ability( 'extrachill/get-venue-booking-performance-report', __( 'Get Venue Booking Performance Report', 'extrachill-events' ), 'operations', 'can_read_operations', $this->operations_schema() );
		$this->register_ability( 'extrachill/get-venue-booking-revenue-report', __( 'Get Venue Booking Revenue Report', 'extrachill-events' ), 'finance', 'can_read_finance', $this->finance_schema() );
	}

	private function register_ability( string $name, string $label, string $execute, string $permission, array $output ): void {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $label,
				'category'            => 'extrachill-events',
				'input_schema'        => $this->input_schema( 'finance' === $execute ),
				'output_schema'       => $output,
				'execute_callback'    => array( $this, $execute ),
				'permission_callback' => array( $this, $permission ),
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
	}

	public function operations( array $input ) {
		return $this->service()->operations( $input, get_current_user_id() );
	}

	public function finance( array $input ) {
		return $this->service()->finance( $input, get_current_user_id() );
	}

	public function can_read_operations( array $input ) {
		$result = $this->service()->authorize_operations( $input, get_current_user_id() );
		return $result instanceof \WP_Error ? $result : true;
	}

	public function can_read_finance( array $input ) {
		$result = $this->service()->authorize_finance( $input, get_current_user_id() );
		return $result instanceof \WP_Error ? $result : true;
	}

	private function service(): BookingReportingService {
		if ( null === $this->service ) {
			$this->service = new BookingReportingService();
		}

		return $this->service;
	}

	private function input_schema( bool $finance ): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_ids' => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => BookingReportingService::MAX_VENUES,
					'uniqueItems' => true,
					'items'       => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'from'           => $this->datetime(),
				'to'             => $this->datetime(),
				'limit'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => $finance ? BookingReportingService::MAX_FINANCE_BOOKINGS : BookingReportingService::MAX_BOOKINGS,
				),
			),
			'required'             => array( 'venue_term_ids', 'from', 'to' ),
			'additionalProperties' => false,
		);
	}

	private function operations_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'scope'        => $this->scope_schema(),
				'state'        => array(
					'type' => 'string',
					'enum' => array( 'empty', 'incomplete', 'complete' ),
				),
				'bounded'      => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'availability' => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'string' ),
				),
				'funnel'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'operations'   => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'marketing'    => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
			),
			'required'             => array( 'scope', 'state', 'bounded', 'availability', 'funnel', 'operations', 'marketing' ),
			'additionalProperties' => false,
		);
	}

	private function finance_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'scope'                    => $this->scope_schema(),
				'state'                    => array(
					'type' => 'string',
					'enum' => array( 'empty', 'incomplete', 'corrected', 'finalized' ),
				),
				'bounded'                  => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'coverage'                 => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'commission_statuses'      => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'show_settlement_statuses' => array(
					'type'                 => 'object',
					'additionalProperties' => array( 'type' => 'integer' ),
				),
				'currencies'               => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'                 => 'object',
						'additionalProperties' => array( 'type' => 'integer' ),
					),
				),
			),
			'required'             => array( 'scope', 'state', 'bounded', 'coverage', 'commission_statuses', 'show_settlement_statuses', 'currencies' ),
			'additionalProperties' => false,
		);
	}

	private function scope_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'venue_term_ids' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'integer' ),
				),
				'from'           => $this->datetime(),
				'to'             => $this->datetime(),
				'timezone'       => array(
					'type' => 'string',
					'enum' => array( 'UTC' ),
				),
				'interval'       => array(
					'type' => 'string',
					'enum' => array( 'half_open' ),
				),
				'basis'          => array(
					'type' => 'string',
					'enum' => array( 'inquiry_created_at', 'performance_start_at' ),
				),
				'outcomes_as_of' => $this->datetime(),
			),
			'required'             => array( 'venue_term_ids', 'from', 'to', 'timezone', 'interval', 'basis', 'outcomes_as_of' ),
			'additionalProperties' => false,
		);
	}

	private function datetime(): array {
		return array(
			'type'    => 'string',
			'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
		);
	}
}
