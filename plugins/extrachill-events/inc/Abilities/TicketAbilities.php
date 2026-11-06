<?php
/**
 * Paid ticket offers and service-only fulfillment.
 *
 * @package ExtraChillEvents
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

defined( 'ABSPATH' ) || exit;

/**
 * Ticket offer settings plus the paid-pass abilities consumed by the Shop.
 */
class TicketAbilities {

	/** Post meta key holding the ticket offer. */
	const META_KEY = '_extrachill_ticket_offer';

	/**
	 * Register on the Abilities API init hook.
	 */
	public function __construct() {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Ticket offer field schema, merged into the event perk settings.
	 *
	 * @return array
	 */
	public static function fields(): array {
		return array(
			'tickets_enabled'    => array( 'type' => 'boolean' ),
			'ticket_price_minor' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'ticket_currency'    => array(
				'type'    => 'string',
				'pattern' => '^[a-z]{3}$',
			),
			'ticket_quantity'    => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'ticket_seller'      => array(
				'type'                 => 'object',
				'properties'           => array(
					'type' => array(
						'type' => 'string',
						'enum' => array( 'venue', 'promoter' ),
					),
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
				),
				'required'             => array( 'type', 'id' ),
				'additionalProperties' => false,
			),
		);
	}

	/**
	 * Stored ticket offer with defaults applied.
	 *
	 * @param int $id Event post ID.
	 * @return array
	 */
	public static function settings( int $id ): array {
		$stored   = get_post_meta( $id, self::META_KEY, true );
		$defaults = array(
			'tickets_enabled'    => false,
			'ticket_price_minor' => 0,
			'ticket_currency'    => 'usd',
			'ticket_quantity'    => 0,
			'ticket_seller'      => array(
				'type' => 'venue',
				'id'   => 0,
			),
		);

		return array_merge( $defaults, is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Validate before any perk writes, including finance authority on seller changes.
	 *
	 * @param int   $id    Event post ID.
	 * @param array $input Submitted settings.
	 * @return array|\WP_Error Merged settings or an error.
	 */
	public static function validate_settings( int $id, array $input ) {
		$settings = array_merge( self::settings( $id ), array_intersect_key( $input, self::fields() ) );

		if ( isset( $input['ticket_seller'] ) ) {
			if ( ! extrachill_events_user_can_manage_event( get_current_user_id(), $id ) ) {
				return new \WP_Error( 'ticket_seller_forbidden', 'Event management is required.', array( 'status' => 403 ) );
			}

			$seller = ( new CommerceSellerAbilities( null, null, false ) )->execute( $input['ticket_seller'] );
			if ( is_wp_error( $seller ) ) {
				return $seller;
			}
			if ( empty( $seller['can_manage_finances'] ) ) {
				return new \WP_Error( 'ticket_seller_forbidden', 'Seller finance authority is required.', array( 'status' => 403 ) );
			}
		}

		$malformed     = $settings['ticket_price_minor'] < 0
			|| $settings['ticket_quantity'] < 0
			|| ! preg_match( '/^[a-z]{3}$/', (string) $settings['ticket_currency'] );
		$enabled_unset = $settings['tickets_enabled']
			&& ( $settings['ticket_price_minor'] <= 0 || empty( $settings['ticket_seller']['id'] ) );

		if ( $malformed || $enabled_unset ) {
			return new \WP_Error( 'invalid_ticket_offer', 'Enabled tickets require a positive price and a seller.', array( 'status' => 400 ) );
		}

		return $settings;
	}

	/**
	 * Register the ticket abilities.
	 */
	public function register(): void {
		$reference = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 191,
		);
		$event     = array( 'event' => array( 'type' => array( 'string', 'integer' ) ) );

		$this->register_ability(
			'get-event-ticket-offer',
			'offer',
			$event,
			array( 'event' ),
			$this->offer_output(),
			true
		);

		$this->register_ability(
			'issue-ticket-passes',
			'issue',
			$event + array(
				'quantity'          => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 10,
				),
				'name'              => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 100,
				),
				'email'             => array(
					'type'   => 'string',
					'format' => 'email',
				),
				'idempotency_key'   => $reference,
				'order_reference'   => $reference,
				'payment_reference' => $reference,
			),
			array( 'event', 'quantity', 'name', 'email', 'idempotency_key', 'order_reference', 'payment_reference' ),
			array(
				'status' => array(
					'type' => 'string',
					'enum' => array( 'issued', 'sold_out' ),
				),
				'passes' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code' => array( 'type' => 'string' ),
							'url'  => array( 'type' => 'string' ),
						),
						'required'   => array( 'code', 'url' ),
					),
				),
			),
			false
		);

		$this->register_ability(
			'get-ticket-order-status',
			'order_status',
			array( 'order_reference' => $reference ),
			array( 'order_reference' ),
			array(
				'event_id'     => array( 'type' => 'integer' ),
				'passes'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'code'   => array( 'type' => 'string' ),
							'status' => array(
								'type' => 'string',
								'enum' => array( 'active', 'redeemed', 'revoked' ),
							),
						),
						'required'   => array( 'code', 'status' ),
					),
				),
				'any_redeemed' => array( 'type' => 'boolean' ),
				'refundable'   => array( 'type' => 'boolean' ),
			),
			false
		);

		$this->register_ability(
			'revoke-ticket-passes',
			'revoke',
			array(
				'order_reference' => $reference,
				'reason'          => $reference,
			),
			array( 'order_reference', 'reason' ),
			array( 'revoked' => array( 'type' => 'integer' ) ),
			false
		);
	}

	/**
	 * Output schema for the public ticket offer.
	 *
	 * @return array
	 */
	private function offer_output(): array {
		$seller = self::fields()['ticket_seller'];
		// An unconfigured offer has seller ID zero, not a selectable seller.
		$seller['properties']['id']['minimum'] = 0;

		return array(
			'event_id'    => array( 'type' => 'integer' ),
			'title'       => array( 'type' => 'string' ),
			'url'         => array( 'type' => 'string' ),
			'enabled'     => array( 'type' => 'boolean' ),
			'price_minor' => array( 'type' => 'integer' ),
			'currency'    => array( 'type' => 'string' ),
			'remaining'   => array( 'type' => array( 'integer', 'null' ) ),
			'sale_open'   => array( 'type' => 'boolean' ),
			'seller'      => $seller,
		);
	}

	/**
	 * Register one ticket ability.
	 *
	 * @param string $name     Ability name without namespace.
	 * @param string $callback Method on this class.
	 * @param array  $input    Input properties.
	 * @param array  $required Required input keys.
	 * @param array  $output   Output properties.
	 * @param bool   $is_public Whether anyone may execute it.
	 */
	private function register_ability( string $name, string $callback, array $input, array $required, array $output, bool $is_public ): void {
		wp_register_ability(
			'extrachill/' . $name,
			array(
				'label'               => $name,
				'description'         => $name,
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => $input,
					'required'             => $required,
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => $output,
					'required'   => array_keys( $output ),
				),
				'execute_callback'    => array( $this, $callback ),
				'permission_callback' => $is_public ? '__return_true' : array( $this, 'authorize' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'    => in_array( $callback, array( 'offer', 'order_status' ), true ),
						'idempotent'  => true,
						'destructive' => 'revoke' === $callback,
					),
				),
			)
		);
	}

	/**
	 * Require the verified ticket service authority.
	 *
	 * @return true|\WP_Error
	 */
	public function authorize() {
		if ( extrachill_events_ticket_has_service_authority() ) {
			return true;
		}

		return new \WP_Error( 'ticket_service_required', 'Verified ticket service authority is required.', array( 'status' => 403 ) );
	}

	/**
	 * Resolve a published event by ID or slug.
	 *
	 * @param mixed $reference Event ID or slug.
	 * @return \WP_Post|null
	 */
	private function resolve( $reference ) {
		$post = is_numeric( $reference )
			? get_post( (int) $reference )
			: get_page_by_path( (string) $reference, OBJECT, 'data_machine_events' );

		if ( $post && 'data_machine_events' === $post->post_type && 'publish' === $post->post_status ) {
			return $post;
		}

		return null;
	}

	/**
	 * Public ticket offer for an event.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function offer( array $input ) {
		$post = $this->resolve( $input['event'] ?? '' );
		if ( ! $post ) {
			return new \WP_Error( 'event_not_found', 'Event not found.', array( 'status' => 404 ) );
		}

		$settings  = self::settings( $post->ID );
		$capacity  = extrachill_events_get_perk_capacity( $post->ID );
		$remaining = $capacity > 0 ? max( 0, $capacity - RsvpPassesTable::count_for_event( $post->ID )['claimed'] ) : null;

		if ( $settings['ticket_quantity'] > 0 ) {
			$ticket_remaining = max( 0, $settings['ticket_quantity'] - RsvpPassesTable::paid_ticket_count( $post->ID ) );
			$remaining        = null === $remaining ? $ticket_remaining : min( $remaining, $ticket_remaining );
		}

		// Event dates live in the Data Machine Events dates table, not post meta.
		// Sales stay open through an ongoing show for walk-ups.
		$not_over = function_exists( 'datamachine_get_event_timing' ) && 'past' !== datamachine_get_event_timing( (int) $post->ID );

		return array(
			'event_id'    => (int) $post->ID,
			'title'       => $post->post_title,
			'url'         => get_permalink( $post->ID ),
			'enabled'     => (bool) $settings['tickets_enabled'],
			'price_minor' => (int) $settings['ticket_price_minor'],
			'currency'    => $settings['ticket_currency'],
			'remaining'   => $remaining,
			'sale_open'   => $settings['tickets_enabled'] && $not_over && ( null === $remaining || $remaining > 0 ),
			'seller'      => $settings['ticket_seller'],
		);
	}

	/**
	 * Issue paid passes for a completed checkout. Idempotent per key.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function issue( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$offer = $this->offer( $input );
		if ( is_wp_error( $offer ) ) {
			return $offer;
		}

		$existing = RsvpPassesTable::ticket_rows( $input['idempotency_key'], true );
		if ( ! $existing && ! $offer['sale_open'] ) {
			return array(
				'status' => 'sold_out',
				'passes' => array(),
			);
		}

		$settings = self::settings( $offer['event_id'] );
		$rows     = RsvpPassesTable::issue_tickets(
			$offer['event_id'],
			$input['quantity'],
			extrachill_events_get_perk_capacity( $offer['event_id'] ),
			$settings['ticket_quantity'],
			sanitize_text_field( $input['name'] ),
			$input['idempotency_key'],
			$input['order_reference'],
			$input['payment_reference']
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}

		$passes = array();
		foreach ( (array) $rows as $row ) {
			$passes[] = array(
				'code' => $row['code'],
				'url'  => extrachill_events_guest_list_pass_link( $offer['event_id'], $row['code'], $row['guest_name'] ),
			);
			if ( ! $existing ) {
				extrachill_events_send_guest_list_pass_email( $offer['event_id'], $input['email'], $row['code'], $row['guest_name'] );
			}
		}

		return array(
			'status' => null === $rows ? 'sold_out' : 'issued',
			'passes' => $passes,
		);
	}

	/**
	 * Pass status for a paid order.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function order_status( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$rows     = RsvpPassesTable::ticket_rows( $input['order_reference'] );
		$redeemed = in_array( 'redeemed', array_column( $rows, 'status' ), true );
		$passes   = array_map(
			static function ( $row ) {
				return array(
					'code'   => $row['code'],
					'status' => $row['status'],
				);
			},
			$rows
		);

		return array(
			'event_id'     => (int) ( $rows[0]['event_id'] ?? 0 ),
			'passes'       => $passes,
			'any_redeemed' => $redeemed,
			'refundable'   => ! $redeemed,
		);
	}

	/**
	 * Revoke every active pass on an order unless one is checked in.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function revoke( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		return RsvpPassesTable::revoke_ticket_order( $input['order_reference'] );
	}
}
