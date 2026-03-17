<?php
/**
 * Handler: extrachill/get-link-page-data
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get complete link page data.
 *
 * @param array $input { artist_id: int, link_page_id?: int }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_get_link_page_data( $input ) {
	$artist_id    = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$link_page_id = isset( $input['link_page_id'] ) ? absint( $input['link_page_id'] ) : null;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	$data = ec_get_link_page_data( $artist_id, $link_page_id );

	if ( empty( $data ) || empty( $data['link_page_id'] ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	return $data;
}
