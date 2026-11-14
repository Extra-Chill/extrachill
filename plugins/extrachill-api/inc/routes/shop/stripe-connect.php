<?php
/**
 * Stripe Connect REST API Endpoints
 *
 * Thin adapters over the extrachill-shop Stripe Connect abilities. Any
 * seller type (artist, venue, promoter) is named with `seller_type` +
 * `seller_id`; the abilities own the permission check and Stripe calls.
 *
 * Routes:
 * - GET  /shop/stripe-connect/status          extrachill/shop-stripe-status
 * - POST /shop/stripe-connect/onboarding-link extrachill/shop-stripe-onboarding-link
 * - POST /shop/stripe-connect/dashboard-link  extrachill/shop-stripe-dashboard-link
 *
 * @package ExtraChillAPI
 * @since 0.1.6
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Stripe Connect REST routes.
 */
function extrachill_api_register_stripe_connect_routes() {
	$routes = array(
		'/shop/stripe-connect/status'          => array( WP_REST_Server::READABLE, 'extrachill/shop-stripe-status' ),
		'/shop/stripe-connect/onboarding-link' => array( WP_REST_Server::CREATABLE, 'extrachill/shop-stripe-onboarding-link' ),
		'/shop/stripe-connect/dashboard-link'  => array( WP_REST_Server::CREATABLE, 'extrachill/shop-stripe-dashboard-link' ),
	);

	foreach ( $routes as $route => $endpoint ) {
		extrachill_api_shop_register_ability_route(
			$route,
			array(
				array(
					'methods' => $endpoint[0],
					'ability' => $endpoint[1],
					'args'    => extrachill_api_shop_seller_args(),
				),
			)
		);
	}
}
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_stripe_connect_routes' );
