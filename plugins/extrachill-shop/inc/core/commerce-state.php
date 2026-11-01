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
 * Resolve a seller reference from ability input.
 *
 * Accepts the typed `seller` object, or the legacy `artist_id` integer.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Seller reference.
 */
function extrachill_shop_seller_from_input( array $input ) {
	if ( isset( $input['seller'] ) ) {
		return extrachill_shop_validate_seller_ref( $input['seller'] );
	}
	$artist_id = isset( $input['artist_id'] ) ? (int) $input['artist_id'] : 0;
	if ( $artist_id > 0 ) {
		return array(
			'type' => 'artist',
			'id'   => $artist_id,
		);
	}
	return new WP_Error( 'missing_seller', __( 'A seller or artist ID is required.', 'extrachill-shop' ), array( 'status' => 400 ) );
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
 * Events owner (`extrachill/get-commerce-seller`), which also reports whether
 * the current user may manage the seller's finances.
 *
 * @param mixed $seller Seller reference.
 * @return array|WP_Error { type, id, name, url, can_manage_finances? }
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
			'type'                => $seller['type'],
			'id'                  => $seller['id'],
			'name'                => (string) ( $result['name'] ?? '' ),
			'url'                 => (string) ( $result['url'] ?? '' ),
			'can_manage_finances' => ! empty( $result['can_manage_finances'] ),
		);
	}
	return $sellers[ $key ];
}

/**
 * Ask the seller's owner whether the current actor can manage it.
 *
 * Artists keep the Artist owner's edit permission. Venues and promoters
 * require finance authority on the Events site (venue or promoter owner).
 *
 * @param mixed $seller Seller reference.
 * @return true|WP_Error
 */
function extrachill_shop_current_user_can_manage_seller( $seller ) {
	$seller = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	if ( 'artist' === $seller['type'] ) {
		return extrachill_shop_current_user_can_manage_artist( $seller['id'] );
	}

	$canonical = extrachill_shop_get_canonical_seller( $seller );
	if ( is_wp_error( $canonical ) ) {
		return $canonical;
	}
	return ! empty( $canonical['can_manage_finances'] )
		? true
		: new WP_Error( 'cannot_manage_seller', __( 'You do not have access to this seller.', 'extrachill-shop' ), array( 'status' => 403 ) );
}

/**
 * Ability input schema fragment for a typed seller reference.
 *
 * @return array
 */
function extrachill_shop_seller_input_schema() {
	return array(
		'type'                 => 'object',
		'description'          => 'Typed seller reference. Use instead of artist_id for venues and promoters.',
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
 * Permission check shared by seller-scoped Shop abilities.
 *
 * @param array $input Ability input with `seller` or `artist_id`.
 * @return true|WP_Error
 */
function extrachill_shop_authorize_seller_input( array $input ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'You must be logged in to access this endpoint.', array( 'status' => 401 ) );
	}
	$seller = extrachill_shop_seller_from_input( $input );
	if ( is_wp_error( $seller ) ) {
		return $seller;
	}
	return extrachill_shop_current_user_can_manage_seller( $seller );
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
 * Read seller state, lazily migrating the previous artist option in place.
 *
 * The legacy option is retained for safe replay; the typed record wins once
 * present. Reads never overwrite an existing typed binding.
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
	if ( false === $record && 'artist' === $seller['type'] ) {
		$legacy = get_option( extrachill_shop_stripe_artist_option_name( $seller['id'] ), false );
		if ( is_array( $legacy ) && extrachill_shop_set_seller_stripe_account_record( $seller, $legacy, true ) ) {
			$record = get_option( $key, false );
		}
	}
	return is_array( $record ) ? $record : false;
}

/**
 * Persist seller state and the webhook reverse index.
 *
 * @param array $seller Seller reference.
 * @param array $record             Account projection.
 * @param bool  $preserve_timestamp Keep the record's updated_at (lazy migration only).
 * @return bool
 */
function extrachill_shop_set_seller_stripe_account_record( $seller, array $record, $preserve_timestamp = false ) {
	$seller     = extrachill_shop_validate_seller_ref( $seller );
	$account_id = sanitize_text_field( (string) ( $record['account_id'] ?? '' ) );
	if ( is_wp_error( $seller ) || '' === $account_id ) {
		return false;
	}
	$key   = 'extrachill_shop_stripe_seller_' . $seller['type'] . ':' . $seller['id'];
	$index = 'extrachill_shop_stripe_account_' . hash( 'sha256', $account_id );
	$bound = get_option( $index, false );
	if ( false !== $bound ) {
		$bound = is_array( $bound ) ? $bound : array(
			'type' => 'artist',
			'id'   => absint( $bound ),
		);
		if ( $bound !== $seller ) {
			return false;
		}
	}
	$existing = get_option( $key, false );
	if ( is_array( $existing ) && ( $existing['account_id'] ?? '' ) !== $account_id ) {
		return false;
	}
	$stored = array(
		'version'             => 2,
		'seller'              => $seller,
		'account_id'          => $account_id,
		'status'              => sanitize_key( (string) ( $record['status'] ?? 'pending' ) ),
		'onboarding_complete' => ! empty( $record['onboarding_complete'] ),
		'updated_at'          => $preserve_timestamp && ! empty( $record['updated_at'] ) ? (string) $record['updated_at'] : gmdate( 'c' ),
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
 * Update status fields for a connected account webhook.
 *
 * @param string $account_id Stripe account ID.
 * @param string $status Account status.
 * @param bool   $onboarding_complete Whether onboarding is complete.
 * @return bool
 */
function extrachill_shop_update_stripe_account_by_id( $account_id, $status, $onboarding_complete ) {
	$reference = get_option( 'extrachill_shop_stripe_account_' . hash( 'sha256', (string) $account_id ), 0 );
	// Old reverse indexes contain an artist ID, not a typed reference.
	$seller = extrachill_shop_validate_seller_ref(
		is_array( $reference ) ? $reference : array(
			'type' => 'artist',
			'id'   => absint( $reference ),
		)
	);
	$record = is_wp_error( $seller ) ? false : extrachill_shop_get_seller_stripe_account_record( $seller );
	if ( ! $record || ! hash_equals( (string) $record['account_id'], (string) $account_id ) ) {
		return false;
	}

	$record['status']              = sanitize_key( $status );
	$record['onboarding_complete'] = (bool) $onboarding_complete;
	return extrachill_shop_set_seller_stripe_account_record( $seller, $record );
}
