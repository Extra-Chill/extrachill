<?php
/**
 * Canonical artist identity and authority policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates the canonical artist term to Artist Platform profile binding and
 * authorizes an actor as an authority for that artist.
 *
 * Authority requires a two-way binding: the canonical `artist` term on the main
 * site must reference a published `artist_profile`, that profile must reference
 * the same term, and the actor must appear on the profile roster.
 */
class ArtistAuthorization {

	/**
	 * Authorize an actor as an authority for one canonical artist.
	 *
	 * @param int $artist_term_id Canonical artist term ID.
	 * @param int $user_id        Actor user ID.
	 * @return true|\WP_Error True when authorized, otherwise a denial.
	 */
	public function authorize_artist( int $artist_term_id, int $user_id ) {
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( ! function_exists( 'ec_get_artists_for_user' ) || ! in_array( $profile_id, array_map( 'intval', ec_get_artists_for_user( $user_id ) ), true ) ) {
			return $this->denied();
		}
		return true;
	}

	/**
	 * Resolve and verify the two-way canonical artist term to profile binding.
	 *
	 * @param int $artist_term_id Canonical artist term ID.
	 * @return int|\WP_Error Bound artist profile ID, or an error.
	 */
	private function artist_profile_id( int $artist_term_id ) {
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( $main_blog_id < 1 || $artist_blog_id < 1 || $artist_term_id < 1 ) {
			return new \WP_Error( 'invalid_canonical_artist', __( 'A valid canonical artist is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term = $this->read_term( $artist_term_id, 'artist', 'canonical_artist_terms_unavailable' );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$profile_id = $term ? absint( get_term_meta( $artist_term_id, '_artist_profile_id', true ) ) : 0;
		} finally {
			restore_current_blog();
		}
		if ( $profile_id < 1 ) {
			return new \WP_Error( 'invalid_canonical_artist', __( 'The canonical artist has no bound profile.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			$profile = get_post( $profile_id );
			$bound   = absint( get_post_meta( $profile_id, '_artist_term_id', true ) );
		} finally {
			restore_current_blog();
		}
		if ( ! $profile || 'artist_profile' !== $profile->post_type || 'publish' !== $profile->post_status || $bound !== $artist_term_id ) {
			return new \WP_Error( 'invalid_canonical_artist', __( 'The artist profile binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $profile_id;
	}

	/**
	 * Execute one uncached term read and preserve wpdb failures.
	 *
	 * @param int    $term_id    Term ID to read.
	 * @param string $taxonomy   Taxonomy name.
	 * @param string $error_code Error code returned on a database failure.
	 * @return \WP_Term|null|\WP_Error Term, null when missing, or an error.
	 */
	private function read_term( int $term_id, string $taxonomy, string $error_code ) {
		global $wpdb;

		$wpdb->flush();
		$query          = new \WP_Term_Query();
		$terms          = $query->query(
			array(
				'taxonomy'      => $taxonomy,
				'hide_empty'    => false,
				'include'       => array( $term_id ),
				'number'        => 1,
				'cache_results' => false,
			)
		);
		$database_error = (string) $wpdb->last_error;
		if ( '' !== $database_error || is_wp_error( $terms ) ) {
			return new \WP_Error( $error_code, __( 'Artist taxonomy data could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return empty( $terms ) ? null : reset( $terms );
	}

	/** Return a non-enumerating denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'artist_authority_forbidden', __( 'You are not authorized for this artist.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
