<?php
/**
 * Handler: extrachill/create-artist
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a new artist profile.
 *
 * @param array $input { name: string, bio?: string, local_city?: string, genre?: string, user_id?: int }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_create_artist( $input ) {
	$name       = isset( $input['name'] ) ? trim( sanitize_text_field( $input['name'] ) ) : '';
	$bio        = isset( $input['bio'] ) ? wp_kses_post( wp_unslash( $input['bio'] ) ) : '';
	$local_city = isset( $input['local_city'] ) ? sanitize_text_field( wp_unslash( $input['local_city'] ) ) : '';
	$genre      = isset( $input['genre'] ) ? sanitize_text_field( wp_unslash( $input['genre'] ) ) : '';
	$user_id    = isset( $input['user_id'] ) ? absint( $input['user_id'] ) : get_current_user_id();

	if ( strlen( $name ) < 1 ) {
		return new WP_Error( 'invalid_artist_name', 'Artist name is required.' );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', 'Multisite not configured.' );
	}

	switch_to_blog( $artist_blog_id );

	$post_data = array(
		'post_title'   => $name,
		'post_content' => $bio,
		'post_type'    => 'artist_profile',
		'post_status'  => 'publish',
		'post_author'  => $user_id,
	);

	$artist_id = wp_insert_post( $post_data, true );

	if ( is_wp_error( $artist_id ) ) {
		restore_current_blog();
		return $artist_id;
	}

	if ( $local_city !== '' ) {
		update_post_meta( $artist_id, '_local_city', $local_city );
	}

	if ( $genre !== '' ) {
		update_post_meta( $artist_id, '_genre', $genre );
	}

	restore_current_blog();

	if ( function_exists( 'ec_add_artist_membership' ) ) {
		ec_add_artist_membership( $user_id, $artist_id );
	}

	$get_ability = wp_get_ability( 'extrachill/get-artist-data' );
	if ( $get_ability ) {
		return $get_ability->execute( array( 'artist_id' => $artist_id ) );
	}

	return array( 'id' => (int) $artist_id, 'name' => $name );
}
