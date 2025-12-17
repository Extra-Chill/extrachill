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
 * Stripe Connect is scoped to an artist profile. The request must include
 * an artist ID that the current user can manage.
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
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_stripe_status( $request ) {
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error(
			'configuration_error',
			'Artist blog is not configured.',
			array( 'status' => 500 )
		);
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Artist blog is not configured.',
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $artist_blog_id );
	try {
		$account_id = (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true );
	} finally {
		restore_current_blog();
	}

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
			'Stripe integration is not available.',
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

	$safe_status = isset( $status['status'] ) ? (string) $status['status'] : '';
	if ( $safe_status ) {
		switch_to_blog( $artist_blog_id );
		try {
			update_post_meta( $artist_id, '_stripe_connect_status', $safe_status );
			update_post_meta( $artist_id, '_stripe_connect_onboarding_complete', ! empty( $status['details_submitted'] ) ? '1' : '0' );
		} finally {
			restore_current_blog();
		}
	}

	return rest_ensure_response(
		array(
			'connected'            => true,
			'account_id'           => $account_id,
			'status'               => $safe_status,
			'can_receive_payments' => ! empty( $status['can_receive_payments'] ),
			'charges_enabled'      => ! empty( $status['charges_enabled'] ),
			'payouts_enabled'      => ! empty( $status['payouts_enabled'] ),
			'details_submitted'    => ! empty( $status['details_submitted'] ),
		)
	);
}

/**
 * Get Stripe onboarding link for an artist profile.
 *
 * Creates account if needed, then returns onboarding URL.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_onboarding_link( $request ) {
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	if ( ! function_exists( 'extrachill_shop_create_stripe_account' ) ) {
		return new WP_Error(
			'stripe_not_available',
			'Stripe integration is not available.',
			array( 'status' => 500 )
		);
	}

	$result = extrachill_shop_create_stripe_account( $artist_id );
	if ( ! $result['success'] ) {
		return new WP_Error(
			'stripe_account_creation_failed',
			$result['error'],
			array( 'status' => 500 )
		);
	}

	$account_id = $result['account_id'];

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
 * Get Stripe dashboard link for an artist profile.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error Response.
 */
function extrachill_api_get_dashboard_link( $request ) {
	$artist_id = absint( $request->get_param( 'artist_id' ) );

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return new WP_Error(
			'configuration_error',
			'Artist blog is not configured.',
			array( 'status' => 500 )
		);
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Artist blog is not configured.',
			array( 'status' => 500 )
		);
	}

	switch_to_blog( $artist_blog_id );
	try {
		$account_id = (string) get_post_meta( $artist_id, '_stripe_connect_account_id', true );
	} finally {
		restore_current_blog();
	}

	if ( empty( $account_id ) ) {
		return new WP_Error(
			'no_stripe_account',
			'No Stripe account connected.',
			array( 'status' => 400 )
		);
	}

	if ( ! function_exists( 'extrachill_shop_create_dashboard_link' ) ) {
		return new WP_Error(
			'stripe_not_available',
			'Stripe integration is not available.',
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
