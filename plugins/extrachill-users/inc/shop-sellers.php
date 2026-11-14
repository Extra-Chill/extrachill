<?php
/**
 * Shop seller discovery.
 *
 * Lists the sellers (artists, venues, promoters) a user can manage in the
 * seller shop manager. Each owner stays authoritative: artist membership comes
 * from Users/Artist, venue and promoter finance authority from Events'
 * `extrachill/list-commerce-sellers` ability. Shop stores no "who manages
 * what" state, and every Shop operation re-checks authority per seller.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artist sellers the user is a member of.
 *
 * @param int $user_id User ID.
 * @return array<int, array{type: string, id: int, name: string, manage_url: string}>
 */
function ec_get_manageable_shop_artist_sellers( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 || ! function_exists( 'ec_get_artists_for_user' ) ) {
		return array();
	}

	$artist_ids = array_map( 'intval', (array) ec_get_artists_for_user( $user_id ) );
	if ( empty( $artist_ids ) ) {
		return array();
	}

	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	if ( $artist_blog_id < 1 ) {
		return array();
	}

	$manage_url = ec_get_artist_manage_url();
	$sellers    = array();
	switch_to_blog( $artist_blog_id );
	try {
		foreach ( $artist_ids as $artist_id ) {
			$post = get_post( $artist_id );
			if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
				continue;
			}
			$sellers[] = array(
				'type'       => 'artist',
				'id'         => $artist_id,
				'name'       => $post->post_title,
				'manage_url' => $manage_url,
			);
		}
	} finally {
		restore_current_blog();
	}

	return $sellers;
}

/**
 * URL of the Manage Artist screen on the Artist site.
 *
 * @return string
 */
function ec_get_artist_manage_url() {
	return function_exists( 'ec_get_site_url' ) ? trailingslashit( (string) ec_get_site_url( 'artist' ) ) . 'manage-artist/' : '';
}

/**
 * Venue and promoter sellers whose finances the user may manage.
 *
 * Authority is read from Events; it is never inferred here.
 *
 * @param int $user_id User ID.
 * @return array<int, array{type: string, id: int, name: string, manage_url: string}>
 */
function ec_get_manageable_shop_event_sellers( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 || ! function_exists( 'ec_cross_site_rest_request' ) ) {
		return array();
	}

	$result = ec_cross_site_rest_request(
		'events',
		'GET',
		'/wp-abilities/v1/abilities/extrachill/list-commerce-sellers/run',
		array( 'user_id' => $user_id )
	);
	if ( is_wp_error( $result ) || ! is_array( $result ) || ! is_array( $result['sellers'] ?? null ) ) {
		return array();
	}

	$sellers = array();
	foreach ( $result['sellers'] as $seller ) {
		if ( ! is_array( $seller ) || true !== ( $seller['can_manage_finances'] ?? false ) ) {
			continue;
		}
		$type = (string) ( $seller['type'] ?? '' );
		$id   = (int) ( $seller['id'] ?? 0 );
		if ( ! in_array( $type, array( 'venue', 'promoter' ), true ) || $id < 1 ) {
			continue;
		}
		$sellers[] = array(
			'type'       => $type,
			'id'         => $id,
			'name'       => (string) ( $seller['name'] ?? '' ),
			'manage_url' => (string) ( $seller['manage_url'] ?? '' ),
		);
	}

	return $sellers;
}

/**
 * All sellers the user can manage in the shop manager.
 *
 * @param int $user_id User ID.
 * @return array<int, array{type: string, id: int, name: string, manage_url: string}> Typed, named seller references.
 */
function ec_get_manageable_shop_sellers( $user_id ) {
	$sellers = array();
	foreach ( array_merge( ec_get_manageable_shop_artist_sellers( $user_id ), ec_get_manageable_shop_event_sellers( $user_id ) ) as $seller ) {
		$sellers[ $seller['type'] . ':' . $seller['id'] ] = $seller;
	}
	return array_values( $sellers );
}

