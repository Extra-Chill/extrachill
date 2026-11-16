<?php
/**
 * Shop-owned commerce state and owner contract clients.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/** Seconds a live Stripe account check is reused while the status is unknown. */
if ( ! defined( 'EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL' ) ) {
	define( 'EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL', 120 );
}

/**
 * Execute an ability in its owning site runtime.
 *
 * @param string $site_key Site registry key.
 * @param string $ability  Ability name.
 * @param string $method   HTTP method.
 * @param array  $input    Ability input.
 * @param int    $user_id  Actor to run as; defaults to the current user.
 * @return array|WP_Error
 */
function extrachill_shop_execute_owner_ability( $site_key, $ability, $method, array $input, $user_id = null ) {
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
		return new WP_Error( 'owner_runtime_unavailable', __( 'The owning site runtime is unavailable.', 'extrachill-shop' ), array( 'status' => 503 ) );
	}

	$args = array( 'user_id' => null === $user_id ? get_current_user_id() : (int) $user_id );
	if ( 'GET' === $method ) {
		$args['query'] = array( 'input' => $input );
	} else {
		$args['body'] = array( 'input' => $input );
	}

	return ec_cross_site_rest_request_http(
		$site_key,
		$method,
		'/wp-abilities/v1/abilities/' . $ability . '/run',
		$args
	);
}

/**
 * Read canonical artist identity from the Artist owner.
 *
 * @param int $artist_id Artist profile ID.
 * @return array|WP_Error
 */
function extrachill_shop_get_canonical_artist( $artist_id ) {
	static $artists = array();

	$artist_id = absint( $artist_id );
	if ( array_key_exists( $artist_id, $artists ) ) {
		return $artists[ $artist_id ];
	}

	$artists[ $artist_id ] = extrachill_shop_execute_owner_ability(
		'artist',
		'extrachill/artist-get',
		'GET',
		array( 'id' => $artist_id )
	);
	return $artists[ $artist_id ];
}

/**
 * Validate a typed seller reference without coercing invalid IDs.
 *
 * @param mixed $seller Seller reference.
 * @return array|WP_Error
 */
function extrachill_shop_validate_seller_ref( $seller ) {
	if ( ! is_array( $seller )
		|| ! in_array( $seller['type'] ?? '', array( 'artist', 'venue', 'promoter' ), true )
		|| ! is_int( $seller['id'] ?? null ) || $seller['id'] < 1 ) {
		return new WP_Error( 'invalid_seller', __( 'A valid seller type and positive integer ID are required.', 'extrachill-shop' ), array( 'status' => 400 ) );
	}
	return array(
		'type' => $seller['type'],
		'id'   => $seller['id'],
	);
}

/**
 * Resolve the typed `seller` reference from ability input.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Seller reference.
 */
function extrachill_shop_seller_from_input( array $input ) {
	if ( ! isset( $input['seller'] ) ) {
		return new WP_Error( 'missing_seller', __( 'A seller is required.', 'extrachill-shop' ), array( 'status' => 400 ) );
	}
	return extrachill_shop_validate_seller_ref( $input['seller'] );
}

/**
 * Stable grouping key for a seller: the artist ID for artists, `type:id` otherwise.
 *
 * Artist keys stay integers so existing artist payout records keep their shape.
 *
 * @param array $seller Seller reference.
 * @return int|string
 */
function extrachill_shop_seller_key( array $seller ) {
	return 'artist' === $seller['type'] ? (int) $seller['id'] : $seller['type'] . ':' . (int) $seller['id'];
}

/**
 * Resolve a seller's canonical identity from its owning site.
 *
 * Artists resolve through the Artist owner; venues and promoters through the
 * Events owner (`extrachill/get-commerce-seller`). Identity only: whether a
 * user may manage the seller is `extrachill_shop_can_manage_seller()`.
 *
 * @param mixed $seller Seller reference.
 * @return array|WP_Error { type, id, name, slug, url, manage_url? }
 */
