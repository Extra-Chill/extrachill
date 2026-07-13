<?php
declare(strict_types=1);
/**
 * Handler: extrachill/artist-get
 *
 * Returns a single artist profile payload.
 *
 * @package ExtraChillArtistPlatform
 * @since   1.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get a single artist profile by ID.
 *
 * @param array $input { @type int $id Artist profile post ID. }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_artist_get( array $input ): array|WP_Error {
	$artist_id = isset( $input['id'] ) ? (int) $input['id'] : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_id', 'id is required.' );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	$did_switch     = false;

	if ( $artist_blog_id && get_current_blog_id() !== $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		$did_switch = true;
	}

	$artist = get_post( $artist_id );

	if ( ! $artist || $artist->post_type !== 'artist_profile' || $artist->post_status !== 'publish' ) {
		if ( $did_switch ) {
			restore_current_blog();
		}
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	$data = ec_get_artist_profile_data( $artist_id );

	if ( $did_switch ) {
		restore_current_blog();
	}

	return array(
		'id'                => (int) $artist_id,
		'name'              => $data['title'],
		'slug'              => $data['slug'],
		'permalink'         => $data['permalink'],
		'bio'               => $data['bio'],
		'local_city'        => $data['local_city'] !== '' ? $data['local_city'] : null,
		'genre'             => $data['genre'] !== '' ? $data['genre'] : null,
		'profile_image_id'  => $data['profile_image_id'] ? (int) $data['profile_image_id'] : null,
		'profile_image_url' => $data['profile_image_url'] ?: null,
		'header_image_id'   => $data['header_image_id'] ? (int) $data['header_image_id'] : null,
		'header_image_url'  => $data['header_image_url'] ?: null,
		'official_links'    => $data['social_links'],
		'link_page_id'      => $data['link_page_id'] ?: null,
	);
}
