<?php
/**
 * Venue booking privacy and diagnostics ability.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

// Repository convention uses PSR-4 class names and concise method comments.
// phpcs:disable WordPress.Files.FileName,Generic.Commenting,Squiz.Commenting

use ExtraChillEvents\Core\BookingPrivacyService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the bounded dry-run-first booking operations surface. */
class BookingPrivacyAbilities {
	private static bool $registered = false;
	/** @var BookingPrivacyService */
	private $service;

	public function __construct( ?BookingPrivacyService $service = null ) {
		$this->service = $service ? $service : new BookingPrivacyService();
		if ( ! self::$registered ) {
			// @phpstan-ignore arguments.count
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			self::$registered = true;
		}
	}

	public function register(): void {
		wp_register_ability(
			'extrachill/operate-venue-booking-privacy',
			array(
				'label'               => __( 'Operate Venue Booking Privacy', 'extrachill-events' ),
				'description'         => __( 'Diagnose booking operations or dry-run/apply a bounded venue retention batch.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->input_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => true,
					),
				),
			)
		);
	}

	public function execute( array $input ) {
		return $this->service->operate( $input, get_current_user_id() );
	}

	public function permission( array $input ) {
		$result = $this->service->authorize_operation( $input, get_current_user_id() );
		return $result instanceof \WP_Error ? $result : true;
	}

	private function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'     => array(
					'type' => 'string',
					'enum' => array( 'diagnose', 'cleanup' ),
				),
				'venue_term_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'status'        => array(
					'type' => 'string',
					'enum' => \ExtraChillEvents\Core\BookingRepository::STATUSES,
				),
				'before'        => array(
					'type'    => 'string',
					'pattern' => '^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$',
				),
				'after_id'      => array(
					'type'    => 'integer',
					'minimum' => 0,
					'default' => 0,
				),
				'limit'         => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => BookingPrivacyService::MAX_LIMIT,
					'default' => BookingPrivacyService::BATCH_SIZE,
				),
				'apply'         => array(
					'type'    => 'boolean',
					'default' => false,
				),
			),
			'required'             => array( 'operation', 'venue_term_id', 'before' ),
			'additionalProperties' => false,
		);
	}
}