function extrachill_shop_get_canonical_seller( $seller ) {
	static $sellers = array();

	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	$key = $seller['type'] . ':' . $seller['id'] . ':' . get_current_user_id();
	if ( array_key_exists( $key, $sellers ) ) {
		return $sellers[ $key ];
	}

	$not_found = new WP_Error( 'seller_not_found', __( 'Seller not found.', 'extrachill-shop' ), array( 'status' => 404 ) );
	if ( 'artist' === $seller['type'] ) {
		$artist = extrachill_shop_get_canonical_artist( $seller['id'] );
		if ( is_wp_error( $artist ) ) {
			$sellers[ $key ] = $artist;
		} elseif ( absint( $artist['id'] ?? 0 ) !== $seller['id'] ) {
			$sellers[ $key ] = $not_found;
		} else {
			$sellers[ $key ] = array(
				'type' => 'artist',
				'id'   => $seller['id'],
				'name' => (string) ( $artist['name'] ?? '' ),
				'slug' => (string) ( $artist['slug'] ?? '' ),
				'url'  => (string) ( $artist['permalink'] ?? '' ),
			);
		}
		return $sellers[ $key ];
	}

	$result = extrachill_shop_execute_owner_ability( 'events', 'extrachill/get-commerce-seller', 'GET', $seller );
	if ( is_wp_error( $result ) ) {
		$sellers[ $key ] = $result;
	} elseif ( ( $result['type'] ?? '' ) !== $seller['type'] || absint( $result['id'] ?? 0 ) !== $seller['id'] ) {
		$sellers[ $key ] = $not_found;
	} else {
		$sellers[ $key ] = array(
			'type'       => $seller['type'],
			'id'         => $seller['id'],
			'name'       => (string) ( $result['name'] ?? '' ),
			'slug'       => (string) ( $result['slug'] ?? '' ),
			'url'        => (string) ( $result['url'] ?? '' ),
			'manage_url' => (string) ( $result['manage_url'] ?? '' ),
		);
	}
	return $sellers[ $key ];
}

/**
 * Ability input schema fragment for a typed seller reference.
 *
 * @return array
 */
function extrachill_shop_seller_input_schema() {
	return array(
		'type'                 => 'object',
		'description'          => 'Typed seller reference (artist, venue or promoter).',
		'properties'           => array(
			'type' => array(
				'type' => 'string',
				'enum' => array( 'artist', 'venue', 'promoter' ),
			),
			'id'   => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		),
		'required'             => array( 'type', 'id' ),
		'additionalProperties' => false,
	);
}

/**
 * Read a Shop-owned ship-from address.
 *
 * @param array $seller Seller reference.
 * @return array|false
 */
function extrachill_shop_get_seller_shipping_address( $seller ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return false;
	}
	$key     = 'extrachill_shop_shipping_seller_' . $seller['type'] . ':' . $seller['id'];
	$address = get_option( $key, false );
	return is_array( $address ) ? $address : false;
}

/**
 * Persist a seller's ship-from address on Shop, including idempotent saves.
 *
 * @param array $seller Seller reference.
 * @param array $address Shipping address.
 * @return bool
 */
function extrachill_shop_set_seller_shipping_address( $seller, array $address ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return false;
	}
	$key      = 'extrachill_shop_shipping_seller_' . $seller['type'] . ':' . $seller['id'];
	$existing = get_option( $key, false );
	// The return policy shares this record; an address save keeps it.
	if ( ! array_key_exists( 'return_policy', $address ) && is_array( $existing ) && isset( $existing['return_policy'] ) ) {
		$address['return_policy'] = $existing['return_policy'];
	}
	return update_option( $key, $address, false ) || get_option( $key, false ) === $address;
}

/** Longest return policy a seller can publish, in characters. */
const EXTRACHILL_SHOP_RETURN_POLICY_MAX = 2000;

/**
 * A seller's published shipping times and return policy ('' when unset).
 *
 * Stored with the seller's shipping settings (the ship-from address record).
 *
 * @param mixed $seller Seller reference.
 * @return string Plain text.
 */
