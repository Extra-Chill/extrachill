<?php
/**
 * Clean Artist Platform bindings when a main-site artist term is deleted.
 *
 * @package ExtraChillNetwork
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove the Artist-side reference to a deleting main-site artist term.
 *
 * @param int    $term_id  Term ID being deleted.
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function extrachill_network_delete_artist_term_profile_binding( $term_id, $taxonomy ) {
	$term_id = (int) $term_id;
	if ( 'artist' !== $taxonomy || $term_id <= 0 || ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$main_blog_id   = (int) ec_get_blog_id( 'main' );
	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	if ( $main_blog_id <= 0 || $artist_blog_id <= 0 || get_current_blog_id() !== $main_blog_id ) {
		return;
	}

	$profile_id = (int) get_term_meta( $term_id, '_artist_profile_id', true );

	switch_to_blog( $artist_blog_id );
	try {
		if ( $profile_id > 0 ) {
			$profile = get_post( $profile_id );
			if ( $profile && 'artist_profile' === $profile->post_type && (int) get_post_meta( $profile_id, '_artist_term_id', true ) === $term_id ) {
				delete_post_meta( $profile_id, '_artist_term_id', $term_id );
				return;
			}
		}

		$profile_ids = get_posts(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private', 'trash', 'auto-draft' ),
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_artist_term_id',
				'meta_value'     => $term_id,
			)
		);

		if ( 1 === count( $profile_ids ) ) {
			delete_post_meta( (int) $profile_ids[0], '_artist_term_id', $term_id );
		}
	} finally {
		restore_current_blog();
	}
}
add_action( 'pre_delete_term', 'extrachill_network_delete_artist_term_profile_binding', 10, 2 );
