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
	$orphans = ec_get_orphaned_artist_relationships();
	if ( is_wp_error( $orphans ) ) {
		return $orphans;
	}

	return array( 'orphans' => $orphans );
}
