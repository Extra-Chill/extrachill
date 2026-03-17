<?php
/**
 * Handler: extrachill/save-link-page-styles
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Save CSS variables for a link page. Merges with existing styles.
 *
 * @param array $input { artist_id: int, css_vars: array }
 * @return array|WP_Error
 */
function extrachill_artist_platform_ability_save_link_page_styles( $input ) {
	$artist_id = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$css_vars  = isset( $input['css_vars'] ) ? $input['css_vars'] : null;

	if ( ! $artist_id ) {
		return new WP_Error( 'missing_artist_id', 'artist_id is required.' );
	}

	if ( ! is_array( $css_vars ) ) {
		return new WP_Error( 'invalid_css_vars', 'css_vars must be an object.' );
	}

	$link_page_id = ec_get_link_page_for_artist( $artist_id );
	if ( ! $link_page_id ) {
		return new WP_Error( 'no_link_page', 'No link page exists for this artist.' );
	}

	$existing_vars = get_post_meta( $link_page_id, '_link_page_custom_css_vars', true );
	$existing_vars = is_array( $existing_vars ) ? $existing_vars : array();

	$sanitized_vars = extrachill_artist_platform_sanitize_css_vars( $css_vars );
	$merged_vars    = array_merge( $existing_vars, $sanitized_vars );

	$save_data = array( 'css_vars' => $merged_vars );
	$result    = ec_handle_link_page_save( $link_page_id, $save_data );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return ec_get_link_page_data( $artist_id, $link_page_id );
}