function extrachill_shop_get_seller_return_policy( $seller ): string {
	$record = extrachill_shop_get_seller_shipping_address( $seller );
	return is_array( $record ) && isset( $record['return_policy'] ) ? (string) $record['return_policy'] : '';
}

/**
 * Save a seller's shipping times and return policy, keeping their address.
 *
 * @param mixed  $seller Seller reference.
 * @param string $policy Plain text; '' clears it.
 * @return bool
 */
function extrachill_shop_set_seller_return_policy( $seller, string $policy ): bool {
	$record                  = extrachill_shop_get_seller_shipping_address( $seller );
	$record                  = is_array( $record ) ? $record : array();
	$record['return_policy'] = $policy;
	return extrachill_shop_set_seller_shipping_address( $seller, $record );
}

/**
 * Clean seller-entered return policy text.
 *
 * @param mixed $policy Raw input.
 * @return string Plain text, line breaks kept, at most EXTRACHILL_SHOP_RETURN_POLICY_MAX characters.
 */
function extrachill_shop_sanitize_return_policy( $policy ): string {
	$policy = trim( sanitize_textarea_field( (string) $policy ) );
	return function_exists( 'mb_substr' ) ? mb_substr( $policy, 0, EXTRACHILL_SHOP_RETURN_POLICY_MAX ) : substr( $policy, 0, EXTRACHILL_SHOP_RETURN_POLICY_MAX );
}

/**
 * Get the Stripe account ID bound to a seller.
 *
 * @param mixed $seller Seller reference.
 * @return string|false
 */
function extrachill_shop_get_seller_stripe_account( $seller ) {
	$record = extrachill_shop_get_seller_stripe_account_record( $seller );
	return $record && ! empty( $record['account_id'] ) ? (string) $record['account_id'] : false;
}

/**
 * Read a seller's Shop-owned Stripe account record.
 *
 * @param array $seller Seller reference.
 * @return array|false
 */
function extrachill_shop_get_seller_stripe_account_record( $seller ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return false;
	}
	$key    = 'extrachill_shop_stripe_seller_' . $seller['type'] . ':' . $seller['id'];
	$record = get_option( $key, false );
	return is_array( $record ) ? $record : false;
}

/**
 * Persist seller state and the webhook reverse index.
 *
 * @param array $seller Seller reference.
 * @param array $record Account projection.
 * @return bool
 */
function extrachill_shop_set_seller_stripe_account_record( $seller, array $record ) {
	$seller     = extrachill_shop_validate_seller_ref( $seller );
	$account_id = sanitize_text_field( (string) ( $record['account_id'] ?? '' ) );
	if ( is_wp_error( $seller ) || '' === $account_id ) {
		return false;
	}
	$key   = 'extrachill_shop_stripe_seller_' . $seller['type'] . ':' . $seller['id'];
	$index = 'extrachill_shop_stripe_account_' . hash( 'sha256', $account_id );
	$bound = get_option( $index, false );
	if ( false !== $bound && $bound !== $seller ) {
		return false;
	}
	$existing = get_option( $key, false );
	if ( is_array( $existing ) && ( $existing['account_id'] ?? '' ) !== $account_id ) {
		return false;
	}
	// Who pays card processing on direct charges (Stripe's
	// `controller.fees.payer`). Callers that only know the status pass no
	// payer, so a known payer is kept rather than wiped.
	$fee_payer = sanitize_key( (string) ( $record['fee_payer'] ?? '' ) );
	if ( '' === $fee_payer && is_array( $existing ) ) {
		$fee_payer = sanitize_key( (string) ( $existing['fee_payer'] ?? '' ) );
	}
	$stored = array(
		'version'             => 2,
		'seller'              => $seller,
		'account_id'          => $account_id,
		'status'              => sanitize_key( (string) ( $record['status'] ?? 'pending' ) ),
		'onboarding_complete' => ! empty( $record['onboarding_complete'] ),
		// Set only when `status` came from Stripe (account.updated or a live read).
		'status_synced_at'    => (string) ( $record['status_synced_at'] ?? '' ),
		// Stripe controller.fees.payer as reported; empty when not known yet.
		'fee_payer'           => $fee_payer,
		'updated_at'          => gmdate( 'c' ),
	);
	if ( 'artist' === $seller['type'] ) {
		$stored['artist_id'] = $seller['id'];
	}
	$saved   = update_option( $key, $stored, false );
	$indexed = update_option( $index, $seller, false );
	return ( $saved || get_option( $key, false ) === $stored )
		&& ( $indexed || get_option( $index, false ) === $seller );
}

