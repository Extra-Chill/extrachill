<?php
/**
 * Stripe Connect Core Functions
 *
 * Handles Stripe API initialization, Express account creation, and account management
 * for the artist marketplace. Uses Stripe Connect destination charges for payouts.
 *
 * Required wp-config.php constants:
 * - STRIPE_SECRET_KEY: Stripe secret API key
 * - STRIPE_PUBLISHABLE_KEY: Stripe publishable API key
 * - STRIPE_WEBHOOK_SECRET: Webhook signing secret
 *
 * @package ExtraChillShop
 * @since 0.2.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Initialize Stripe API client.
 *
 * Sets the API key from wp-config.php constant.
 *
 * @return bool True if initialized successfully.
 */
function extrachill_shop_stripe_init() {
	static $initialized = false;

	if ( $initialized ) {
		return true;
	}

	if ( ! defined( 'STRIPE_SECRET_KEY' ) || empty( STRIPE_SECRET_KEY ) ) {
		return false;
	}

	// Ensure Composer autoloader is loaded.
	$autoloader = EXTRACHILL_SHOP_PLUGIN_DIR . 'vendor/autoload.php';
	if ( ! file_exists( $autoloader ) ) {
		return false;
	}

	require_once $autoloader;

	\Stripe\Stripe::setApiKey( STRIPE_SECRET_KEY );
	\Stripe\Stripe::setApiVersion( '2023-10-16' );

	$initialized = true;
	return true;
}

/**
 * Check if Stripe is properly configured.
 *
 * @return bool True if Stripe keys are configured.
 */
function extrachill_shop_stripe_is_configured() {
	return defined( 'STRIPE_SECRET_KEY' ) && ! empty( STRIPE_SECRET_KEY )
		&& defined( 'STRIPE_PUBLISHABLE_KEY' ) && ! empty( STRIPE_PUBLISHABLE_KEY );
}

/**
 * Get the Stripe publishable key for frontend use.
 *
 * @return string|false Publishable key or false if not configured.
 */
function extrachill_shop_get_stripe_publishable_key() {
	if ( ! defined( 'STRIPE_PUBLISHABLE_KEY' ) ) {
		return false;
	}
	return STRIPE_PUBLISHABLE_KEY;
}

/**
 * Create a Stripe Express connected account for a user.
 *
 * @param int $user_id WordPress user ID.
 * @return array{success: bool, account_id?: string, error?: string}
 */
function extrachill_shop_create_stripe_account( $user_id ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return array(
			'success' => false,
			'error'   => 'User not found.',
		);
	}

	// Check if user already has an account.
	$existing_account = get_user_meta( $user_id, '_stripe_connect_account_id', true );
	if ( ! empty( $existing_account ) ) {
		return array(
			'success'    => true,
			'account_id' => $existing_account,
		);
	}

	try {
		$account = \Stripe\Account::create(
			array(
				'type'         => 'express',
				'email'        => $user->user_email,
				'capabilities' => array(
					'card_payments' => array( 'requested' => true ),
					'transfers'     => array( 'requested' => true ),
				),
				'business_type' => 'individual',
				'metadata'      => array(
					'wordpress_user_id' => $user_id,
					'platform'          => 'extrachill',
				),
			)
		);

		// Store account ID.
		update_user_meta( $user_id, '_stripe_connect_account_id', $account->id );
		update_user_meta( $user_id, '_stripe_connect_status', 'pending' );
		update_user_meta( $user_id, '_stripe_connect_onboarding_complete', '0' );

		return array(
			'success'    => true,
			'account_id' => $account->id,
		);

	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}
}

/**
 * Create an Account Link for Stripe Express onboarding or dashboard access.
 *
 * @param string $account_id Stripe connected account ID.
 * @param string $type       Link type: 'account_onboarding' or 'account_update'.
 * @return array{success: bool, url?: string, error?: string}
 */
function extrachill_shop_create_account_link( $account_id, $type = 'account_onboarding' ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	$return_url  = wc_get_account_endpoint_url( 'artist-settings' );
	$refresh_url = add_query_arg( 'stripe_refresh', '1', $return_url );

	try {
		$account_link = \Stripe\AccountLink::create(
			array(
				'account'     => $account_id,
				'refresh_url' => $refresh_url,
				'return_url'  => $return_url,
				'type'        => $type,
			)
		);

		return array(
			'success' => true,
			'url'     => $account_link->url,
		);

	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}
}

