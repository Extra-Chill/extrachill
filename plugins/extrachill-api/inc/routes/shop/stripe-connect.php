<?php
/**
 * Stripe Connect REST API Endpoints
 *
 * Provides REST API endpoints for Stripe Connect account management.
 * All operations switch to shop blog context for Stripe SDK and WooCommerce access.
 *
 * Routes:
 * - POST /shop/stripe-connect/account - Create connected account
 * - GET /shop/stripe-connect/status - Get account status
 * - POST /shop/stripe-connect/onboarding-link - Generate onboarding URL
 * - POST /shop/stripe-connect/dashboard-link - Generate dashboard URL
 *
 * @package ExtraChillAPI
 * @since 0.1.6
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the shop blog ID.
 *
 * @return int|null Shop blog ID or null if not available.
 */
function extrachill_api_stripe_get_shop_blog_id() {
	return function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'shop' ) : null;
}

/**
 * Check if user has any artist profiles they can manage.
 *
 * @return bool True if user has manageable artists.
 */
function extrachill_api_stripe_user_has_artists() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return false;
	}

	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	if ( ! function_exists( 'ec_get_artists_for_user' ) ) {
		return false;
	}

	$artists = ec_get_artists_for_user( $user_id );
	return ! empty( $artists );
}

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
add_action( 'extrachill_api_register_routes', 'extrachill_api_register_stripe_connect_routes' );

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
			'You must be logged in to access this endpoint.',
			array( 'status' => 401 )
		);
	}

	$shop_blog_id = extrachill_api_stripe_get_shop_blog_id();
	if ( ! $shop_blog_id ) {
		return new WP_Error(
			'configuration_error',
			'Shop site is not configured.',
			array( 'status' => 500 )
		);
	}

	if ( ! extrachill_api_stripe_user_has_artists() ) {
		return new WP_Error(
			'not_an_artist',
			'You must be an artist to access this endpoint.',
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
	$shop_blog_id = extrachill_api_stripe_get_shop_blog_id();
	$user_id      = get_current_user_id();

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'extrachill_shop_create_stripe_account' ) ) {
			return new WP_Error(
				'stripe_not_available',
				'Stripe integration is not available.',
				array( 'status' => 500 )
			);
		}

		$result = extrachill_shop_create_stripe_account( $user_id );

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
	} finally {
		restore_current_blog();
	}
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

	$shop_blog_id = extrachill_api_stripe_get_shop_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
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

		// Update cached status (user meta is network-wide, works from any blog context).
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
	} finally {
		restore_current_blog();
	}
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
	$shop_blog_id = extrachill_api_stripe_get_shop_blog_id();
	$user_id      = get_current_user_id();
	$account_id   = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	switch_to_blog( $shop_blog_id );
	try {
		if ( ! function_exists( 'extrachill_shop_create_stripe_account' ) ) {
			return new WP_Error(
				'stripe_not_available',
				'Stripe integration is not available.',
				array( 'status' => 500 )
			);
		}

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

		if ( ! function_exists( 'extrachill_shop_create_account_link' ) ) {
			return new WP_Error(
				'stripe_not_available',
				'Stripe link creation is not available.',
				array( 'status' => 500 )
			);
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
	} finally {
		restore_current_blog();
	}
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
			'No Stripe account connected.',
			array( 'status' => 400 )
		);
	}

	$shop_blog_id = extrachill_api_stripe_get_shop_blog_id();

	switch_to_blog( $shop_blog_id );
	try {
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
	} finally {
		restore_current_blog();
	}
}
