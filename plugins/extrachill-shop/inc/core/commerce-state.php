<?php
/**
 * Shop-owned commerce state and owner contract clients.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Execute an ability in its owning site runtime.
 *
 * @param string $site_key Site registry key.
 * @param string $ability  Ability name.
 * @param string $method   HTTP method.
 * @param array  $input    Ability input.
 * @return array|WP_Error
 */
function extrachill_shop_execute_owner_ability( $site_key, $ability, $method, array $input ) {
	if ( ! function_exists( 'ec_cross_site_rest_request_http' ) ) {
		return new WP_Error( 'owner_runtime_unavailable', __( 'The owning site runtime is unavailable.', 'extrachill-shop' ), array( 'status' => 503 ) );
	}

	$args = array( 'user_id' => get_current_user_id() );
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
 * Ask the Artist owner whether the current actor can manage an artist.
 *
 * @param int $artist_id Artist profile ID.
 * @return true|WP_Error
 */
function extrachill_shop_current_user_can_manage_artist( $artist_id ) {
	$result = extrachill_shop_execute_owner_ability(
		'artist',
		'extrachill/artist-get-permissions',
		'GET',
		array( 'id' => absint( $artist_id ) )
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return ! empty( $result['can_edit'] )
		? true
		: new WP_Error( 'cannot_manage_artist', __( 'You do not have access to this artist.', 'extrachill-shop' ), array( 'status' => 403 ) );
}

/**
 * Build the private option key for an artist's Shop-owned Stripe projection.
 *
 * @param int $artist_id Canonical artist profile ID.
 * @return string
 */
function extrachill_shop_stripe_artist_option_name( $artist_id ) {
	return 'extrachill_shop_stripe_artist_' . absint( $artist_id );
}

/**
 * Read one Shop-owned Stripe account projection.
 *
 * @param int $artist_id Canonical artist profile ID.
 * @return array|false
 */
function extrachill_shop_get_stripe_account_record( $artist_id ) {
	$record = get_option( extrachill_shop_stripe_artist_option_name( $artist_id ), false );
	return is_array( $record ) ? $record : false;
}

/**
 * Persist one Shop-owned Stripe account projection.
 *
 * @param int   $artist_id Canonical artist profile ID.
 * @param array $record    Account projection.
 * @return bool
 */
function extrachill_shop_set_stripe_account_record( $artist_id, array $record ) {
	$artist_id  = absint( $artist_id );
	$account_id = isset( $record['account_id'] ) ? sanitize_text_field( (string) $record['account_id'] ) : '';
	if ( ! $artist_id || '' === $account_id ) {
		return false;
	}

	$stored = array(
		'version'             => 1,
		'artist_id'           => $artist_id,
		'account_id'          => $account_id,
		'status'              => sanitize_key( (string) ( $record['status'] ?? 'pending' ) ),
		'onboarding_complete' => ! empty( $record['onboarding_complete'] ),
		'updated_at'          => gmdate( 'c' ),
	);
	$artist_option  = extrachill_shop_stripe_artist_option_name( $artist_id );
	$account_option = 'extrachill_shop_stripe_account_' . hash( 'sha256', $account_id );
	$record_saved   = update_option( $artist_option, $stored, false );
	$index_saved    = update_option( $account_option, $artist_id, false );

	return ( $record_saved || get_option( $artist_option, false ) === $stored )
		&& ( $index_saved || absint( get_option( $account_option, 0 ) ) === $artist_id );
}

/**
 * Update status fields for a connected account webhook.
 *
 * @param string $account_id Stripe account ID.
 * @param string $status Account status.
 * @param bool   $onboarding_complete Whether onboarding is complete.
 * @return bool
 */
function extrachill_shop_update_stripe_account_by_id( $account_id, $status, $onboarding_complete ) {
	$artist_id = absint( get_option( 'extrachill_shop_stripe_account_' . hash( 'sha256', (string) $account_id ), 0 ) );
	$record    = $artist_id ? extrachill_shop_get_stripe_account_record( $artist_id ) : false;
	if ( ! $record || ! hash_equals( (string) $record['account_id'], (string) $account_id ) ) {
		return false;
	}

	$record['status']              = sanitize_key( $status );
	$record['onboarding_complete'] = (bool) $onboarding_complete;
	return extrachill_shop_set_stripe_account_record( $artist_id, $record );
}
