<?php
/**
 * Handler: extrachill/artist-get-analytics
 *
 * Returns link page analytics for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Get link page analytics for an artist.
 *
 * @param array $input Input parameters.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get_analytics( array $input ) {
	$artist_id  = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$date_range = isset( $input['date_range'] ) ? (int) $input['date_range'] : 30;
	$start_date = isset( $input['start_date'] ) ? (string) $input['start_date'] : '';
	$end_date   = isset( $input['end_date'] ) ? (string) $input['end_date'] : '';

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
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
	 * @param mixed  $result       Previous filter result (null if no handler).
	 * @param int    $link_page_id The link page post ID.
	 * @param int    $date_range   Number of days to query.
	 * @param string $start_date   Inclusive exact window start in Y-m-d format.
	 * @param string $end_date     Inclusive exact window end in Y-m-d format.
	 */
	$result = apply_filters( 'extrachill_get_link_page_analytics', null, $link_page_id, $date_range, $start_date, $end_date );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! is_array( $result ) ) {
		return new WP_Error( 'analytics_unavailable', 'Analytics data could not be retrieved.' );
	}

	return $result;
}
