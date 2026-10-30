<?php
/**
 * REST route: POST /wp-json/extrachill/v1/shop/stripe-webhook
 *
 * Centralized Stripe webhook route registration for the platform.
 * The business logic lives in the extrachill-shop plugin.
 *
 * @package ExtraChillAPI
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_api_register_routes', 'extrachill_api_register_shop_stripe_webhook_route' );

function extrachill_api_register_shop_stripe_webhook_route() {
	register_rest_route(
		'extrachill/v1',
		'/shop/stripe-webhook',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_handle_shop_stripe_webhook',
			'permission_callback' => '__return_true',
		)
	);
}

function extrachill_api_handle_shop_stripe_webhook( WP_REST_Request $request ) {
	if ( ! function_exists( 'extrachill_shop_handle_webhook' ) ) {
		return new WP_Error(
			'stripe_webhook_unavailable',
			__( 'Stripe webhook handler unavailable.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	return extrachill_shop_handle_webhook( $request );
}
