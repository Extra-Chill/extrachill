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
		extrachill_shop_resolve_stripe_secret_key()
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
	$secret_key      = apply_filters(
		'extrachill_stripe_secret_key',
		extrachill_shop_resolve_stripe_secret_key()
	);
	$publishable_key = apply_filters(
		'extrachill_stripe_publishable_key',
		extrachill_shop_resolve_stripe_publishable_key()
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
		extrachill_shop_resolve_stripe_publishable_key()
	);

	return ! empty( $publishable_key ) ? $publishable_key : false;
}

/**
 * Create a Stripe Express connected account for an artist profile.
 *
 * Account metadata and status are stored in Shop-owned options keyed by the
 * canonical Artist profile reference.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return array{success: bool, account_id?: string, error?: string}
 */
function extrachill_shop_create_stripe_account( $artist_profile_id ) {
	return extrachill_shop_create_seller_stripe_account(
		array(
			'type' => 'artist',
			'id'   => absint( $artist_profile_id ),
		)
	);
}

/**
 * Create (or return) the Stripe Express connected account for a seller.
 *
 * Artists keep their existing account shape (individual business type and
 * `artist_reference` metadata). Venues and promoters choose their business
 * type during Stripe onboarding.
 *
 * @param array $seller Seller reference { type: artist|venue|promoter, id }.
 * @return array{success: bool, account_id?: string, error?: string}
 */
function extrachill_shop_create_seller_stripe_account( $seller ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	$is_artist = is_array( $seller ) && 'artist' === ( $seller['type'] ?? '' );
	$seller    = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return array(
			'success' => false,
			'error'   => $is_artist ? 'Artist profile ID is required.' : 'A valid seller is required.',
		);
	}

	$canonical = extrachill_shop_get_canonical_seller( $seller );
	if ( is_wp_error( $canonical ) ) {
		return array(
			'success' => false,
			'error'   => $is_artist ? 'Artist profile not found.' : 'Seller not found.',
		);
	}

	$existing = extrachill_shop_get_seller_stripe_account_record( $seller );
	if ( $existing ) {
		return array(
			'success'    => true,
			'account_id' => $existing['account_id'],
		);
	}

	$params = array(
		'type'         => 'express',
		'capabilities' => array(
			'card_payments' => array( 'requested' => true ),
			'transfers'     => array( 'requested' => true ),
		),
	);
	if ( $is_artist ) {
		$params['business_type'] = 'individual';
		$params['metadata']      = array(
			'artist_reference' => 'artist:' . $seller['id'],
			'platform'         => 'extrachill',
		);
	} else {
		$params['metadata'] = array(
			'seller_reference' => $seller['type'] . ':' . $seller['id'],
			'platform'         => 'extrachill',
		);
	}

	try {
		$account = \Stripe\Account::create( $params );
	} catch ( \Stripe\Exception\ApiErrorException $e ) {
		return array(
			'success' => false,
			'error'   => $e->getMessage(),
		);
	}

	if ( ! extrachill_shop_set_seller_stripe_account_record(
		$seller,
		array(
			'account_id'          => $account->id,
			'status'              => 'pending',
			'onboarding_complete' => false,
		)
	) ) {
		return array(
			'success' => false,
			'error'   => 'Stripe account binding could not be stored.',
		);
	}

	return array(
		'success'    => true,
		'account_id' => $account->id,
	);
}

/**
 * Create an Account Link for Stripe Express onboarding or dashboard access.
 *
 * @param string     $account_id Stripe connected account ID.
 * @param string     $type       Link type: 'account_onboarding' or 'account_update'.
 * @param array|null $seller     Optional seller reference; artists (default) return to the artist shop manager.
 * @return array{success: bool, url?: string, error?: string}
 */
function extrachill_shop_create_account_link( $account_id, $type = 'account_onboarding', $seller = null ) {
	if ( ! extrachill_shop_stripe_init() ) {
		return array(
			'success' => false,
			'error'   => 'Stripe is not configured.',
		);
	}

	if ( is_array( $seller ) && 'artist' !== ( $seller['type'] ?? 'artist' ) ) {
		// Venues and promoters return to their Events profile until a seller shop UI exists.
		$canonical  = extrachill_shop_get_canonical_seller( $seller );
		$return_url = is_wp_error( $canonical ) ? '' : (string) $canonical['url'];
		if ( ! $return_url ) {
			return array(
				'success' => false,
				'error'   => 'Seller URL is not available.',
			);
		}
	} else {
		$return_url = ec_get_site_url( 'artist' );
		if ( ! $return_url ) {
			return array(
				'success' => false,
				'error'   => 'Artist site URL is not available.',
			);
		}
		$return_url = trailingslashit( $return_url ) . 'manage-shop/';
	}

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
 * Stripe account status is cached in Shop-owned storage.
 *
 * @param string $account_id Stripe account ID.
 * @param array  $account_data Account data from webhook.
 */
function extrachill_shop_update_account_status_cache( $account_id, $account_data ) {
	$status = 'pending';
	if ( ! empty( $account_data['charges_enabled'] ) && ! empty( $account_data['payouts_enabled'] ) ) {
		$status = 'active';
	} elseif ( ! empty( $account_data['details_submitted'] ) ) {
		$status = 'restricted';
	}

	extrachill_shop_update_stripe_account_by_id( $account_id, $status, ! empty( $account_data['details_submitted'] ) );
}

/**
 * Get the Stripe account ID for a specific artist.
 *
 * @param int $artist_profile_id Artist profile post ID.
 * @return string|false Stripe account ID or false if not found.
 */
function extrachill_shop_get_artist_stripe_account( $artist_profile_id ) {
	return extrachill_shop_get_seller_stripe_account(
		array(
			'type' => 'artist',
			'id'   => absint( $artist_profile_id ),
		)
	);
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
