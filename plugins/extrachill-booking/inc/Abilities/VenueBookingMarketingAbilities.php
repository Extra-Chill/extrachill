<?php
/**
 * Booking marketing abilities.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\BookingMarketingService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\TeamCapabilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Registers the explicit recovery trigger and approval replay handler. */
class VenueBookingMarketingAbilities {
	/**
	 * Whether hooks were registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;
	/**
	 * Marketing orchestration.
	 *
	 * @var BookingMarketingService
	 */
	private $marketing;
	/**
	 * Booking persistence.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/**
	 * Venue authority.
	 *
	 * @var callable
	 */
	private $authorization;

	/**
	 * Build the ability adapter.
	 *
	 * @param BookingMarketingService|null $marketing     Marketing service.
	 * @param BookingRepository|null       $bookings      Booking persistence.
	 * @param callable|null                $authorization Venue authority.
	 */
	public function __construct( ?BookingMarketingService $marketing = null, ?BookingRepository $bookings = null, ?callable $authorization = null ) {
		$this->authorization = $authorization ? $authorization : array( TeamCapabilities::class, 'authorize' );
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->marketing     = $marketing ? $marketing : new BookingMarketingService( $this->bookings, null, null, $this->authorization );
		if ( ! self::$registered ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
			add_filter( 'datamachine_pending_action_handlers', array( $this, 'pending_action_handlers' ) );
			self::$registered = true;
		}
	}

	/** Register the explicit recovery trigger. */
	public function register(): void {
		wp_register_ability(
			'extrachill/trigger-booking-marketing',
			array(
				'label'               => __( 'Trigger Booking Marketing', 'extrachill-events' ),
				'description'         => __( 'Recover or start configured marketing for a confirmed booking with a canonical event.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => $this->booking_schema(),
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => array( $this, 'trigger' ),
				'permission_callback' => array( $this, 'can_access' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => false,
						'idempotent'  => true,
						'destructive' => true,
					),
				),
			)
		);

		foreach ( array( 'get', 'retry', 'cancel' ) as $verb ) {
			wp_register_ability(
				'extrachill/' . $verb . '-booking-marketing-operation',
				array(
					/* translators: %s: delegated operation action, such as Get, Retry, or Cancel. */
					'label'               => sprintf( __( '%s Booking Marketing Operation', 'extrachill-events' ), ucfirst( $verb ) ),
					'description'         => __( 'Act on one venue-authorized delegated marketing operation.', 'extrachill-events' ),
					'category'            => 'extrachill-events',
					'input_schema'        => $this->operation_schema(),
					'output_schema'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
					),
					'execute_callback'    => function ( array $input ) use ( $verb ) {
						return $this->marketing->manage( $verb, absint( $input['booking_id'] ?? 0 ), (string) ( $input['operation_ref'] ?? '' ), get_current_user_id() );
					},
					'permission_callback' => array( $this, 'can_access' ),
					'meta'                => array(
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => 'get' === $verb,
							'idempotent'  => true,
							'destructive' => 'get' !== $verb,
						),
					),
				)
			);
		}
	}

	/**
	 * Execute the recovery trigger for one booking.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error Trigger result.
	 */
	public function trigger( array $input ) {
		return $this->marketing->trigger( absint( $input['booking_id'] ?? 0 ), get_current_user_id() );
	}

	/**
	 * Check exact venue access for one booking.
	 *
	 * @param array $input Ability input.
	 * @return bool|\WP_Error Permission result.
	 */
	public function can_access( array $input ) {
		$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		return is_array( $booking ) ? ( $this->authorization )( 'ec_access_venue', $booking['venue_term_id'], get_current_user_id() ) : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/**
	 * Register approval replay and fresh authorization callbacks.
	 *
	 * @param array $handlers Existing pending-action handlers.
	 * @return array Filtered handlers.
	 */
	public function pending_action_handlers( $handlers ) {
		$handlers                                     = is_array( $handlers ) ? $handlers : array();
		$handlers['extrachill_run_booking_marketing'] = array(
			'apply'       => function ( array $input ) {
				return $this->marketing->apply( $input, get_current_user_id() );
			},
			'can_resolve' => function ( array $payload, string $decision, int $user_id ) {
				unset( $decision );
				$input   = is_array( $payload['apply_input'] ?? null ) ? $payload['apply_input'] : array();
				$booking = $this->bookings->get( absint( $input['booking_id'] ?? 0 ) );
				return is_array( $booking ) ? ( $this->authorization )( 'ec_access_venue', $booking['venue_term_id'], $user_id ) : new \WP_Error( 'venue_action_forbidden' );
			},
		);
		return $handlers;
	}

	/** Return the booking identifier input contract. */
	private function booking_schema(): array {
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

	/** Return the exact opaque operation receipt input contract. */
	private function operation_schema(): array {
		$schema                                = $this->booking_schema();
		$schema['properties']['operation_ref'] = array(
			'type'    => 'string',
			'pattern' => '^dop_[a-f0-9]{64}$',
		);
		$schema['required'][]                  = 'operation_ref';
		return $schema;
	}
}
