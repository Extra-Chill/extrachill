<?php
/**
 * Centralized Link Page Creation Logic for extrch.co / extrachill.link
 *
 * Handles automatic creation of link pages for new artist profiles
 * and the creation/management of the default site link page.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates a link page for a given artist profile when it is published for the first time.
 * Uses the centralized creation filter system.
 *
 * @param int     $post_id The ID of the artist_profile post being published.
 * @param WP_Post $post    The artist_profile post object.
 */
function extrachill_artist_create_link_page_for_artist_profile( $post_id, $post ) {
	// Only run on actual post publish, not on auto-save or revisions
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( 'artist_profile' !== $post->post_type ) {
		return;
	}

	// Skip if we're in sync mode to prevent duplicate creation
	if ( class_exists( 'ArtistDataSyncManager' ) && ArtistDataSyncManager::is_syncing() ) {
		return;
	}

	// Use centralized creation filter system
	$result = ec_create_link_page( $post_id );

	// Creation filter handles everything - no defensive programming needed
}
// Auto-creation of link pages is now handled by the centralized join flow system
// Only join flow registrations will automatically create link pages
// add_action( 'publish_artist_profile', 'extrachill_artist_create_link_page_for_artist_profile', 10, 2 );
