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
 * @param array $input { artist_id: int, name?: string, bio?: string, local_city?: string, genre?: string, profile_image_id?: int, header_image_id?: int }.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_update_artist( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}
	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}
	$projection_fields  = array( 'name', 'bio', 'genre', 'profile_image_id' );
	$affects_projection = (bool) array_intersect( $projection_fields, array_keys( $input ) );
	if ( ! $affects_projection ) {
		return extrachill_artist_platform_ability_update_artist_under_lock( $input, 0 );
	}
	return ec_artist_with_link_page_lock(
		$artist_id,
		static function ( $link_page_id ) use ( $input ) {
			return extrachill_artist_platform_ability_update_artist_under_lock( $input, $link_page_id );
		}
	);
}

/**
 * Execute the profile mutation after any required Link Page lock is held.
 *
 * @param array $input        Ability input.
 * @param int   $link_page_id Locked Link Page ID, or zero when unrelated.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_update_artist_under_lock( $input, $link_page_id ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}
	$did_switch = get_current_blog_id() !== (int) $artist_blog_id;
	if ( $did_switch && ( ! switch_to_blog( $artist_blog_id ) || get_current_blog_id() !== (int) $artist_blog_id ) ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}

	if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
		if ( $did_switch ) {
			restore_current_blog();
		}
		return new WP_Error( 'invalid_artist', 'Artist not found.' );
	}

	$post_data   = array( 'ID' => $artist_id );
	$has_updates = false;

	if ( isset( $input['name'] ) ) {
		$post_data['post_title'] = sanitize_text_field( wp_unslash( $input['name'] ) );
		$has_updates             = true;
	}

	if ( isset( $input['bio'] ) ) {
		$post_data['post_content'] = wp_kses_post( wp_unslash( $input['bio'] ) );
		$has_updates               = true;
	}

	if ( array_key_exists( 'local_city', $input ) ) {
		$local_city = sanitize_text_field( wp_unslash( $input['local_city'] ) );
		if ( '' === $local_city ) {
			delete_post_meta( $artist_id, '_local_city' );
		} else {
			update_post_meta( $artist_id, '_local_city', $local_city );
		}
	}

	if ( array_key_exists( 'genre', $input ) ) {
		$genre = sanitize_text_field( wp_unslash( $input['genre'] ) );
		if ( '' === $genre ) {
			delete_post_meta( $artist_id, '_genre' );
		} else {
			update_post_meta( $artist_id, '_genre', $genre );
		}
	}

	if ( array_key_exists( 'profile_image_id', $input ) ) {
		$profile_image_id = absint( $input['profile_image_id'] );
		if ( 0 < $profile_image_id ) {
			set_post_thumbnail( $artist_id, $profile_image_id );
		} else {
			delete_post_thumbnail( $artist_id );
		}
	}

	if ( array_key_exists( 'header_image_id', $input ) ) {
		$header_image_id = absint( $input['header_image_id'] );
		if ( 0 < $header_image_id ) {
			update_post_meta( $artist_id, '_artist_profile_header_image_id', $header_image_id );
		} else {
			delete_post_meta( $artist_id, '_artist_profile_header_image_id' );
		}
	}

	if ( $has_updates ) {
		$result = wp_update_post( $post_data, true );
		if ( is_wp_error( $result ) ) {
			if ( $did_switch ) {
				restore_current_blog();
			}
			return $result;
		}
	}

	if ( $link_page_id ) {
		do_action( 'ec_link_page_save', $link_page_id );
	}

	$get_ability = wp_get_ability( 'extrachill/get-artist-data' );
	if ( $get_ability ) {
		$response = $get_ability->execute( array( 'artist_id' => $artist_id ) );
	} else {
		$response = array( 'id' => (int) $artist_id );
	}
	if ( $did_switch ) {
		restore_current_blog();
	}
	return $response;
}
