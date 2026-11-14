<?php
/**
 * Seller storefronts.
 *
 * A storefront is the Shop catalog filtered to one seller at
 * `/<type>/<slug>/` (artist, venue, promoter), matching the owner's own URLs. Seller identity
 * lives with its owner (Artist profiles, Events venues/promoters) and in Shop
 * product metadata, not a duplicate Shop-local taxonomy.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront URL for any seller.
 *
 * Fills in the slug from the seller's public identity when the caller only
 * has `{type, id}`.
 *
 * @param mixed $seller `{type, id, slug?}` seller reference.
 * @return string Storefront URL, or '' when unavailable.
 */
function extrachill_shop_get_seller_store_url( $seller ): string {
	if ( ! function_exists( 'ec_get_shop_storefront_url' ) || ! is_array( $seller ) ) {
		return '';
	}
	if ( empty( $seller['slug'] ) ) {
		$public = extrachill_shop_get_public_seller( $seller );
		if ( ! $public ) {
			return '';
		}
		$seller['slug'] = $public['slug'];
	}
	return (string) ec_get_shop_storefront_url( $seller );
}

/**
 * Storefront URL for a canonical artist.
 *
 * @param int $artist_profile_id Canonical artist profile ID.
 * @return string|false
 */
function extrachill_shop_get_artist_store_url( $artist_profile_id ) {
	$artist = extrachill_shop_get_canonical_artist( $artist_profile_id );
	if ( is_wp_error( $artist ) ) {
		return false;
	}

	$url = extrachill_shop_get_seller_store_url(
		array(
			'type' => 'artist',
			'id'   => absint( $artist['id'] ),
		)
	);
	return '' === $url ? false : $url;
}

/**
 * Public identity of a seller: name and public page.
 *
 * Cached across requests because storefronts, badges and the filter bar ask
 * for it on anonymous page views, and venue/promoter identity is a cross-site
 * owner call.
 *
 * @param array $seller `{type, id}` seller reference.
 * @return array|null `{type, id, name, slug, url}`, or null when unknown.
 */
function extrachill_shop_get_public_seller( $seller ): ?array {
	static $memo = array();

	$ref = extrachill_shop_validate_seller_ref( $seller );
	if ( is_wp_error( $ref ) ) {
		return null;
	}
	$key = $ref['type'] . ':' . $ref['id'];
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$transient = 'ec_shop_public_seller_v2_' . $ref['type'] . '_' . $ref['id'];
	$cached    = get_site_transient( $transient );
	if ( is_array( $cached ) ) {
		$memo[ $key ] = $cached;
		return $cached;
	}

	$canonical = extrachill_shop_get_canonical_seller( $ref );
	if ( is_wp_error( $canonical ) || '' === (string) ( $canonical['name'] ?? '' ) || '' === (string) ( $canonical['slug'] ?? '' ) ) {
		$memo[ $key ] = null;
		return null;
	}

	$public = array(
		'type' => $ref['type'],
		'id'   => $ref['id'],
		'name' => (string) $canonical['name'],
		'slug' => (string) $canonical['slug'],
		'url'  => (string) ( $canonical['url'] ?? '' ),
	);
	set_site_transient( $transient, $public, HOUR_IN_SECONDS );
	$memo[ $key ] = $public;
	return $public;
}

/**
 * Resolve a storefront path (`/<type>/<slug>/`) to a seller.
 *
 * Artists resolve through the Artist profile slug; venues and promoters
 * through their Events owner. Cached: storefronts are anonymous page views.
 *
 * @param string $type Seller type.
 * @param string $slug Seller slug.
 * @return array|null `{type, id}`, or null when unknown.
 */
function extrachill_shop_resolve_storefront_slug( string $type, string $slug ): ?array {
	static $memo = array();

	$slug = sanitize_title( $slug );
	if ( '' === $slug || ! in_array( $type, array( 'artist', 'venue', 'promoter' ), true ) ) {
		return null;
	}
	$key = $type . ':' . $slug;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}

	$transient = 'ec_shop_seller_slug_' . $type . '_' . md5( $slug );
	$cached    = get_site_transient( $transient );
	if ( is_array( $cached ) || 'none' === $cached ) {
		$memo[ $key ] = is_array( $cached ) ? $cached : null;
		return $memo[ $key ];
	}

	$id = 0;
	if ( 'artist' === $type ) {
		$artist = function_exists( 'extrachill_get_artist_profile_by_slug' ) ? extrachill_get_artist_profile_by_slug( $slug ) : false;
		$id     = is_array( $artist ) ? (int) ( $artist['id'] ?? 0 ) : 0;
	} else {
		$result = extrachill_shop_execute_owner_ability(
			'events',
			'extrachill/get-commerce-seller',
			'GET',
			array(
				'type' => $type,
				'slug' => $slug,
			)
		);
		$id     = ! is_wp_error( $result ) && ( $result['type'] ?? '' ) === $type ? (int) ( $result['id'] ?? 0 ) : 0;
	}

	$seller = $id > 0 ? array(
		'type' => $type,
		'id'   => $id,
	) : null;
	// Misses are cached briefly so unknown paths do not fan out to the owner.
	set_site_transient( $transient, $seller ?? 'none', $seller ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );
	$memo[ $key ] = $seller;
	return $seller;
}

/**
 * Seller the current storefront page is for, from its `/<type>/<slug>/` path.
 *
 * @return array{type: string, id: int}|null
 */
function extrachill_shop_get_requested_storefront_seller(): ?array {
	$type = (string) get_query_var( 'ec_shop_seller_type', '' );
	$slug = (string) get_query_var( 'ec_shop_seller_slug', '' );
	return '' === $type || '' === $slug ? null : extrachill_shop_resolve_storefront_slug( $type, $slug );
}

/**
 * Product meta query that selects one seller's products.
 *
 * @param array $seller `{type, id}` seller reference.
 * @return array
 */
function extrachill_shop_seller_products_meta_query( array $seller ): array {
	return array(
		'relation' => 'AND',
		array(
			'key'   => '_extrachill_shop_seller_type',
			'value' => (string) $seller['type'],
		),
		array(
			'key'     => '_extrachill_shop_seller_id',
			'value'   => (int) $seller['id'],
			'compare' => '=',
			'type'    => 'NUMERIC',
		),
	);
}
