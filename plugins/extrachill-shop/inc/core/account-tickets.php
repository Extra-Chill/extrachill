<?php
/**
 * My Account > Tickets.
 *
 * Ticket orders skip WooCommerce (they are Shop option rows settled on the
 * seller's Stripe account), so WooCommerce's Orders tab never lists them.
 * This tab lists a logged-in buyer's ticket orders from the buyer index that
 * ticket checkout writes, with each order's passes read from Events through
 * the existing `get-ticket-order-status` service ability.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** My Account endpoint slug. */
const EXTRACHILL_SHOP_TICKETS_ENDPOINT = 'tickets';

/** Ticket order statuses a buyer sees. Pending/expired/failed Sessions were never paid. */
const EXTRACHILL_SHOP_BUYER_TICKET_STATUSES = array( 'issued', 'refund_pending', 'refunded', 'disputed' );

add_filter( 'woocommerce_get_query_vars', 'extrachill_shop_tickets_query_var' );
add_filter( 'woocommerce_account_menu_items', 'extrachill_shop_tickets_menu_item' );
add_filter( 'woocommerce_endpoint_' . EXTRACHILL_SHOP_TICKETS_ENDPOINT . '_title', 'extrachill_shop_tickets_endpoint_title' );
add_action( 'woocommerce_account_' . EXTRACHILL_SHOP_TICKETS_ENDPOINT . '_endpoint', 'extrachill_shop_render_account_tickets' );
add_action( 'init', 'extrachill_shop_maybe_flush_tickets_endpoint', 99 );

/**
 * Register the endpoint with WooCommerce, which adds its rewrite rule.
 *
 * @param mixed $vars WooCommerce query vars.
 * @return array
 */
function extrachill_shop_tickets_query_var( $vars ) {
	$vars                                     = is_array( $vars ) ? $vars : array();
	$vars[ EXTRACHILL_SHOP_TICKETS_ENDPOINT ] = EXTRACHILL_SHOP_TICKETS_ENDPOINT;
	return $vars;
}

/**
 * Add "Tickets" to the My Account menu, right after Orders.
 *
 * @param mixed $items Menu items keyed by endpoint.
 * @return array
 */
function extrachill_shop_tickets_menu_item( $items ) {
	$items  = is_array( $items ) ? $items : array();
	$label  = __( 'Tickets', 'extrachill-shop' );
	$result = array();
	foreach ( $items as $key => $item ) {
		$result[ $key ] = $item;
		if ( 'orders' === $key ) {
			$result[ EXTRACHILL_SHOP_TICKETS_ENDPOINT ] = $label;
		}
	}
	if ( ! isset( $result[ EXTRACHILL_SHOP_TICKETS_ENDPOINT ] ) ) {
		$result[ EXTRACHILL_SHOP_TICKETS_ENDPOINT ] = $label;
	}
	return $result;
}

/**
 * Page title for the Tickets endpoint.
 *
 * @return string
 */
function extrachill_shop_tickets_endpoint_title() {
	return __( 'Tickets', 'extrachill-shop' );
}

/**
 * Flush rewrite rules once so the new endpoint resolves.
 *
 * @return void
 */
