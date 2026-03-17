<?php
/**
 * Handler: extrachill/update-artist
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Update an existing artist profile. Supports partial updates.
 *
 * @param array $input { artist_id: int, name?: string, bio?: string, local_city?: string, genre?: string, profile_image_id?: int, header_image_id?: int }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_update_artist( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}

	switch_to_blog( $artist_blog_id );

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		restore_current_blog();
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	$post_data   = array( 'ID' => $artist_id );
	$has_updates = false;

	if ( isset( $input['name'] ) ) {
		$post_data['post_title'] = sanitize_text_field( wp_unslash( $input['name'] ) );
		$has_updates = true;
	}

	if ( isset( $input['bio'] ) ) {
		$post_data['post_content'] = wp_kses_post( wp_unslash( $input['bio'] ) );
		$has_updates = true;
	}

	if ( array_key_exists( 'local_city', $input ) ) {
		$local_city = sanitize_text_field( wp_unslash( $input['local_city'] ) );
		if ( $local_city === '' ) {
			delete_post_meta( $artist_id, '_local_city' );
		} else {
			update_post_meta( $artist_id, '_local_city', $local_city );
		}
	}

	if ( array_key_exists( 'genre', $input ) ) {
		$genre = sanitize_text_field( wp_unslash( $input['genre'] ) );
		if ( $genre === '' ) {
			delete_post_meta( $artist_id, '_genre' );
		} else {
			update_post_meta( $artist_id, '_genre', $genre );
		}
	}

	if ( array_key_exists( 'profile_image_id', $input ) ) {
		$profile_image_id = absint( $input['profile_image_id'] );
		if ( $profile_image_id > 0 ) {
			set_post_thumbnail( $artist_id, $profile_image_id );
		} else {
			delete_post_thumbnail( $artist_id );
		}
	}

	if ( array_key_exists( 'header_image_id', $input ) ) {
		$header_image_id = absint( $input['header_image_id'] );
		if ( $header_image_id > 0 ) {
			update_post_meta( $artist_id, '_artist_profile_header_image_id', $header_image_id );
		} else {
			delete_post_meta( $artist_id, '_artist_profile_header_image_id' );
		}
	}

	if ( $has_updates ) {
		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			restore_current_blog();
			return $result;
		}
	}

	restore_current_blog();

	$get_ability = wp_get_ability( 'extrachill/get-artist-data' );
	if ( $get_ability ) {
		return $get_ability->execute( array( 'artist_id' => $artist_id ) );
	}

	return array( 'id' => (int) $artist_id );
}