/**
 * Create a login link to the Stripe Express dashboard.
 *
 * @param string $account_id Stripe connected account ID.
 * @return array{success: bool, url?: string, error?: string}
 */
function extrachill_shop_create_dashboard_link( $account_id ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	try {
		$login_link = \Stripe\Account::createLoginLink( $account_id );

		return array(
			'success' => true,
			'url'     => $login_link->url,
		);

	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}
}

/**
 * Get account status from Stripe API and update local cache.
 *
 * @param string $account_id Stripe connected account ID.
 * @return array{success: bool, status?: string, can_receive_payments?: bool, error?: string}
 */
function extrachill_shop_get_account_status( $account_id ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	try {
		$account = \Stripe\Account::retrieve( $account_id );

		$status = 'pending';
		if ( $account->charges_enabled && $account->payouts_enabled ) {
			$status = 'active';
		} elseif ( $account->details_submitted ) {
			$status = 'restricted';
		}

		$can_receive_payments = $account->charges_enabled && $account->payouts_enabled;

		return array(
			'success'              => true,
			'status'               => $status,
			'can_receive_payments' => $can_receive_payments,
			'charges_enabled'      => $account->charges_enabled,
			'payouts_enabled'      => $account->payouts_enabled,
			'details_submitted'    => $account->details_submitted,
		);

	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}
}

/**
 * Check if a connected account can receive payments.
 *
 * Uses cached status first, falls back to API call.
 *
 * @param string $account_id Stripe connected account ID.
 * @return bool True if account can receive payments.
 */
function extrachill_shop_account_can_receive_payments( $account_id ) {
	$status = extrachill_shop_get_account_status( $account_id );

	if ( ! $status['success'] ) {
		return false;
	}

	return $status['can_receive_payments'];
}

/**
 * Update local account status cache from Stripe webhook data.
 *
 * @param string $account_id Stripe account ID.
 * @param array  $account_data Account data from webhook.
 */
function extrachill_shop_update_account_status_cache( $account_id, $account_data ) {
	global $wpdb;

	// Find user with this account ID.
	$user_id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '_stripe_connect_account_id' AND meta_value = %s LIMIT 1",
			$account_id
		)
	);

	if ( ! $user_id ) {
		return;
	}

	$status = 'pending';
	if ( ! empty( $account_data['charges_enabled'] ) && ! empty( $account_data['payouts_enabled'] ) ) {
		$status = 'active';
	} elseif ( ! empty( $account_data['details_submitted'] ) ) {
		$status = 'restricted';
	}

	update_user_meta( $user_id, '_stripe_connect_status', $status );

	$onboarding_complete = ( ! empty( $account_data['details_submitted'] ) ) ? '1' : '0';
	update_user_meta( $user_id, '_stripe_connect_onboarding_complete', $onboarding_complete );
}

/**
 * Get the Stripe account ID for a specific artist.
 *
 * Artists are linked to users, so this looks up the user who owns the artist
 * and returns their connected Stripe account.
 *
 * @param int $artist_profile_id Artist profile post ID (from Blog ID 4).
 * @return string|false Stripe account ID or false if not found.
 */
function extrachill_shop_get_artist_stripe_account( $artist_profile_id ) {
	// Switch to artist site to get artist data.
	$current_blog = get_current_blog_id();
	$artist_blog  = 4;

	if ( $current_blog !== $artist_blog ) {
		switch_to_blog( $artist_blog );
	}

	try {
		$artist = get_post( $artist_profile_id );
		if ( ! $artist || 'artist_profile' !== $artist->post_type ) {
			return false;
		}

		$user_id = $artist->post_author;

	} finally {
		if ( $current_blog !== $artist_blog ) {
			restore_current_blog();
		}
	}

	$account_id = get_user_meta( $user_id, '_stripe_connect_account_id', true );

	return ! empty( $account_id ) ? $account_id : false;
}

/**
 * Check if an artist has a connected Stripe account that can receive payments.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return bool True if artist can receive payments.
 */
function extrachill_shop_artist_can_receive_payments( $artist_profile_id ) {
	$account_id = extrachill_shop_get_artist_stripe_account( $artist_profile_id );

	if ( ! $account_id ) {
		return false;
	}

	return extrachill_shop_account_can_receive_payments( $account_id );
}
