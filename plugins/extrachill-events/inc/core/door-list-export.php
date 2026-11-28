<?php
/**
 * Door list CSV export and the "Buy tickets" door QR (extrachill#1746).
 *
 * The export is a host-only download of everyone on the door list with
 * their email, source, ticket type, code, order reference and check-in
 * time. It uses the same authorization as the door list itself
 * (extrachill_events_user_can_manage_event()) plus a nonce.
 *
 * The Buy tickets QR on the host screen links walk-ups to the event's
 * ticket page, and only shows while the sale is open (online sales stay
 * open through the show).
 *
 * @package ExtraChillEvents
 */

use ExtraChillEvents\Abilities\ShowTicketReportAbilities;
use ExtraChillEvents\Abilities\TicketAbilities;
use ExtraChillEvents\Abilities\TicketTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/Abilities/ShowTicketReportAbilities.php';

/**
 * Nonced download link for the door list CSV.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_door_list_csv_url( int $event_id ): string {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action'   => 'ec_door_list_csv',
				'event_id' => $event_id,
			),
			admin_url( 'admin-post.php' )
		),
		'ec_door_list_csv_' . $event_id
	);
}

/**
 * Build the door list CSV for a user who manages the event.
 *
 * @param int $event_id Event post ID.
 * @param int $user_id  Requesting user.
 * @return array{filename: string, csv: string}|\WP_Error
 */
function extrachill_events_door_list_export( int $event_id, int $user_id ) {
	if ( $user_id < 1 || 'data_machine_events' !== get_post_type( $event_id ) || ! extrachill_events_user_can_manage_event( $user_id, $event_id ) ) {
		return new \WP_Error( 'cannot_export_door_list', __( 'Only people who run this show can download its door list.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	$door_list = extrachill_events_get_door_list_rows( $event_id );
	$rows      = extrachill_events_guest_list_door_rows( $door_list['door_rows'] );
	$tickets   = false;
	foreach ( $rows as $i => $row ) {
		if ( '' !== (string) ( $row['order_reference'] ?? '' ) ) {
			$tickets = true;
		}
		if ( 'attendee' === ( $row['kind'] ?? '' ) && empty( $row['email'] ) ) {
			$user                = get_userdata( (int) ( $row['user_id'] ?? 0 ) );
			$rows[ $i ]['email'] = $user ? (string) $user->user_email : '';
		}
	}

	// Buyer emails and codes live on Shop's orders. Without seller authority
	// the export still lists every name, just without them.
	$orders     = $tickets ? (array) ShowTicketReportAbilities::shop_orders( $event_id ) : array();
	$type_names = array_column( TicketTypes::effective( TicketAbilities::settings( $event_id ) ), 'name', 'id' );
	$csv_rows   = ShowTicketReportAbilities::door_list_csv_rows(
		$rows,
		$orders,
		$type_names,
		static function ( string $gmt ): string {
			return (string) get_date_from_gmt( $gmt, 'Y-m-d H:i' );
		}
	);

	return array(
		'filename' => 'door-list-' . $event_id . '.csv',
		'csv'      => ShowTicketReportAbilities::to_csv( $csv_rows ),
	);
}

/**
 * Serve the door list CSV as a download.
 *
 * @hook admin_post_ec_door_list_csv
 */
function extrachill_events_serve_door_list_csv(): void {
	$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
	check_admin_referer( 'ec_door_list_csv_' . $event_id );

	$export = ec_is_events_site()
		? extrachill_events_door_list_export( $event_id, (int) get_current_user_id() )
		: new \WP_Error( 'cannot_export_door_list', __( 'Only people who run this show can download its door list.', 'extrachill-events' ) );
	if ( is_wp_error( $export ) ) {
		wp_die( esc_html( $export->get_error_message() ), '', array( 'response' => 403 ) );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $export['filename'] . '"' );
	echo $export['csv']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download body, cells formula-guarded.
	exit;
}
add_action( 'admin_post_ec_door_list_csv', 'extrachill_events_serve_door_list_csv' );

/**
 * Where the Buy tickets QR points: the event's ticket page while the sale
 * is open, else ''.
 *
 * @param mixed $offer Public ticket offer (extrachill/get-event-ticket-offer), or an error/null.
 * @return string
 */
function extrachill_events_buy_tickets_qr_target( $offer ): string {
	if ( ! is_array( $offer ) || empty( $offer['enabled'] ) || empty( $offer['sale_open'] ) ) {
		return '';
	}
	return (string) ( $offer['url'] ?? '' );
}

/**
 * Where the door's Buy tickets QR points for an event: its ticket page
 * while the sale is open, else ''.
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_door_buy_tickets_url( int $event_id ): string {
	return function_exists( 'extrachill_events_checkout_offer' ) ? extrachill_events_buy_tickets_qr_target( extrachill_events_checkout_offer( $event_id ) ) : '';
}

/**
 * The Buy tickets QR image URL (served to managers by
 * extrachill_events_serve_buy_tickets_qr()).
 *
 * @param int $event_id Event post ID.
 * @return string
 */
function extrachill_events_buy_tickets_qr_url( int $event_id ): string {
	return add_query_arg(
		array(
			'action'   => 'ec_buy_tickets_qr',
			'event_id' => $event_id,
		),
		admin_url( 'admin-post.php' )
	);
}

/**
 * The show's sales report for the host screen, or null when the show has
 * never sold tickets and doesn't sell them now.
 *
 * @param int $event_id Event post ID.
 * @return array|null
 */
function extrachill_events_door_ticket_report( int $event_id ): ?array {
	$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/get-show-ticket-report' ) : null;
	$report  = $ability ? $ability->execute( array( 'event_id' => $event_id ) ) : null;
	if ( ! is_array( $report ) ) {
		return null;
	}
	return empty( $report['tickets_enabled'] ) && 0 === (int) $report['sold'] && 0 === (int) $report['refunded'] ? null : $report;
}

/**
 * Serve the Buy tickets QR (PNG) to event managers while the sale is open.
 *
 * @hook admin_post_ec_buy_tickets_qr
 */
function extrachill_events_serve_buy_tickets_qr(): void {
	// Read-only image for a logged-in manager; authorization below, no state change.
	$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! ec_is_events_site() || ! extrachill_events_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		status_header( 403 );
		exit;
	}
	$url = extrachill_events_door_buy_tickets_url( $event_id );
	if ( '' === $url ) {
		status_header( 404 );
		exit;
	}
	extrachill_events_output_qr_png( $url, 'buy-tickets-' . $event_id . '.png' );
}
add_action( 'admin_post_ec_buy_tickets_qr', 'extrachill_events_serve_buy_tickets_qr' );