/**
 * Read one Shop-owned Stripe account projection.
 *
 * @param int $artist_id Canonical artist profile ID.
 * @return array|false
 */
function extrachill_shop_get_stripe_account_record( $artist_id ) {
	return extrachill_shop_get_seller_stripe_account_record(
		array(
			'type' => 'artist',
			'id'   => absint( $artist_id ),
		)
	);
}

/**
 * Persist one Shop-owned Stripe account projection.
 *
 * @param int   $artist_id Canonical artist profile ID.
 * @param array $record    Account projection.
 * @return bool
 */
function extrachill_shop_set_stripe_account_record( $artist_id, array $record ) {
	return extrachill_shop_set_seller_stripe_account_record(
		array(
			'type' => 'artist',
			'id'   => absint( $artist_id ),
		),
		$record
	);
}

/**
 * Who pays card processing on a Stripe account's direct charges.
 *
 * Reads Stripe's `controller.fees.payer` from an Account object, a webhook
 * payload, or the same shape as an array. `account` means the connected
 * account pays; `application`, `application_express` and
 * `application_custom` mean the platform (Extra Chill) pays.
 *
 * @param object|array|null $account Stripe Account (or its array form).
 * @return string Sanitized payer, or '' when the account does not say.
 */
function extrachill_shop_stripe_fee_payer( $account ): string {
	$value = $account;
	foreach ( array( 'controller', 'fees', 'payer' ) as $field ) {
		if ( is_array( $value ) ) {
			$value = $value[ $field ] ?? null;
		} elseif ( is_object( $value ) ) {
			$value = $value->$field ?? null;
		} else {
			return '';
		}
	}
	return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
}

/**
 * The record saved for a newly created connected account.
 *
 * @param object|array $account `\Stripe\Account::create()` response.
 * @return array Account projection for `extrachill_shop_set_seller_stripe_account_record()`.
 */
function extrachill_shop_new_stripe_account_record( $account ): array {
	return array(
		'account_id'          => (string) ( is_array( $account ) ? ( $account['id'] ?? '' ) : ( $account->id ?? '' ) ),
		'status'              => 'pending',
		'onboarding_complete' => false,
		'fee_payer'           => extrachill_shop_stripe_fee_payer( $account ),
	);
}

/**
 * Who pays card processing on a seller's direct charges.
 *
 * Reads the stored payer, which account creation, `account.updated` and
 * status refreshes keep. A record saved before the payer was stored is
 * looked up from Stripe once and the answer is saved. A failed lookup is
 * remembered briefly so a busy checkout does not ask Stripe every time; the
 * ticket fee treats '' as "the account pays", which keeps the venue whole
 * (see `extrachill_commerce_processing_payer()`).
 *
 * @param array $seller Seller reference.
 * @return string Payer, or '' when unknown.
 */
function extrachill_shop_get_seller_fee_payer( $seller ): string {
	$record = extrachill_shop_get_seller_stripe_account_record( $seller );
	if ( ! $record || empty( $record['account_id'] ) ) {
		return '';
	}
	if ( ! empty( $record['fee_payer'] ) ) {
		return sanitize_key( (string) $record['fee_payer'] );
	}
	$key = 'extrachill_shop_stripe_fee_payer_miss_' . hash( 'sha256', (string) $record['account_id'] );
	if ( false !== get_transient( $key ) ) {
		return '';
	}
	$status = function_exists( 'extrachill_shop_get_account_status' ) ? extrachill_shop_get_account_status( $record['account_id'] ) : array( 'success' => false );
	$payer  = ! empty( $status['success'] ) ? sanitize_key( (string) ( $status['fee_payer'] ?? '' ) ) : '';
	if ( '' === $payer ) {
		set_transient( $key, 1, EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL );
		return '';
	}
	$record['fee_payer'] = $payer;
	extrachill_shop_set_seller_stripe_account_record( $seller, $record );
	return $payer;
}

