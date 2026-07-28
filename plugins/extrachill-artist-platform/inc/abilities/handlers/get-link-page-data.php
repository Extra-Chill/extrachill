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

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( $link_page_id && ! extrachill_artist_platform_ability_link_page_belongs_to_artist( $artist_id, $link_page_id ) ) {
		return new WP_Error( 'invalid_link_page', 'The link page does not belong to this artist.' );
	}

	$data = ec_get_link_page_data( $artist_id, $link_page_id );

	if ( empty( $data ) || empty( $data['link_page_id'] ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	return $data;
}
