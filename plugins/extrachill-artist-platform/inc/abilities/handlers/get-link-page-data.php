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
 * @param array $input { artist_id: int, link_page_id?: int }.
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

	$owner_reference = ec_artist_link_page_owner_reference( $artist_id );
	if ( is_wp_error( $owner_reference ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}
	$target = $link_page_id
		? array(
			'link_page_id'    => $link_page_id,
			'owner_reference' => $owner_reference,
		)
		: $owner_reference;
	$data   = ec_read_link_page( $target );
	if ( is_wp_error( $data ) ) {
		if ( $link_page_id ) {
			return new WP_Error( 'invalid_link_page', 'The link page does not belong to this artist.' );
		}
		return 'link_page_operation_forbidden' === $data->get_error_code()
			? new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' )
			: new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	if ( empty( $data ) || empty( $data['link_page_id'] ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	return $data;
}
