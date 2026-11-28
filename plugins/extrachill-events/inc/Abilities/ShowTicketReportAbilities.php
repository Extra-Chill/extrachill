<?php
/**
 * Sales report and door list export for one show (extrachill#1746).
 *
 * The morning-after numbers for hosts: tickets sold, gross, fees, refunds
 * and net, a breakdown by ticket type and by code, and the check-in rate.
 * Passes come from RsvpPassesTable; money, codes and buyer emails come from
 * Shop's paid ticket orders (`extrachill/get-event-ticket-order-report`),
 * which Shop only shares with people who manage the show's seller. Hosts
 * who don't still get the ticket and check-in counts.
 *
 * @package ExtraChillEvents\Abilities
 */

namespace ExtraChillEvents\Abilities;

use ExtraChillEvents\Core\RsvpPassesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/TicketAbilities.php';
require_once __DIR__ . '/TicketTypes.php';

/** Registers `extrachill/get-show-ticket-report`. */
class ShowTicketReportAbilities {

	/** CSV header for the door list export. */
	const CSV_HEADER = array( 'Name', 'Email', 'Source', 'Ticket type', 'Code', 'Order reference', 'Checked in' );

	/**
	 * Construct the ability registrar.
	 *
	 * @param bool $register Hook registration (false in tests).
	 */
	public function __construct( bool $register = true ) {
		if ( $register ) {
			add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
		}
	}

