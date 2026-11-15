<?php
/**
 * Canonical artist identity fixture for standalone Events smokes.
 *
 * @package ExtraChillEvents
 */

if ( ! function_exists( 'ec_get_artist_identity' ) ) {
	/**
	 * Artist identity contract fixture for the isolated Events harness.
	 *
	 * Production loads extrachill-network/inc/core/artist-identity.php. This
	 * suite runs without sibling plugins, so it mirrors the profile_id lookup
	 * this suite consumes: the profile's term pointer is read on the artist
	 * blog, and the caller's blog is always restored. Pointers are parsed
	 * strictly, as in production. Records are not cached.
	 *
	 * @param array $by Lookup with exactly one of profile_id, term_id or slug.
	 * @return array|null Identity, or null when the lookup is unsupported.
	 */
	function ec_get_artist_identity( array $by ) {
		$profile_id = isset( $by['profile_id'] ) && 1 === count( $by ) ? (int) $by['profile_id'] : 0;
		if ( 0 >= $profile_id ) {
			return null;
		}

		switch_to_blog( (int) ec_get_blog_id( 'artist' ) );
		try {
			$pointer = get_post_meta( $profile_id, '_artist_term_id', true );
		} finally {
			restore_current_blog();
		}
		$term_id = ( is_int( $pointer ) && 0 < $pointer ) || ( is_string( $pointer ) && ctype_digit( $pointer ) ) ? (int) $pointer : 0;

		return array(
			'profile_id'    => $profile_id,
			'term_id'       => $term_id,
			'slug'          => '',
			'name'          => '',
			'status'        => 'publish',
			'permalink'     => '',
			'thumbnail_id'  => 0,
			'thumbnail_url' => '',
			'term_slug'     => '',
			'term_name'     => '',
			'binding_valid' => false,
		);
	}
}
