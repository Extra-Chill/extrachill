<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-list-subscribers
 *
 * Returns paginated subscriber list for an artist.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * List subscribers for an artist (paginated).
 *
 * @param array $input {
 *     @type int $id       Artist profile post ID.
 *     @type int $page     Page number (default 1).
 *     @type int $per_page Results per page (default 20, max 100).
 * }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_list_subscribers( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;
	$page      = isset( $input['page'] ) ? max( 1, (int) $input['page'] ) : 1;
	$per_page  = isset( $input['per_page'] ) ? max( 1, min( 100, (int) $input['per_page'] ) ) : 20;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		return new WP_Error( 'invalid_artist', __( 'Invalid artist specified.', 'extrachill-artist-platform' ) );
	}

	if ( ! function_exists( 'extrachill_artist_get_artist_subscribers' ) ) {
		return new WP_Error( 'dependency_missing', 'Subscriber functions not available.' );
	}

	$offset      = ( $page - 1 ) * $per_page;
	$subscribers = extrachill_artist_get_artist_subscribers( $artist_id, array(
		'limit'  => $per_page,
		'offset' => $offset,
	) );

	global $wpdb;
	$table = $wpdb->prefix . 'artist_subscribers';
	$total = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE artist_profile_id = %d", $artist_id )
	);

	return array(
		'subscribers' => $subscribers,
		'total'       => $total,
		'per_page'    => $per_page,
		'page'        => $page,
	);
}