function extrachill_shop_maybe_flush_tickets_endpoint() {
	if ( EXTRACHILL_SHOP_TICKETS_ENDPOINT === get_option( 'extrachill_shop_tickets_endpoint_flushed' ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'extrachill_shop_tickets_endpoint_flushed', EXTRACHILL_SHOP_TICKETS_ENDPOINT );
}

/**
 * A buyer's ticket orders, newest first, with their passes.
 *
 * Reads only references indexed under the buyer and re-checks each order's
 * recorded buyer, so one buyer never sees another buyer's tickets.
 *
 * @param int $user_id Buyer user ID.
 * @param int $limit   Most orders to return.
 * @return array[]
 */
function extrachill_shop_get_buyer_ticket_orders( int $user_id, int $limit = 50 ): array {
	if ( $user_id < 1 ) {
		return array();
	}
	$references = array_reverse( array_unique( array_map( 'strval', (array) get_user_meta( $user_id, EXTRACHILL_SHOP_BUYER_TICKET_ORDER_META, false ) ) ) );
	$orders     = array();
	foreach ( $references as $reference ) {
		if ( count( $orders ) >= $limit ) {
			break;
		}
		$order = get_option( 'extrachill_ticket_order_' . $reference );
		if ( ! is_array( $order ) || (int) ( $order['buyer_user_id'] ?? 0 ) !== $user_id || ! in_array( $order['status'] ?? '', EXTRACHILL_SHOP_BUYER_TICKET_STATUSES, true ) ) {
			continue;
		}
		$orders[] = array(
			'reference'   => $reference,
			'event_id'    => (int) ( $order['event'] ?? 0 ),
			'event_title' => (string) ( $order['event_title'] ?? '' ),
			'event_url'   => (string) ( $order['event_url'] ?? '' ),
			'quantity'    => (int) ( $order['quantity'] ?? ( $order['sales']['tickets_sold'] ?? 0 ) ),
			'status'      => (string) $order['status'],
			'created_at'  => (string) ( $order['created_at'] ?? '' ),
			'passes'      => extrachill_shop_buyer_ticket_passes( $reference ),
		);
	}
	return $orders;
}

/**
 * Passes on one ticket order, read from Events.
 *
 * @param string $reference Ticket order reference.
 * @return array[] { code, status, url }; empty when Events is unreachable.
 */
function extrachill_shop_buyer_ticket_passes( string $reference ): array {
	$status = extrachill_shop_execute_ticket_owner_ability( 'get-ticket-order-status', array( 'order_reference' => $reference ) );
	if ( is_wp_error( $status ) || ! is_array( $status ) || ! is_array( $status['passes'] ?? null ) ) {
		return array();
	}
	$passes = array();
	foreach ( $status['passes'] as $pass ) {
		if ( ! is_array( $pass ) || empty( $pass['code'] ) ) {
			continue;
		}
		$passes[] = array(
			'code'   => (string) $pass['code'],
			'status' => (string) ( $pass['status'] ?? '' ),
			'url'    => (string) ( $pass['url'] ?? '' ),
		);
	}
	return $passes;
}

/**
 * Ability: the current user's ticket orders.
 *
 * @param mixed $input Ability input (unused).
 * @return array{orders: array[]}
 */
function extrachill_shop_list_my_ticket_orders( $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Ability callback signature.
	return array( 'orders' => extrachill_shop_get_buyer_ticket_orders( get_current_user_id() ) );
}

/**
 * Buyer-facing label for a ticket order status.
 *
 * @param string $status Order status.
 * @return string
 */
function extrachill_shop_ticket_order_status_label( string $status ): string {
	$labels = array(
		'issued'         => __( 'Confirmed', 'extrachill-shop' ),
		'refund_pending' => __( 'Refund in progress', 'extrachill-shop' ),
		'refunded'       => __( 'Refunded', 'extrachill-shop' ),
		'disputed'       => __( 'Disputed', 'extrachill-shop' ),
	);
	return $labels[ $status ] ?? $status;
}

/**
 * Buyer-facing label for a pass status.
 *
 * @param string $status Pass status.
 * @return string
 */
function extrachill_shop_ticket_pass_status_label( string $status ): string {
	$labels = array(
		'active'   => __( 'Ready', 'extrachill-shop' ),
		'redeemed' => __( 'Checked in', 'extrachill-shop' ),
		'revoked'  => __( 'Cancelled', 'extrachill-shop' ),
	);
	return $labels[ $status ] ?? $status;
}

/**
 * Markup for the Tickets tab.
 *
 * @param array[] $orders Orders from extrachill_shop_get_buyer_ticket_orders().
 * @return string HTML.
 */
function extrachill_shop_account_tickets_html( array $orders ): string {
	if ( empty( $orders ) ) {
		return '<p class="notice notice-info">' . esc_html__( 'You have no ticket orders yet. Tickets you buy while logged in show up here.', 'extrachill-shop' ) . '</p>';
	}
	$html = '';
	foreach ( $orders as $order ) {
		$title = '' !== $order['event_title'] ? $order['event_title'] : __( 'Ticket order', 'extrachill-shop' );
		$html .= '<section class="ec-surface-card ec-card-padding">';
		$html .= '<h3>' . ( '' !== $order['event_url'] ? '<a href="' . esc_url( $order['event_url'] ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</h3>';
		$html .= '<p>' . esc_html(
			sprintf(
				/* translators: 1: ticket count, 2: order status. */
				__( 'Tickets: %1$d · %2$s', 'extrachill-shop' ),
				$order['quantity'],
				extrachill_shop_ticket_order_status_label( $order['status'] )
			)
		) . '</p>';
		if ( ! empty( $order['passes'] ) ) {
			$html .= '<ul>';
			foreach ( $order['passes'] as $pass ) {
				$html .= '<li>' . esc_html( $pass['code'] . ' · ' . extrachill_shop_ticket_pass_status_label( $pass['status'] ) );
				if ( '' !== $pass['url'] && 'active' === $pass['status'] ) {
					$html .= ' <a class="button-2 button-small" href="' . esc_url( $pass['url'] ) . '">' . esc_html__( 'Open pass', 'extrachill-shop' ) . '</a>';
				}
				$html .= '</li>';
			}
			$html .= '</ul>';
		} elseif ( 'issued' === $order['status'] ) {
			$html .= '<p>' . esc_html__( 'Your passes were emailed to you.', 'extrachill-shop' ) . '</p>';
		}
		$html .= '</section>';
	}
	return $html;
}

/**
 * Render the Tickets tab for the logged-in buyer.
 *
 * @return void
 */
function extrachill_shop_render_account_tickets() {
	echo extrachill_shop_account_tickets_html( extrachill_shop_get_buyer_ticket_orders( get_current_user_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while building.
}
