<?php
/**
 * Artist Platform operation provider for Link Pages.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the canonical owner reference for an artist profile.
 *
 * @param int $artist_id Artist profile post ID.
 * @return string|WP_Error
 */
function ec_artist_link_page_owner_reference( $artist_id ) {
	$blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();

	return ec_normalize_link_page_owner_reference(
		array(
			'kind'      => 'post',
			'blog_id'   => $blog_id,
			'subtype'   => 'artist_profile',
			'object_id' => absint( $artist_id ),
		)
	);
}

/**
 * Return the current Artist Platform callbacks for a supported owner.
 *
 * @param array $resolved Resolved operation target.
 * @return array|null
 */
function ec_artist_link_page_operation_provider( $resolved ) {
	$owner          = $resolved['owner'];
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	if ( 'post' !== $owner['kind'] || $artist_blog_id !== $owner['blog_id'] || 'artist_profile' !== $owner['subtype'] ) {
		return null;
	}

	return array(
		'authorize' => 'ec_artist_link_page_operation_authorize',
		'read'      => 'ec_artist_link_page_operation_read',
		'save'      => 'ec_artist_link_page_operation_save',
	);
}

/**
 * Apply current Artist Platform membership and capability policy.
 *
 * @param array  $resolved  Resolved operation target.
 * @param string $operation Operation name.
 * @return bool
 */
function ec_artist_link_page_operation_authorize( $resolved, $operation ) {
	if ( ! in_array( $operation, array( 'read', 'save' ), true ) ) {
		return false;
	}

	return extrachill_artist_platform_ability_artist_permission(
		array( 'artist_id' => (int) $resolved['owner']['object_id'] )
	);
}

/**
 * Read through the existing Artist Platform projection.
 *
 * @param array $resolved Resolved operation target.
 * @return array
 */
function ec_artist_link_page_operation_read( $resolved ) {
	return ec_get_link_page_data( (int) $resolved['owner']['object_id'], (int) $resolved['link_page_id'] );
}

/**
 * Save through the existing Artist Platform persistence and projection.
 *
 * @param array $resolved Resolved operation target.
 * @param array $data     Prepared save data.
 * @return array|WP_Error
 */
function ec_artist_link_page_operation_save( $resolved, $data ) {
	$result = ec_handle_link_page_save( (int) $resolved['link_page_id'], $data );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return ec_artist_link_page_operation_read( $resolved );
}
