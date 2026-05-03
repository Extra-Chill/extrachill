<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-get-analytics
 *
 * Returns link page analytics for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get link page analytics for an artist.
 *
 * @param array $input {
 *     @type int $id         Artist profile post ID.
 *     @type int $date_range Number of days to query (default 30, max 90).
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_analytics( array $input ): array|WP_Error {
	$artist_id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$date_range = isset( $input['date_range'] ) ? (int) $input['date_range'] : 30;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! function_exists( 'ec_get_link_page_for_artist' ) ) {
		return new WP_Error( 'dependency_missing', 'Artist platform not active.' );
	}

	$link_page_id = ec_get_link_page_for_artist( $artist_id );

	if ( ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	$date_range = max( 1, min( 90, $date_range ) );

	/**
	 * Retrieve link page analytics via filter hook.
	 *
	 * @param mixed $result       Previous filter result (null if no handler).
	 * @param int   $link_page_id The link page post ID.
	 * @param int   $date_range   Number of days to query.
	 */
	$result = apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new WP_Error( 'analytics_unavailable', 'Analytics data could not be retrieved.' );
	}

	return $result;
}
