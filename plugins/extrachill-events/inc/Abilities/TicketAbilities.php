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
	 * Ticket offer output schema: an event with no ticket seller reports null.
	 *
	 * The input schema stays strict (a chosen seller has a positive ID); only
	 * what abilities return may say "no seller".
	 *
	 * @return array
	 */
	public static function output_fields(): array {
		$fields                          = self::fields();
		$fields['ticket_seller']['type'] = array( 'object', 'null' );

		return $fields;
	}

	/**
	 * Shape stored ticket settings for ability output.
	 *
	 * Storage keeps the unconfigured seller as ID 0 for internal callers; the
	 * output contract reports it as null so it validates against output_fields().
	 *
	 * @param array $settings Ticket settings from settings() or validate_settings().
	 * @return array
	 */
	public static function output_settings( array $settings ): array {
		$seller = $settings['ticket_seller'] ?? null;
		$valid  = is_array( $seller )
			&& in_array( $seller['type'] ?? '', array( 'venue', 'promoter' ), true )
			&& (int) ( $seller['id'] ?? 0 ) > 0;

		$settings['ticket_seller'] = $valid
			? array(
				'type' => (string) $seller['type'],
				'id'   => (int) $seller['id'],
			)
			: null;

		return $settings;
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
		$current  = self::settings( $id );
		$settings = array_merge( $current, array_intersect_key( $input, self::fields() ) );

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

		// Only turning sales on, or moving them to another seller, asks Shop.
		// Unrelated saves on a show that is already on sale stay local.
		$seller_key = static function ( array $seller ): string {
			return (string) ( $seller['type'] ?? '' ) . ':' . (int) ( $seller['id'] ?? 0 );
		};
		$opening    = $settings['tickets_enabled']
			&& ( ! $current['tickets_enabled'] || $seller_key( $current['ticket_seller'] ) !== $seller_key( $settings['ticket_seller'] ) );
		if ( $opening ) {
			$ready = self::seller_can_receive_payments( $settings['ticket_seller'] );
			if ( is_wp_error( $ready ) ) {
				return $ready;
			}
		}

		return $settings;
	}

	/**
	 * Ask Shop, which owns Stripe Connect, whether a seller can take payments.
	 *
	 * Runs as the current user, who already holds finance authority for the
	 * seller. An unknown status refuses: tickets never open on a guess.
	 *
	 * @param array $seller Seller reference with type and id.
	 * @return true|\WP_Error
	 */
	public static function seller_can_receive_payments( array $seller ) {
		$unknown = new \WP_Error( 'ticket_seller_payments_unknown', 'Could not confirm the seller\'s payment setup. Try again in a moment.', array( 'status' => 503 ) );
		if ( ! function_exists( 'ec_cross_site_rest_request' ) ) {
			return $unknown;
		}

		$status = ec_cross_site_rest_request(
			'shop',
			'GET',
			'/shop/stripe-connect/status',
			array(
				'query' => array(
					'seller_type' => (string) ( $seller['type'] ?? '' ),
					'seller_id'   => (int) ( $seller['id'] ?? 0 ),
				),
			)
		);
		if ( is_wp_error( $status ) || ! is_array( $status ) || ! isset( $status['can_receive_payments'] ) ) {
			return $unknown;
		}
		if ( true !== $status['can_receive_payments'] ) {
			return new \WP_Error( 'ticket_seller_payments_unavailable', 'This seller has to finish payment setup before tickets can go on sale.', array( 'status' => 409 ) );
		}

		return true;
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

		$text = array(
			'type'      => 'string',
			'maxLength' => 300,
		);
		$this->register_ability(
			'notify-commerce-seller',
			'notify_seller',
			array(
				'seller'    => array(
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
				'subject'   => $text,
				'preheader' => $text,
				'body_html' => array(
					'type'      => 'string',
					'maxLength' => 50000,
				),
				'cta_url'   => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'cta_label' => $text,
			),
			array( 'seller', 'subject', 'body_html' ),
			array( 'sent' => array( 'type' => 'integer' ) ),
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

		$result = RsvpPassesTable::revoke_ticket_order( $input['order_reference'] );
		if ( 'dispute' === $input['reason'] ) {
			$checked_in = is_wp_error( $result ) && 'ticket_already_redeemed' === $result->get_error_code();
			if ( ! is_wp_error( $result ) || $checked_in ) {
				self::notify_seller_of_dispute( RsvpPassesTable::ticket_rows( $input['order_reference'] ), $checked_in );
			}
			// A buyer who already got in keeps their check-in; the seller
			// answers the dispute with it, so the webhook still succeeds.
			if ( $checked_in ) {
				return array( 'revoked' => 0 );
			}
		}
		return $result;
	}

	/**
	 * Tell the seller's finance owners that a buyer disputed a ticket payment.
	 *
	 * Events owns the seller's recipients, so the email goes from here when
	 * Shop revokes an order for a Stripe dispute.
	 *
	 * @param array $rows       The order's pass rows.
	 * @param bool  $checked_in Whether someone on the order already checked in.
	 */
	public static function notify_seller_of_dispute( array $rows, bool $checked_in ): void {
		$event_id = (int) ( $rows[0]['event_id'] ?? 0 );
		$seller   = self::settings( $event_id )['ticket_seller'];
		if ( ! $event_id || empty( $seller['id'] ) ) {
			return;
		}

		$show    = html_entity_decode( (string) get_the_title( $event_id ), ENT_QUOTES );
		$buyer   = (string) ( $rows[0]['guest_name'] ?? '' );
		$tickets = count( $rows );
		/* translators: 1: buyer name, 2: ticket count, 3: show title. */
		$what = sprintf( _n( '%1$s disputed their payment for %2$d ticket to %3$s with their bank.', '%1$s disputed their payment for %2$d tickets to %3$s with their bank.', $tickets, 'extrachill-events' ), $buyer, $tickets, $show );
		$then = $checked_in
			? __( 'They already checked in at the door. Tell Stripe that when you respond: a check-in is strong evidence they got what they paid for.', 'extrachill-events' )
			: __( 'Their passes are cancelled, so they won\'t get in with them.', 'extrachill-events' );
		$body = '<p>' . esc_html( $what ) . '</p><p>' . esc_html( $then ) . '</p><p>'
			. esc_html__( 'Stripe has held the payment and a dispute fee from your account. Respond in your Stripe dashboard under Payments, then Disputes, before the deadline shown there, or the bank keeps the money with the buyer.', 'extrachill-events' )
			. '</p>';

		self::email_seller_owners(
			$seller,
			array(
				/* translators: %s: show title. */
				'subject'   => sprintf( __( 'A ticket payment for %s was disputed', 'extrachill-events' ), $show ),
				'body_html' => $body,
				'cta_url'   => 'https://connect.stripe.com/express_login',
				'cta_label' => __( 'Open Stripe', 'extrachill-events' ),
			)
		);
	}

	/**
	 * Email a venue's or promoter's finance owners.
	 *
	 * Events owns who those people are, so other sites (Shop) ask through
	 * `extrachill/notify-commerce-seller` instead of reading them directly.
	 *
	 * @param array $seller  `{ type, id }` venue or promoter.
	 * @param array $message `subject`, `body_html`, optional `preheader`, `cta_url`, `cta_label`.
	 * @return int Emails queued.
	 */
	public static function email_seller_owners( array $seller, array $message ): int {
		if ( ! function_exists( 'ec_send_email_queued' ) ) {
			return 0;
		}
		$data = CommerceSellerNotifications::get_data( (string) ( $seller['type'] ?? '' ), (int) ( $seller['id'] ?? 0 ) );
		$sent = 0;
		foreach ( $data['recipients'] as $email ) {
			$result = ec_send_email_queued(
				array(
					'to'       => $email,
					'subject'  => (string) $message['subject'],
					'template' => 'extrachill/branded',
					'context'  => array_filter(
						array(
							'subject_html'   => esc_html( (string) $message['subject'] ),
							'preheader'      => (string) ( $message['preheader'] ?? '' ),
							'recipient_name' => $data['name'],
							'body_html'      => (string) $message['body_html'],
							'cta_url'        => (string) ( $message['cta_url'] ?? '' ),
							'cta_label'      => (string) ( $message['cta_label'] ?? '' ),
						)
					),
				)
			);
			if ( ! empty( $result['success'] ) ) {
				++$sent;
			}
		}
		return $sent;
	}

	/**
	 * Service-only: email a seller's finance owners on Shop's behalf.
	 *
	 * @param array $input Ability input.
	 * @return array|\WP_Error
	 */
	public function notify_seller( array $input ) {
		$allowed = $this->authorize();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		return array( 'sent' => self::email_seller_owners( $input['seller'], $input ) );
	}
}
