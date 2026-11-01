<?php
/**
 * Stripe Connect REST API Endpoints
 *
 * Provides REST API endpoints for Stripe Connect account management.
 * These routes are called on the shop site directly where WooCommerce and
 * the Stripe SDK are available. Artist meta is accessed via switch_to_blog().
 *
 * Routes:
 * - GET /shop/stripe-connect/status - Get account status
 * - POST /shop/stripe-connect/onboarding-link - Generate onboarding URL
 * - POST /shop/stripe-connect/dashboard-link - Generate dashboard URL
 *
 * @package ExtraChillAPI
 * @since 0.1.6
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register Stripe Connect REST routes.
 */
function extrachill_api_register_stripe_connect_routes() {
	$namespace = 'extrachill/v1';

	// Get account status.
	register_rest_route(
		$namespace,
		'/shop/stripe-connect/status',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'extrachill_api_get_stripe_status',
			'permission_callback' => 'extrachill_api_stripe_connect_permission',
		)
	);

	// Generate onboarding link.
	register_rest_route(
		$namespace,
		'/shop/stripe-connect/onboarding-link',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_get_onboarding_link',
			'permission_callback' => 'extrachill_api_stripe_connect_permission',
		)
	);

	// Generate dashboard link.
	register_rest_route(
		$namespace,
		'/shop/stripe-connect/dashboard-link',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_get_dashboard_link',
			'permission_callback' => 'extrachill_api_stripe_connect_permission',
		)
	);
}
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_stripe_connect_routes' );

/**
 * Permission callback for Stripe Connect endpoints.
 *
 * Stripe Connect is scoped to a seller. Artist requests pass `artist_id`,
 * checked here; venue and promoter requests pass `seller_type` + `seller_id`
 * and are authorized by the Shop ability when it executes.
 *
 * @param WP_REST_Request $request Request object.
 * @return bool|WP_Error True if permitted, WP_Error otherwise.
 */
function extrachill_api_stripe_connect_permission( $request ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			'You must be logged in to access this endpoint.',
			array( 'status' => 401 )
		);
	}

	// Venue and promoter sellers are authorized by the Shop ability (Events-owned finance authority).
	if ( null !== $request->get_param( 'seller_type' ) ) {
		return true;
	}

	$artist_id = absint( $request->get_param( 'artist_id' ) );
	if ( ! $artist_id ) {
		return new WP_Error(
			'missing_artist_id',
			'Artist ID is required.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'ec_can_manage_artist' ) ) {
		return new WP_Error(
			'artist_permissions_unavailable',
			'Artist permissions are not available.',
			array( 'status' => 500 )
		);
	}

	if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
		return new WP_Error(
			'cannot_manage_artist',
			'You do not have access to this artist.',
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Get Stripe account status for an artist profile.
 *
 * Wraps the extrachill/shop-stripe-status ability from extrachill-shop.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_stripe_status( $request ) {
	$ability = wp_get_ability( 'extrachill/shop-stripe-status' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( extrachill_api_stripe_connect_input( $request ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Get Stripe onboarding link for an artist profile.
 *
 * Wraps the extrachill/shop-stripe-onboarding-link ability from extrachill-shop.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_onboarding_link( $request ) {
	$ability = wp_get_ability( 'extrachill/shop-stripe-onboarding-link' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( extrachill_api_stripe_connect_input( $request ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Get Stripe dashboard link for an artist profile.
 *
 * Wraps the extrachill/shop-stripe-dashboard-link ability from extrachill-shop.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_dashboard_link( $request ) {
	$ability = wp_get_ability( 'extrachill/shop-stripe-dashboard-link' );
	if ( ! $ability ) {
		return new WP_Error( 'ability_not_found', 'extrachill-shop plugin is required.', array( 'status' => 500 ) );
	}

	$result = $ability->execute( extrachill_api_stripe_connect_input( $request ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Map a Stripe Connect request to Shop ability input.
 *
 * @param WP_REST_Request $request Request object.
 * @return array Ability input with `seller` or `artist_id`.
 */
function extrachill_api_stripe_connect_input( $request ) {
	if ( null !== $request->get_param( 'seller_type' ) ) {
		return array(
			'seller' => array(
				'type' => sanitize_key( (string) $request->get_param( 'seller_type' ) ),
				'id'   => absint( $request->get_param( 'seller_id' ) ),
			),
		);
	}
	return array( 'artist_id' => absint( $request->get_param( 'artist_id' ) ) );
}
