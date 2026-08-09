<?php
/**
 * Handler: extrachill/save-link-page-settings
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save advanced settings for a link page. Merges with existing settings.
 *
 * @param array $input { artist_id: int, settings?: array, bio?: string, background_image_id?: int, profile_image_id?: int }.
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_save_link_page_settings( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.' );
	}

	$owner_reference = ec_artist_link_page_owner_reference( $artist_id );
	$link_page_id    = is_wp_error( $owner_reference ) ? $owner_reference : ec_get_link_page_id_for_owner( $owner_reference );
	if ( is_wp_error( $link_page_id ) || ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	$save_data = array();

	if ( isset( $input['settings'] ) && is_array( $input['settings'] ) ) {
		$sanitized_settings = extrachill_artist_platform_sanitize_link_settings( $input['settings'] );
		$save_data          = array_merge( $save_data, $sanitized_settings );
	}

	if ( array_key_exists( 'bio', $input ) ) {
		$save_data['bio'] = sanitize_text_field( wp_unslash( (string) $input['bio'] ) );
	}

	if ( isset( $input['background_image_id'] ) ) {
		$save_data['background_image_id'] = absint( $input['background_image_id'] );
	}

	if ( isset( $input['profile_image_id'] ) ) {
		$save_data['profile_image_id'] = absint( $input['profile_image_id'] );
	}

	if ( empty( $save_data ) ) {
		return new WP_Error( 'empty_input', 'No settings provided to save.' );
	}

	$result = ec_save_link_page(
		array(
			'link_page_id'    => $link_page_id,
			'owner_reference' => $owner_reference,
		),
		$save_data
	);

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return $result;
}