	/** Register the read-only ability. */
	public function register(): void {
		$count = array( 'type' => 'integer' );
		wp_register_ability(
			'extrachill/get-show-ticket-report',
			array(
				'label'               => __( 'Get Show Ticket Report', 'extrachill-events' ),
				'description'         => __( 'Sales report for one show: tickets sold, gross, fees, refunds and net, a breakdown by ticket type and by code, and the check-in rate. Requires managing the event, the same as the door list. Money and codes need authority over the show\'s seller.', 'extrachill-events' ),
				'category'            => 'extrachill-events',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'event_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'event_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'event_id'        => $count,
						'tickets_enabled' => array( 'type' => 'boolean' ),
						'currency'        => array( 'type' => 'string' ),
						'sold'            => $count,
						'refunded'        => $count,
						'checked_in'      => $count,
						'check_in_rate'   => $count,
						'money_available' => array( 'type' => 'boolean' ),
						'gross_minor'     => $count,
						'fees_minor'      => $count,
						'refunds_minor'   => $count,
						'net_minor'       => $count,
						'ticket_types'    => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'id'            => array( 'type' => 'string' ),
									'name'          => array( 'type' => 'string' ),
									'sold'          => $count,
									'quantity'      => $count,
									'revenue_minor' => $count,
								),
							),
						),
						'codes'           => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'code'           => array( 'type' => 'string' ),
									'uses'           => $count,
									'max_uses'       => $count,
									'discount_minor' => $count,
								),
							),
						),
					),
					'required'   => array( 'event_id', 'sold', 'refunded', 'checked_in', 'check_in_rate', 'money_available', 'ticket_types', 'codes' ),
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
	 * Same rule as the door list: anyone who manages the event.
	 *
	 * @param array $input Ability input.
	 * @return true|\WP_Error
	 */
	public function permission( $input = array() ) {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		if ( 'data_machine_events' !== get_post_type( $event_id ) ) {
			return new \WP_Error( 'event_not_found', __( 'The show could not be found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$user_id = (int) get_current_user_id();
		if ( $user_id < 1 ) {
			return new \WP_Error( 'rest_forbidden', __( 'Log in to see ticket sales.', 'extrachill-events' ), array( 'status' => 401 ) );
		}
		if ( ! function_exists( 'extrachill_events_user_can_manage_event' ) || ! extrachill_events_user_can_manage_event( $user_id, $event_id ) ) {
			return new \WP_Error( 'cannot_manage_event', __( 'Only people who run this show can see its ticket sales.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Build the report for one show.
	 *
	 * @param array $input Ability input.
	 * @return array
	 */
	public function execute( array $input ): array {
		$event_id = (int) $input['event_id'];
		$rows     = RsvpPassesTable::ticket_rows_for_events( array( $event_id ) );
		// Nothing sold means no orders to read, and zeros give nothing away.
		$orders = $rows ? self::shop_orders( $event_id ) : array();
		return array( 'event_id' => $event_id ) + self::build(
			TicketAbilities::settings( $event_id ),
			RsvpPassesTable::paid_ticket_counts_by_type( $event_id ),
			$rows,
			$orders
		);
	}

	/**
	 * The show's paid ticket orders from Shop, as the current user.
	 *
	 * @param int $event_id Event post ID.
	 * @return array[]|null Order rows, or null when Shop refuses or is unreachable.
	 */
	public static function shop_orders( int $event_id ): ?array {
		if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
			return null;
		}
		$result = ec_cross_site_rest_request_http(
			'shop',
			'GET',
			'/wp-abilities/v1/abilities/extrachill/get-event-ticket-order-report/run',
			array(
				'user_id' => get_current_user_id(),
				'query'   => array( 'input' => array( 'event_id' => $event_id ) ),
			)
		);
		if ( ! is_array( $result ) || ! isset( $result['orders'] ) || ! is_array( $result['orders'] ) ) {
			return null;
		}
		return array_values( array_filter( $result['orders'], 'is_array' ) );
	}

	/**
	 * Totals from the show's settings, live passes per type, paid pass rows
	 * and Shop order rows.
	 *
	 * Sold counts live paid passes (active or checked in), the same count
	 * capacity uses. Gross is what buyers paid on every paid order; refunds
	 * what went back; fees Extra Chill's application fee on orders that kept
	 * it; net = gross - refunds - fees. A type's revenue is the ticket
	 * subtotal of its orders that were not refunded. A code's uses are the
	 * tickets it bought on those orders, against its ticket limit.
	 *
	 * @param array        $settings     Ticket settings.
	 * @param array        $sold_by_type RsvpPassesTable::paid_ticket_counts_by_type().
	 * @param array[]      $rows         Paid pass rows (status, ticket_type).
	 * @param array[]|null $orders     Shop order rows, or null when money is unavailable.
	 * @return array
	 */
	public static function build( array $settings, array $sold_by_type, array $rows, ?array $orders ): array {
		$types = array();
		foreach ( TicketTypes::effective( $settings ) as $type ) {
			$types[ $type['id'] ] = array(
				'id'            => (string) $type['id'],
				'name'          => (string) $type['name'],
				'sold'          => 0,
				'quantity'      => (int) $type['quantity'],
				'revenue_minor' => 0,
			);
		}
		$sold = 0;
		foreach ( $sold_by_type as $id => $count ) {
			$type_row                 = self::type_row( $types, (string) $id );
			$type_row['sold']        += (int) $count;
			$types[ $type_row['id'] ] = $type_row;
			$sold                    += (int) $count;
		}

		$refunded   = 0;
		$checked_in = 0;
		foreach ( $rows as $row ) {
			$status = (string) ( $row['status'] ?? '' );
			if ( RsvpPassesTable::STATUS_REVOKED === $status ) {
				++$refunded;
			} elseif ( RsvpPassesTable::STATUS_REDEEMED === $status ) {
				++$checked_in;
			}
		}

		$codes = array();
		foreach ( (array) ( $settings['ticket_codes'] ?? array() ) as $code ) {
			if ( is_array( $code ) && '' !== (string) ( $code['code'] ?? '' ) ) {
				$codes[ (string) $code['code'] ] = array(
					'code'           => (string) $code['code'],
					'uses'           => 0,
					'max_uses'       => (int) ( $code['max_uses'] ?? 0 ),
					'discount_minor' => 0,
				);
			}
		}

		$money = array(
			'gross_minor'   => 0,
			'fees_minor'    => 0,
			'refunds_minor' => 0,
		);
		foreach ( (array) $orders as $order ) {
			$money['gross_minor']   += (int) ( $order['total_minor'] ?? 0 );
			$money['fees_minor']    += (int) ( $order['fees_minor'] ?? 0 );
			$money['refunds_minor'] += (int) ( $order['refunded_minor'] ?? 0 );
			if ( 'refunded' === ( $order['state'] ?? '' ) ) {
				continue;
			}
			$type_id                    = (string) ( $order['ticket_type'] ?? '' );
			$type_id                    = '' === $type_id ? RsvpPassesTable::DEFAULT_TICKET_TYPE : $type_id;
			$type_row                   = self::type_row( $types, $type_id );
			$type_row['revenue_minor'] += (int) ( $order['subtotal_minor'] ?? 0 );
			$types[ $type_id ]          = $type_row;
			$code                       = (string) ( $order['code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			if ( ! isset( $codes[ $code ] ) ) {
				$codes[ $code ] = array(
					'code'           => $code,
					'uses'           => 0,
					'max_uses'       => 0,
					'discount_minor' => 0,
				);
			}
			$codes[ $code ]['uses']           += (int) ( $order['quantity'] ?? 0 );
			$codes[ $code ]['discount_minor'] += (int) ( $order['discount_minor'] ?? 0 );
		}

		$issued = $sold;
		return array(
			'tickets_enabled' => ! empty( $settings['tickets_enabled'] ),
			'currency'        => (string) ( $settings['ticket_currency'] ?? 'usd' ),
			'sold'            => $sold,
			'refunded'        => $refunded,
			'checked_in'      => $checked_in,
			'check_in_rate'   => $issued > 0 ? (int) round( 100 * $checked_in / $issued ) : 0,
			'money_available' => null !== $orders,
		) + $money + array(
			'net_minor'    => $money['gross_minor'] - $money['refunds_minor'] - $money['fees_minor'],
			'ticket_types' => array_values( $types ),
			'codes'        => array_values( $codes ),
		);
	}

	/**
	 * A type's report row, or a blank one for a type since removed from the
	 * show (it still reports by its ID).
	 *
	 * @param array<string, array{id: string, name: string, sold: int, quantity: int, revenue_minor: int}> $types Report rows by type ID.
	 * @param string                                                                                       $id    Type ID.
	 * @return array{id: string, name: string, sold: int, quantity: int, revenue_minor: int}
	 */
	private static function type_row( array $types, string $id ): array {
		return $types[ $id ] ?? array(
			'id'            => $id,
			'name'          => $id,
			'sold'          => 0,
			'quantity'      => 0,
			'revenue_minor' => 0,
		);
	}

	/**
	 * Door list export rows, header first.
	 *
	 * Each door row is one person: a paid ticket, a guest list name, an
	 * Eventbrite import, a band's guest, an owner's list name, or an account
	 * that RSVPed. Ticket rows take the buyer's email and code from the Shop
	 * order. Every cell is guarded against spreadsheet formulas.
	 *
	 * @param array[]  $door_rows  extrachill_events_guest_list_door_rows() result.
	 * @param array[]  $orders     Shop order rows (may be empty).
	 * @param string[] $type_names Ticket type ID => name.
	 * @param callable $local_time Formats a GMT MySQL datetime for the site.
	 * @return array[]
	 */
	public static function door_list_csv_rows( array $door_rows, array $orders, array $type_names, callable $local_time ): array {
		$by_reference = array_column( $orders, null, 'order_reference' );
		$out          = array( self::CSV_HEADER );
		foreach ( $door_rows as $row ) {
			$reference = (string) ( $row['order_reference'] ?? '' );
			$order     = '' !== $reference ? ( $by_reference[ $reference ] ?? array() ) : array();
			$type      = '' !== $reference ? RsvpPassesTable::ticket_type_of( $row ) : '';
			$email     = (string) ( $order['email'] ?? '' );
			$email     = '' !== $email ? $email : (string) ( $row['email'] ?? '' );
			$name      = (string) ( $row['guest_name'] ?? '' );
			$name      = '' !== $name ? $name : (string) ( $row['name'] ?? $row['display_name'] ?? '' );
			$checked   = (string) ( $row['checked_in_at'] ?? '' );
			$out[]     = array_map(
				array( self::class, 'csv_cell' ),
				array(
					$name,
					$email,
					self::door_source( $row ),
					'' !== $type ? (string) ( $type_names[ $type ] ?? $type ) : '',
					(string) ( $order['code'] ?? '' ),
					$reference,
					! empty( $row['checked_in'] ) && '' !== $checked ? (string) $local_time( $checked ) : '',
				)
			);
		}
		return $out;
	}

	/**
	 * Where a door row came from, in words.
	 *
	 * @param array $row Door row.
	 * @return string
	 */
	public static function door_source( array $row ): string {
		if ( 'attendee' === ( $row['kind'] ?? '' ) ) {
			return 'Guest list';
		}
		switch ( (string) ( $row['source_kind'] ?? '' ) ) {
			case 'ticket':
				return 'Ticket';
			case 'eventbrite':
				return 'Eventbrite';
			case 'band':
				return 'Band: ' . (string) ( $row['source'] ?? '' );
			case 'owner_list':
				return (string) ( $row['source'] ?? 'Owner list' );
			default:
				return 'Guest list';
		}
	}

	/**
	 * Neutralize a cell a spreadsheet would run as a formula.
	 *
	 * @param string $value Cell value.
	 * @return string
	 */
	public static function csv_cell( string $value ): string {
		return '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ? "'" . $value : $value;
	}

	/**
	 * Rows to CSV text (RFC 4180 quoting, CRLF line ends).
	 *
	 * @param array[] $rows Rows of string cells.
	 * @return string
	 */
	public static function to_csv( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$lines[] = implode(
				',',
				array_map(
					static function ( $cell ): string {
						$cell = (string) $cell;
						return preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
					},
					$row
				)
			);
		}
		return implode( "\r\n", $lines ) . "\r\n";
	}
}
