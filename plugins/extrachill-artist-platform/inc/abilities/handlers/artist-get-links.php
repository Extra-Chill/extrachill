<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-get-links
 *
 * Retrieves complete link page data for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get complete link page data for an artist.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_links( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! function_exists( 'ec_get_link_page_data' ) ) {
		return new WP_Error( 'dependency_missing', 'Link page data function not available.' );
	}

	$data = ec_get_link_page_data( $artist_id );

	if ( empty( $data ) || empty( $data['link_page_id'] ) ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	return $data;
}
