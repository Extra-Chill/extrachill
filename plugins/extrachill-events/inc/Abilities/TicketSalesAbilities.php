<?php
/**
 * Ticket sales for a seller: shows, orders and refund eligibility.
 *
 * Read side of the venue/promoter Tickets tab. Events owns passes and ticket
 * settings; money and refunds stay with Shop (`extrachill/refund-ticket-order`).
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/CommerceSellerAbilities.php';
require_once __DIR__ . '/TicketAbilities.php';

/** Registers `extrachill/list-seller-ticket-sales`. */
class TicketSalesAbilities {

	/** Most ticketed events read for one seller. */
	const EVENT_LIMIT = 200;

	/**
	 * Seller identity and finance-authority resolver.
	 *
	 * @var CommerceSellerAbilities
	 */
	private $sellers;

	/**
	 * Construct the ability registrar.
	 *
	 * @param CommerceSellerAbilities|null $sellers  Seller resolver.
	 * @param bool                         $register Hook registration (false in tests).
	 */
	public function __construct( ?CommerceSellerAbilities $sellers = null, bool $register = true ) {
		$this->sellers = $sellers ? $sellers : new CommerceSellerAbilities( null, null, false );
		if ( $register ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register the read-only ability. */
	public function register(): void {
		$count = array( 'type' => 'integer' );
		wp_register_ability(
			'extrachill/list-seller-ticket-sales',
			array(
				'label'               => __( 'List Seller Ticket Sales', 'extrachill-events' ),
				'description'         => __( 'Ticketed shows and orders for a venue or promoter seller, with refund eligibility. Requires finance authority on the seller.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
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
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'events' => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'event_id'        => $count,
									'title'           => array( 'type' => 'string' ),
									'url'             => array( 'type' => 'string' ),
									'manage_url'      => array( 'type' => 'string' ),
									'start'           => array( 'type' => 'string' ),
									'timing'          => array( 'type' => 'string' ),
									'tickets_enabled' => array( 'type' => 'boolean' ),
									'price_minor'     => $count,
									'currency'        => array( 'type' => 'string' ),
									'quantity'        => $count,
									'sold'            => $count,
									'checked_in'      => $count,
									'refunded'        => $count,
									'orders'          => array(
										'type'  => 'array',
										'items' => array(
											'type'       => 'object',
											'properties' => array(
												'order_reference' => array( 'type' => 'string' ),
												'name'     => array( 'type' => 'string' ),
												'quantity' => $count,
												'checked_in' => $count,
												'bought_at' => array( 'type' => 'string' ),
												'status'   => array(
													'type' => 'string',
													'enum' => array( 'paid', 'checked_in', 'refunded' ),
												),
												'refundable' => array( 'type' => 'boolean' ),
											),
										),
									),
								),
							),
						),
					),
					'required'   => array( 'events' ),
				),
				'execute_callback'    => array( $this, 'execute' ),
				'permission_callback' => array( $this, 'permission' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);
	}

