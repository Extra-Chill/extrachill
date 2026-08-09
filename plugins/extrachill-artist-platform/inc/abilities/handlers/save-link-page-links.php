<?php
/**
 * Handler: extrachill/save-link-page-links
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save link sections and buttons to a link page. Full replacement with ID assignment.
 *
 * @param array $input { artist_id: int, links: array }.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_save_link_page_links( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$links     = isset( $input['links'] ) ? $input['links'] : null;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	if ( ! is_array( $links ) ) {
		return new WP_Error( 'invalid_links', 'links must be an array.' );
	}

	$owner_reference = ec_artist_link_page_owner_reference( $artist_id );
	$link_page_id    = is_wp_error( $owner_reference ) ? $owner_reference : ec_get_link_page_id_for_owner( $owner_reference );
	if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	$sanitized_links = extrachill_artist_platform_sanitize_links( $links, $link_page_id );

	$save_data = array( 'links' => $sanitized_links );
	$result    = ec_save_link_page(
		array(
			'link_page_id'    => $link_page_id,
			'owner_reference' => $owner_reference,
		),
		$save_data
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}