/**
 * Update status fields for a connected account webhook.
 *
 * @param string $account_id          Stripe account ID.
 * @param string $status              Account status.
 * @param bool   $onboarding_complete Whether onboarding is complete.
 * @param string $fee_payer           Stripe `controller.fees.payer`; '' keeps the stored one.
 * @return bool
 */
function extrachill_shop_update_stripe_account_by_id( $account_id, $status, $onboarding_complete, $fee_payer = '' ) {
	$seller = extrachill_shop_validate_seller_ref(
		get_option( 'extrachill_shop_stripe_account_' . hash( 'sha256', (string) $account_id ), false )
	);
	$record = is_wp_error( $seller ) ? false : extrachill_shop_get_seller_stripe_account_record( $seller );
	if ( ! $record || ! hash_equals( (string) $record['account_id'], (string) $account_id ) ) {
		return false;
	}

	$record['status']              = sanitize_key( $status );
	$record['onboarding_complete'] = (bool) $onboarding_complete;
	$record['status_synced_at']    = gmdate( 'c' );
	if ( '' !== (string) $fee_payer ) {
		$record['fee_payer'] = (string) $fee_payer;
	}
	return extrachill_shop_set_seller_stripe_account_record( $seller, $record );
}

/**
 * Update local account status cache from Stripe webhook data.
 *
 * Stripe account status is cached in Shop-owned storage.
 *
 * @param string $account_id Stripe account ID.
 * @param array  $account_data Account data from webhook (`charges_enabled`,
 *                             `payouts_enabled`, `details_submitted`, `fee_payer`).
 * @return bool False when no seller is bound to the account.
 */
function extrachill_shop_update_account_status_cache( $account_id, $account_data ) {
	$status = 'pending';
	if ( ! empty( $account_data['charges_enabled'] ) && ! empty( $account_data['payouts_enabled'] ) ) {
		$status = 'active';
	} elseif ( ! empty( $account_data['details_submitted'] ) ) {
		$status = 'restricted';
	}

	return extrachill_shop_update_stripe_account_by_id( $account_id, $status, ! empty( $account_data['details_submitted'] ), (string) ( $account_data['fee_payer'] ?? '' ) );
}

/**
 * Whether a seller's connected account can take payments right now.
 *
 * Reads the stored status, which `account.updated` keeps current, so cart
 * renders and checkout starts make no Stripe call. Only an account whose
 * status Stripe has never reported (no `status_synced_at`) is checked live,
 * and that answer is cached briefly so a busy cart does not hit Stripe on
 * every render.
 *
 * @param array $seller Seller reference.
 * @return bool
 */
function extrachill_shop_seller_can_receive_payments( $seller ) {
	$record = extrachill_shop_get_seller_stripe_account_record( $seller );
	if ( ! $record || empty( $record['account_id'] ) ) {
		return false;
	}
	if ( ! empty( $record['status_synced_at'] ) ) {
		return 'active' === ( $record['status'] ?? '' );
	}

	$key    = 'extrachill_shop_stripe_live_' . hash( 'sha256', (string) $record['account_id'] );
	$cached = get_transient( $key );
	if ( is_array( $cached ) ) {
		return ! empty( $cached['can_receive_payments'] );
	}
	$status = function_exists( 'extrachill_shop_get_account_status' ) ? extrachill_shop_get_account_status( $record['account_id'] ) : array( 'success' => false );
	$ready  = ! empty( $status['success'] ) && ! empty( $status['can_receive_payments'] );
	set_transient( $key, array( 'can_receive_payments' => $ready ), EXTRACHILL_SHOP_STRIPE_LIVE_STATUS_TTL );
	return $ready;
}