	/**
	 * Require finance authority on the seller.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function permission( $input = array() ) {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'rest_forbidden', __( 'Log in to see ticket sales.', 'extrachill-events' ), array( 'status' => 401 ) );
		}
		$seller = $this->sellers->execute(
			array(
				'type' => (string) ( $input['type'] ?? '' ),
				'id'   => (int) ( $input['id'] ?? 0 ),
			)
		);
		if ( is_wp_error( $seller ) ) {
			return $seller;
		}
		return empty( $seller['can_manage_finances'] )
			? new \WP_Error( 'cannot_manage_seller_finances', __( 'Only people who manage this seller\'s money can see ticket sales.', 'extrachill-events' ), array( 'status' => 403 ) )
			: true;
	}

	/**
	 * List the seller's ticketed shows and orders.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input ): array {
		$seller = array(
			'type' => (string) $input['type'],
			'id'   => (int) $input['id'],
		);

		$events = array();
		foreach ( $this->ticketed_event_ids() as $event_id ) {
			$settings = TicketAbilities::settings( $event_id );
			if ( (string) ( $settings['ticket_seller']['type'] ?? '' ) === $seller['type'] && (int) ( $settings['ticket_seller']['id'] ?? 0 ) === $seller['id'] ) {
				$events[ $event_id ] = $settings;
			}
		}

		$rows_by_event = array();
		foreach ( RsvpPassesTable::ticket_rows_for_events( array_keys( $events ) ) as $row ) {
			$rows_by_event[ (int) $row['event_id'] ][] = $row;
		}

		$out = array();
		foreach ( $events as $event_id => $settings ) {
			$dates = function_exists( 'datamachine_get_event_dates' ) ? datamachine_get_event_dates( $event_id ) : null;
			$url   = (string) get_permalink( $event_id );
			$out[] = self::summarize_event(
				array(
					'event_id'   => $event_id,
					'title'      => html_entity_decode( (string) get_the_title( $event_id ), ENT_QUOTES ),
					'url'        => $url,
					'manage_url' => '' === $url ? '' : $url . '#ec-guest-list-management',
					'start'      => $dates && ! empty( $dates->start_datetime ) ? (string) $dates->start_datetime : '',
					'timing'     => function_exists( 'datamachine_get_event_timing' ) ? datamachine_get_event_timing( $event_id ) : 'past',
				),
				$settings,
				$rows_by_event[ $event_id ] ?? array()
			);
		}

		return array( 'events' => self::sort_events( $out ) );
	}

	/**
	 * Published events that carry a ticket offer.
	 *
	 * @return int[]
	 */
	private function ticketed_event_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'data_machine_events',
					'post_status'    => 'publish',
					'meta_key'       => TicketAbilities::META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- Few events carry a ticket offer.
					'fields'         => 'ids',
					'posts_per_page' => self::EVENT_LIMIT,
					'no_found_rows'  => true,
				)
			)
		);
	}

	/**
	 * Summarize one show from its settings and paid pass rows.
	 *
	 * Orders group passes by order reference. An order is refunded when every
	 * pass is revoked, checked in when any pass is redeemed (and then never
	 * refundable), and paid otherwise.
	 *
	 * @param array $event    Event fields.
	 * @param array $settings Ticket settings.
	 * @param array $rows     Paid pass rows for the event.
	 * @return array
	 */
	public static function summarize_event( array $event, array $settings, array $rows ): array {
		$orders = array();
		$counts = array(
			'sold'       => 0,
			'checked_in' => 0,
			'refunded'   => 0,
		);
		foreach ( $rows as $row ) {
			$reference = (string) $row['order_reference'];
			if ( ! isset( $orders[ $reference ] ) ) {
				$orders[ $reference ] = array(
					'order_reference' => $reference,
					'name'            => (string) ( $row['guest_name'] ?? '' ),
					'quantity'        => 0,
					'checked_in'      => 0,
					'revoked'         => 0,
					'bought_at'       => (string) ( $row['issued_at'] ?? '' ),
				);
			}
			++$orders[ $reference ]['quantity'];
			if ( RsvpPassesTable::STATUS_REDEEMED === $row['status'] ) {
				++$orders[ $reference ]['checked_in'];
				++$counts['checked_in'];
				++$counts['sold'];
			} elseif ( RsvpPassesTable::STATUS_REVOKED === $row['status'] ) {
				++$orders[ $reference ]['revoked'];
				++$counts['refunded'];
			} else {
				++$counts['sold'];
			}
			if ( (string) ( $row['issued_at'] ?? '' ) < $orders[ $reference ]['bought_at'] ) {
				$orders[ $reference ]['bought_at'] = (string) $row['issued_at'];
			}
		}

		$order_list = array();
		foreach ( $orders as $order ) {
			if ( $order['checked_in'] > 0 ) {
				$status = 'checked_in';
			} elseif ( $order['revoked'] === $order['quantity'] ) {
				$status = 'refunded';
			} else {
				$status = 'paid';
			}
			unset( $order['revoked'] );
			$order['status']     = $status;
			$order['refundable'] = 'paid' === $status;
			$order_list[]        = $order;
		}

		return $event + array(
			'tickets_enabled' => (bool) $settings['tickets_enabled'],
			'price_minor'     => (int) $settings['ticket_price_minor'],
			'currency'        => (string) $settings['ticket_currency'],
			'quantity'        => (int) $settings['ticket_quantity'],
		) + $counts + array( 'orders' => $order_list );
	}

	/**
	 * Upcoming and ongoing shows first (soonest first), then past shows
	 * (most recent first). Undated shows last.
	 *
	 * @param array $events Event summaries.
	 * @return array
	 */
	public static function sort_events( array $events ): array {
		usort(
			$events,
			static function ( $a, $b ) {
				$rank = static fn( $e ) => '' === $e['start'] ? 2 : ( 'past' === $e['timing'] ? 1 : 0 );
				if ( $rank( $a ) !== $rank( $b ) ) {
					return $rank( $a ) <=> $rank( $b );
				}
				return 0 === $rank( $a ) ? strcmp( $a['start'], $b['start'] ) : strcmp( $b['start'], $a['start'] );
			}
		);
		return $events;
	}
}
