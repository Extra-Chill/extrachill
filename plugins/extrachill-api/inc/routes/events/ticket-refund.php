<?php
/**
 * Seller ticket refund from the Events site.
 *
 * The venue/promoter Tickets tab runs on Events; refunds are owned by Shop
 * (`extrachill/refund-ticket-order`). This route forwards the request to Shop
 * as the current user, and Shop applies its own seller finance check and the
 * never-after-check-in rule.
 *
 * @package ExtraChillAPI
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_ticket_refund_route' );

/** Register POST /extrachill/v1/events/tickets/orders/<reference>/refund. */
function extrachill_api_register_ticket_refund_route() {
	register_rest_route(
		'extrachill/v1',
		'/events/tickets/orders/(?P<reference>[A-Za-z0-9_-]{1,191})/refund',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_ticket_refund',
			'permission_callback' => 'is_user_logged_in',
		)
	);
}

/**
 * Forward a ticket refund to Shop.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function extrachill_api_ticket_refund( WP_REST_Request $request ) {
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
		return new WP_Error( 'ticket_refund_unavailable', 'Ticket refunds are unavailable.', array( 'status' => 503 ) );
	}
	$result = ec_cross_site_rest_request_http(
		'shop',
		'POST',
		'/wp-abilities/v1/abilities/extrachill/refund-ticket-order/run',
		array(
			'user_id' => get_current_user_id(),
			'body'    => array( 'input' => array( 'order_reference' => (string) $request['reference'] ) ),
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( array( 'refunded' => ! empty( $result['refunded'] ) ) );
}
