<?php
declare(strict_types=1);
/**
 * Handler: extrachill/admin-list-orphan-artist-relationships
 *
 * Lists orphaned artist-user relationships (admin-only).
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * List orphaned artist-user relationships.
 *
 * @param array $input Unused — endpoint takes no parameters.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_admin_list_orphan_artist_relationships( array $input ): array|WP_Error {
	$response = extrachill_api_get_orphaned_relationships();

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = $response instanceof WP_REST_Response ? $response->get_data() : (array) $response;

	return is_array( $data ) ? $data : array( 'orphans' => array() );
}
