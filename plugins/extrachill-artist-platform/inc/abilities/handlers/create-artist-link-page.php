<?php
/**
 * Handler: extrachill/create-artist-link-page
 *
 * Explicit, mutation-only provisioning of an artist's Link Page, for artists
 * that exist without one (Extra-Chill/extrachill-artist-platform#243).
 * Idempotent: an artist that already has a page gets that page back.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create (or return) the Link Page for an artist.
 *
 * @param array $input { artist_id: int }.
 * @return array|WP_Error { artist_id, link_page_id, edit_url }.
 */
function extrachill_artist_platform_ability_create_artist_link_page( $input ) {
	$artist_id      = isset( $input['artist_id'] ) ? absint( $input['artist_id'] ) : 0;
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : get_current_blog_id();
	if ( ! $artist_id || ! $artist_blog_id ) {
		return new WP_Error( 'invalid_artist_profile', 'A valid artist_id is required.', array( 'status' => 400 ) );
	}
	if ( ! extrachill_artist_platform_ability_artist_permission( $input ) ) {
		return new WP_Error( 'artist_access_denied', 'You are not allowed to manage this artist.', array( 'status' => 403 ) );
	}
	$switched = get_current_blog_id() !== $artist_blog_id;
	if ( $switched ) {
		switch_to_blog( $artist_blog_id );
	}
	try {
		if ( 'artist_profile' !== get_post_type( $artist_id ) ) {
			return new WP_Error( 'invalid_artist_profile', 'Artist profile not found.', array( 'status' => 404 ) );
		}
		$link_page_id = (int) ec_get_link_page_for_artist( $artist_id );
		if ( ! $link_page_id || ! ec_artist_link_page_is_published( $link_page_id ) ) {
			$created = ec_create_link_page( $artist_id );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$link_page_id = (int) $created;
		}
		return array(
			'artist_id'    => $artist_id,
			'link_page_id' => $link_page_id,
			'edit_url'     => ec_get_artist_link_page_edit_url( $artist_id ),
		);
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}
