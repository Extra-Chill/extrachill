<?php
/**
 * Canonical artist storefront links.
 *
 * Artist identity lives in Artist-owned profiles and Shop product metadata,
 * not a duplicate Shop-local taxonomy.
 *
 * @package ExtraChillShop
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get the Shop catalog URL filtered to a canonical artist reference.
 *
 * @param int $artist_profile_id Canonical artist profile ID.
 * @return string|false
 */
function extrachill_shop_get_artist_store_url( $artist_profile_id ) {
	$artist = extrachill_shop_get_canonical_artist( $artist_profile_id );
	if ( is_wp_error( $artist ) ) {
		return false;
	}

	return add_query_arg( 'artist', absint( $artist['id'] ), home_url( '/' ) );
}