/**
 * Whether the user manages at least one venue or promoter seller.
 *
 * Cached briefly because entry points (avatar menu, nav) ask on every page.
 * Discovery only: Shop operations still check authority per request.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_user_has_shop_event_sellers( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return false;
	}

	$key    = 'ec_shop_event_sellers_' . $user_id;
	$cached = get_site_transient( $key );
	if ( false !== $cached ) {
		return 'yes' === $cached;
	}

	$has = ! empty( ec_get_manageable_shop_event_sellers( $user_id ) );
	set_site_transient( $key, $has ? 'yes' : 'no', 5 * MINUTE_IN_SECONDS );
	return $has;
}

/**
 * URL of the seller shop manager on the Shop site.
 *
 * Every entry point (nav, avatar menu, storefront, emails, Stripe return,
 * Events venue/promoter screens) links here instead of embedding the manager.
 *
 * @param array|null $seller Optional `{type, id}` reference to preselect.
 * @return string Manager URL, or '' when the Shop site URL is unavailable.
 */
function ec_get_shop_manager_url( $seller = null ) {
	$shop_url = function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( 'shop' ) : '';
	if ( '' === $shop_url ) {
		return '';
	}

	$url = trailingslashit( $shop_url ) . 'manage/';
	if ( ! is_array( $seller ) ) {
		return $url;
	}

	$type = isset( $seller['type'] ) ? (string) $seller['type'] : '';
	$id   = isset( $seller['id'] ) ? (int) $seller['id'] : 0;
	if ( ! in_array( $type, array( 'artist', 'venue', 'promoter' ), true ) || $id < 1 ) {
		return $url;
	}

	return add_query_arg( 'seller', $type . ':' . $id, $url );
}

/**
 * Normalize a `{type, id}` seller reference.
 *
 * @param mixed $seller Seller reference.
 * @return array{type: string, id: int}|null Null when malformed.
 */
function ec_shop_seller_ref( $seller ) {
	if ( ! is_array( $seller ) ) {
		return null;
	}
	$type = isset( $seller['type'] ) ? (string) $seller['type'] : '';
	$id   = isset( $seller['id'] ) ? (int) $seller['id'] : 0;
	if ( ! in_array( $type, array( 'artist', 'venue', 'promoter' ), true ) || $id < 1 ) {
		return null;
	}
	return array(
		'type' => $type,
		'id'   => $id,
	);
}

/**
 * Public storefront URL for a seller: `shop/<type>/<slug>/`.
 *
 * Mirrors the owner's own URL (events `/venue/<slug>/`, artist profiles), so
 * a seller's calendar and merch read as a pair. The slug comes from the
 * owner: venue/promoter term slug, artist profile slug.
 *
 * @param array $seller `{type, id, slug}` seller reference.
 * @return string Storefront URL, or '' when the seller, slug or Shop URL is invalid.
 */
function ec_get_shop_storefront_url( $seller ) {
	$ref      = ec_shop_seller_ref( $seller );
	$slug     = is_array( $seller ) && isset( $seller['slug'] ) && is_string( $seller['slug'] ) ? sanitize_title( $seller['slug'] ) : '';
	$shop_url = function_exists( 'ec_get_site_url' ) ? (string) ec_get_site_url( 'shop' ) : '';
	if ( null === $ref || '' === $slug || '' === $shop_url ) {
		return '';
	}
	return trailingslashit( $shop_url ) . $ref['type'] . '/' . $slug . '/';
}

/**
 * Whether a seller has published products in the Shop.
 *
 * Cached briefly; owner pages (venue and promoter calendars) ask on every view.
 *
 * @param array $seller `{type, id}` seller reference.
 * @return bool
 */
function ec_shop_seller_has_products( $seller ) {
	$ref          = ec_shop_seller_ref( $seller );
	$shop_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'shop' ) : 0;
	if ( null === $ref || $shop_blog_id < 1 ) {
		return false;
	}

	$key    = 'ec_shop_seller_products_' . $ref['type'] . '_' . $ref['id'];
	$cached = get_site_transient( $key );
	if ( false !== $cached ) {
		return 'yes' === $cached;
	}

	$meta_query = array(
		array(
			'key'   => '_extrachill_shop_seller_type',
			'value' => $ref['type'],
		),
		array(
			'key'   => '_extrachill_shop_seller_id',
			'value' => $ref['id'],
		),
	);

	switch_to_blog( $shop_blog_id );
	try {
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Seller identity is product meta.
			)
		);
		$has   = ! empty( $query->posts );
	} finally {
		restore_current_blog();
	}

	set_site_transient( $key, $has ? 'yes' : 'no', 10 * MINUTE_IN_SECONDS );
	return $has;
}
