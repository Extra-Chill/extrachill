<?php
/**
 * Canonical artist identity fixture for standalone Community smokes.
 *
 * @package ExtraChillCommunity
 */

if ( ! function_exists( 'ec_get_artist_identity' ) ) {
	/**
	 * Artist identity contract fixture for the isolated Community harness.
	 *
	 * Production loads extrachill-network/inc/core/artist-identity.php. This
	 * suite runs without sibling plugins, so it mirrors the profile_id lookup
	 * on top of the suite's own post stubs. The profile is read on the artist
	 * blog, which is switched only when needed and always restored. Records
	 * are not cached.
	 *
	 * @param array $by Lookup with exactly one of profile_id, term_id or slug.
	 * @return array|null Identity, or null when absent or unsupported.
	 */
	function ec_get_artist_identity( array $by ) {
		$profile_id     = isset( $by['profile_id'] ) && 1 === count( $by ) ? (int) $by['profile_id'] : 0;
		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		if ( 0 >= $profile_id || 0 >= $artist_blog_id ) {
			return null;
		}

		$switched = ! function_exists( 'get_current_blog_id' ) || (int) get_current_blog_id() !== $artist_blog_id;
		if ( $switched ) {
			switch_to_blog( $artist_blog_id );
		}
		try {
			$post = get_post( $profile_id );
			if ( ! is_object( $post ) || 'artist_profile' !== ( $post->post_type ?? '' ) ) {
				return null;
			}
			$thumbnail_url = function_exists( 'get_the_post_thumbnail_url' ) ? get_the_post_thumbnail_url( $post, 'thumbnail' ) : '';

			return array(
				'profile_id'    => $profile_id,
				'term_id'       => 0,
				'slug'          => (string) ( $post->post_name ?? '' ),
				'name'          => (string) $post->post_title,
				'status'        => (string) $post->post_status,
				'permalink'     => (string) get_permalink( $post ),
				'thumbnail_id'  => is_string( $thumbnail_url ) && '' !== $thumbnail_url ? 1 : 0,
				'thumbnail_url' => is_string( $thumbnail_url ) ? $thumbnail_url : '',
				'term_slug'     => '',
				'term_name'     => '',
				'binding_valid' => false,
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}
