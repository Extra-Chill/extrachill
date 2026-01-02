<?php
/**
 * Stripe Connect Core Functions
 *
 * Handles Stripe API initialization, Express account creation, and account management
 * for the artist marketplace. Uses Stripe Connect destination charges for payouts.
 *
 * API keys are configured via Network Admin > Extra Chill Multisite > Payments.
 * Keys can be overridden via filters (used by extrachill-dev for local testing).
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

	$secret_key = apply_filters(
		'extrachill_stripe_secret_key',
		get_site_option( 'extrachill_stripe_secret_key', '' )
	);

	if ( empty( $secret_key ) ) {
		return false;
	}

	// Ensure Composer autoloader is loaded.
	$autoloader = EXTRACHILL_SHOP_PLUGIN_DIR . 'vendor/autoload.php';
	if ( ! file_exists( $autoloader ) ) {
		return false;
	}

	require_once $autoloader;

	\Stripe\Stripe::setApiKey( $secret_key );
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
	$secret_key = apply_filters(
		'extrachill_stripe_secret_key',
		get_site_option( 'extrachill_stripe_secret_key', '' )
	);
	$publishable_key = apply_filters(
		'extrachill_stripe_publishable_key',
		get_site_option( 'extrachill_stripe_publishable_key', '' )
	);

	return ! empty( $secret_key ) && ! empty( $publishable_key );
}

/**
 * Get the Stripe publishable key for frontend use.
 *
 * @return string|false Publishable key or false if not configured.
 */
function extrachill_shop_get_stripe_publishable_key() {
	$publishable_key = apply_filters(
		'extrachill_stripe_publishable_key',
		get_site_option( 'extrachill_stripe_publishable_key', '' )
	);

	return ! empty( $publishable_key ) ? $publishable_key : false;
}

/**
 * Create a Stripe Express connected account for an artist profile.
 *
 * Account metadata and status are stored on the artist_profile post (artist blog).
 * The Stripe account is created using the artist owner's email (post_author).
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return array{success: bool, account_id?: string, error?: string}
 */
function extrachill_shop_create_stripe_account( $artist_profile_id ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	$artist_profile_id = absint( $artist_profile_id );
	if ( ! $artist_profile_id ) {
		return array(
			'success' => false,
			'error'   => 'Artist profile ID is required.',
		);
	}

	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return array(
			'success' => false,
			'error'   => 'Artist blog is not available.',
		);
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return array(
			'success' => false,
			'error'   => 'Artist blog is not available.',
		);
	}

	$current_blog = get_current_blog_id();

	$existing_account = '';
	$owner_user_id    = 0;
	$owner_email      = '';

	switch_to_blog( $artist_blog_id );
	try {
		$artist = get_post( $artist_profile_id );
		if ( ! $artist || 'artist_profile' !== $artist->post_type ) {
			return array(
				'success' => false,
				'error'   => 'Artist profile not found.',
			);
		}

		$existing_account = (string) get_post_meta( $artist_profile_id, '_stripe_connect_account_id', true );
		if ( $existing_account ) {
			return array(
				'success'    => true,
				'account_id' => $existing_account,
			);
		}

		$owner_user_id = (int) $artist->post_author;
		$owner         = get_userdata( $owner_user_id );
		if ( ! $owner ) {
			return array(
				'success' => false,
				'error'   => 'Artist owner user not found.',
			);
		}

		$owner_email = (string) $owner->user_email;
	} finally {
		restore_current_blog();
	}

	if ( get_current_blog_id() !== $current_blog ) {
		switch_to_blog( $current_blog );
	}

	try {
		$account = \Stripe\Account::create(
			array(
				'type'         => 'express',
				'email'        => $owner_email,
				'capabilities' => array(
					'card_payments' => array( 'requested' => true ),
					'transfers'     => array( 'requested' => true ),
				),
				'business_type' => 'individual',
				'metadata'      => array(
					'wordpress_user_id'     => $owner_user_id,
					'artist_profile_id'     => $artist_profile_id,
					'platform'              => 'extrachill',
				),
			)
		);
	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}

	switch_to_blog( $artist_blog_id );
	try {
		update_post_meta( $artist_profile_id, '_stripe_connect_account_id', $account->id );
		update_post_meta( $artist_profile_id, '_stripe_connect_status', 'pending' );
		update_post_meta( $artist_profile_id, '_stripe_connect_onboarding_complete', '0' );
	} finally {
		restore_current_blog();
	}

	if ( get_current_blog_id() !== $current_blog ) {
		switch_to_blog( $current_blog );
	}

	return array(
		'success'    => true,
		'account_id' => $account->id,
	);
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

	$return_url = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'artist' ) : null;
	if ( ! $return_url ) {
		return array(
			'success' => false,
			'error'   => 'Artist site URL is not available.',
		);
	}

	$return_url  = trailingslashit( $return_url ) . 'manage-shop/';
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
 * Stripe account status is cached on the artist_profile post (artist blog) using
 * the connected account ID.
 *
 * @param string $account_id Stripe account ID.
 * @param array  $account_data Account data from webhook.
 */
function extrachill_shop_update_account_status_cache( $account_id, $account_data ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return;
	}

	$status = 'pending';
	if ( ! empty( $account_data['charges_enabled'] ) && ! empty( $account_data['payouts_enabled'] ) ) {
		$status = 'active';
	} elseif ( ! empty( $account_data['details_submitted'] ) ) {
		$status = 'restricted';
	}

	$current_blog = get_current_blog_id();
	switch_to_blog( $artist_blog_id );
	try {
		global $wpdb;
		$artist_profile_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_stripe_connect_account_id' AND meta_value = %s LIMIT 1",
				$account_id
			)
		);

		if ( ! $artist_profile_id ) {
			return;
		}

		update_post_meta( (int) $artist_profile_id, '_stripe_connect_status', $status );

		$onboarding_complete = ( ! empty( $account_data['details_submitted'] ) ) ? '1' : '0';
		update_post_meta( (int) $artist_profile_id, '_stripe_connect_onboarding_complete', $onboarding_complete );
	} finally {
		restore_current_blog();
	}

	if ( get_current_blog_id() !== $current_blog ) {
		switch_to_blog( $current_blog );
	}
}

/**
 * Get the Stripe account ID for a specific artist.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return string|false Stripe account ID or false if not found.
 */
function extrachill_shop_get_artist_stripe_account( $artist_profile_id ) {
	if ( ! function_exists( 'ec_get_blog_id' ) ) {
		return false;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$artist = get_post( $artist_profile_id );
		if ( ! $artist || 'artist_profile' !== $artist->post_type ) {
			return false;
		}

		$account_id = (string) get_post_meta( $artist_profile_id, '_stripe_connect_account_id', true );
		return $account_id ? $account_id : false;
	} finally {
		restore_current_blog();
	}
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
