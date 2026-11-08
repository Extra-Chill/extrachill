<?php
/**
 * Seller storefronts.
 *
 * A storefront is the Shop catalog filtered to one seller with
 * `?seller=type:id`, for artists, venues and promoters alike. Seller identity
 * lives with its owner (Artist profiles, Events venues/promoters) and in Shop
 * product metadata, not a duplicate Shop-local taxonomy.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Storefront URL for any seller.
 *
 * @param array $seller `{type, id}` seller reference.
 * @return string Storefront URL, or '' when unavailable.
 */
function extrachill_shop_get_seller_store_url( $seller ): string {
	return function_exists( 'ec_get_shop_storefront_url' ) ? (string) ec_get_shop_storefront_url( $seller ) : '';
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
 * @return array{type: string, id: int, name: string, url: string}|null
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

	$transient = 'ec_shop_public_seller_' . $ref['type'] . '_' . $ref['id'];
	$cached    = get_site_transient( $transient );
	if ( is_array( $cached ) ) {
		$memo[ $key ] = $cached;
		return $cached;
	}

	$canonical = extrachill_shop_get_canonical_seller( $ref );
	if ( is_wp_error( $canonical ) || '' === (string) ( $canonical['name'] ?? '' ) ) {
		$memo[ $key ] = null;
		return null;
	}

	$public = array(
		'type' => $ref['type'],
		'id'   => $ref['id'],
		'name' => (string) $canonical['name'],
		'url'  => (string) ( $canonical['url'] ?? '' ),
	);
	set_site_transient( $transient, $public, HOUR_IN_SECONDS );
	$memo[ $key ] = $public;
	return $public;
}

/**
 * Seller the storefront is filtered to, from `?seller=type:id`.
 *
 * @return array{type: string, id: int}|null
 */
function extrachill_shop_get_requested_storefront_seller(): ?array {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public catalog filter.
	$raw = isset( $_GET['seller'] ) && is_string( $_GET['seller'] ) ? sanitize_text_field( wp_unslash( $_GET['seller'] ) ) : '';
	return '' === $raw ? null : extrachill_shop_parse_seller_key( $raw );
}

/**
 * Product meta query that selects one seller's products.
 *
 * @param array $seller `{type, id}` seller reference.
 * @return array
 */
function extrachill_shop_seller_products_meta_query( array $seller ): array {
	if ( 'artist' === $seller['type'] ) {
		return array(
			array(
				'key'     => '_artist_profile_id',
				'value'   => (int) $seller['id'],
				'compare' => '=',
				'type'    => 'NUMERIC',
			),
		);
	}
	return array(
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

/**
 * Venue and promoter sellers with published products.
 *
 * @return array<int, array{type: string, id: int}>
 */
function extrachill_shop_get_event_sellers_with_products(): array {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct seller pairs for the public filter.
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DISTINCT t.meta_value AS seller_type, i.meta_value AS seller_id
			FROM {$wpdb->postmeta} t
			INNER JOIN {$wpdb->postmeta} i ON i.post_id = t.post_id AND i.meta_key = %s
			INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id
			WHERE t.meta_key = %s
			AND t.meta_value IN ( 'venue', 'promoter' )
			AND p.post_type = %s
			AND p.post_status = %s",
			'_extrachill_shop_seller_id',
			'_extrachill_shop_seller_type',
			'product',
			'publish'
		),
		ARRAY_A
	);

	$sellers = array();
	foreach ( (array) $rows as $row ) {
		$id = (int) ( $row['seller_id'] ?? 0 );
		if ( $id > 0 ) {
			$sellers[] = array(
				'type' => (string) $row['seller_type'],
				'id'   => $id,
			);
		}
	}
	return $sellers;
}
