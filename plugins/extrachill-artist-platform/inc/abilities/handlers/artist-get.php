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

	if ( ! $artist || $artist->post_type !== 'artist_profile' ) {
		if ( $did_switch ) {
			restore_current_blog();
		}
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	$local_city = get_post_meta( $artist_id, '_local_city', true );
	$genre      = get_post_meta( $artist_id, '_genre', true );

	$profile_image_id  = get_post_thumbnail_id( $artist_id );
	$profile_image_url = $profile_image_id ? wp_get_attachment_image_url( (int) $profile_image_id, 'medium' ) : null;

	$header_image_id  = get_post_meta( $artist_id, '_artist_profile_header_image_id', true );
	$header_image_url = $header_image_id ? wp_get_attachment_image_url( (int) $header_image_id, 'large' ) : null;

	$link_page_id = null;
	if ( function_exists( 'ec_get_link_page_id' ) ) {
		$link_page_id = ec_get_link_page_id( $artist_id );
	}

	if ( $did_switch ) {
		restore_current_blog();
	}

	return array(
		'id'                => (int) $artist_id,
		'name'              => $artist->post_title,
		'slug'              => $artist->post_name,
		'bio'               => $artist->post_content,
		'local_city'        => $local_city !== '' ? $local_city : null,
		'genre'             => $genre !== '' ? $genre : null,
		'profile_image_id'  => $profile_image_id ? (int) $profile_image_id : null,
		'profile_image_url' => $profile_image_url,
		'header_image_id'   => $header_image_id ? (int) $header_image_id : null,
		'header_image_url'  => $header_image_url,
		'link_page_id'      => $link_page_id ? (int) $link_page_id : null,
	);
}
