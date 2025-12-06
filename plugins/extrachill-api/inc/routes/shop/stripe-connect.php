<?php
/**
 * Stripe Connect REST API Endpoints
 *
 * Provides REST API endpoints for Stripe Connect account management:
 * - POST /stripe-connect/account - Create connected account
 * - GET /stripe-connect/status - Get account status
 * - POST /stripe-connect/onboarding-link - Generate onboarding URL
 * - POST /stripe-connect/dashboard-link - Generate dashboard URL
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

	// Create connected account.
	register_rest_route(
		$namespace,
		'/shop/stripe-connect/account',
		array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'extrachill_api_create_stripe_account',
			'permission_callback' => 'extrachill_api_stripe_connect_permission',
		)
	);

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
add_action( 'rest_api_init', 'extrachill_api_register_stripe_connect_routes' );

/**
 * Permission callback for Stripe Connect endpoints.
 *
 * User must be logged in and be an artist.
 *
 * @return bool|WP_Error True if permitted, WP_Error otherwise.
 */
function extrachill_api_stripe_connect_permission() {
	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'rest_not_logged_in',
			__( 'You must be logged in to access this endpoint.', 'extrachill-api' ),
			array( 'status' => 401 )
		);
	}

	if ( ! function_exists( 'extrachill_shop_user_is_artist' ) ) {
		return new WP_Error(
			'shop_not_active',
			__( 'Shop plugin is not active.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_shop_user_is_artist() ) {
		return new WP_Error(
			'not_an_artist',
			__( 'You must be an artist to access this endpoint.', 'extrachill-api' ),
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Create a Stripe connected account for the current user.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_create_stripe_account( $request ) {
	if ( ! function_exists( 'extrachill_shop_create_stripe_account' ) ) {
		return new WP_Error(
			'stripe_not_available',
			__( 'Stripe integration is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$user_id = get_current_user_id();
	$result  = extrachill_shop_create_stripe_account( $user_id );

	if ( ! $result['success'] ) {
		return new WP_Error(
			'stripe_account_creation_failed',
			$result['error'],
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'success'    => true,
			'account_id' => $result['account_id'],
		)
	);
}

/**
 * Get Stripe account status for the current user.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_stripe_status( $request ) {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	if ( empty( $account_id ) ) {
		return rest_ensure_response(
			array(
				'connected'            => false,
				'account_id'           => null,
				'status'               => null,
				'can_receive_payments' => false,
			)
		);
	}

	if ( ! function_exists( 'extrachill_shop_get_account_status' ) ) {
		return new WP_Error(
			'stripe_not_available',
			__( 'Stripe integration is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$status = extrachill_shop_get_account_status( $account_id );

	if ( ! $status['success'] ) {
		return new WP_Error(
			'stripe_status_check_failed',
			$status['error'],
			array( 'status' => 500 )
		);
	}

	// Update cached status.
	update_user_meta( $user_id, '_stripe_connect_status', $status['status'] );

	return rest_ensure_response(
		array(
			'connected'            => true,
			'account_id'           => $account_id,
			'status'               => $status['status'],
			'can_receive_payments' => $status['can_receive_payments'],
			'charges_enabled'      => $status['charges_enabled'],
			'payouts_enabled'      => $status['payouts_enabled'],
			'details_submitted'    => $status['details_submitted'],
		)
	);
}

/**
 * Get Stripe onboarding link for the current user.
 *
 * Creates account if needed, then returns onboarding URL.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_onboarding_link( $request ) {
	if ( ! function_exists( 'extrachill_shop_create_stripe_account' ) ) {
		return new WP_Error(
			'stripe_not_available',
			__( 'Stripe integration is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	// Create account if none exists.
	if ( empty( $account_id ) ) {
		$result = extrachill_shop_create_stripe_account( $user_id );

		if ( ! $result['success'] ) {
			return new WP_Error(
				'stripe_account_creation_failed',
				$result['error'],
				array( 'status' => 500 )
			);
		}

		$account_id = $result['account_id'];
	}

	// Generate onboarding link.
	$link_result = extrachill_shop_create_account_link( $account_id, 'account_onboarding' );

	if ( ! $link_result['success'] ) {
		return new WP_Error(
			'stripe_link_creation_failed',
			$link_result['error'],
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'url'     => $link_result['url'],
		)
	);
}

/**
 * Get Stripe dashboard link for the current user.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_dashboard_link( $request ) {
	$user_id    = get_current_user_id();
	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	if ( empty( $account_id ) ) {
		return new WP_Error(
			'no_stripe_account',
			__( 'No Stripe account connected.', 'extrachill-api' ),
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'extrachill_shop_create_dashboard_link' ) ) {
		return new WP_Error(
			'stripe_not_available',
			__( 'Stripe integration is not available.', 'extrachill-api' ),
			array( 'status' => 500 )
		);
	}

	$link_result = extrachill_shop_create_dashboard_link( $account_id );

	if ( ! $link_result['success'] ) {
		return new WP_Error(
			'stripe_dashboard_link_failed',
			$link_result['error'],
			array( 'status' => 500 )
		);
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'url'     => $link_result['url'],
		)
	);
}
