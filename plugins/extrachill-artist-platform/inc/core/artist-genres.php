<?php
/**
 * Artist genre terms: canonical storage, resolution, and the main-site mirror.
 *
 * Genre is a closed-vocabulary `genre` taxonomy registered by extrachill-network
 * (Extra-Chill/extrachill-network#191). This module binds that taxonomy to
 * `artist_profile`, provides the single write path for artist genres (resolve
 * through the network resolver, assign existing terms, never create terms),
 * and mirrors the resolved slugs to the bound main-site `artist` term as
 * `_genres` termmeta so other sites can read them without a site switch
 * (Extra-Chill/extrachill-artist-platform#213).
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maximum number of genres an artist profile may carry.
 */
const EC_ARTIST_GENRE_MAX = 3;

/**
 * Bind the network `genre` taxonomy to `artist_profile`.
 *
 * extrachill-network registers the taxonomy at init:0; binding here at init:5
 * is a no-op until that plugin is active. No other site or plugin needs to
 * re-register the relationship.
 *
 * @return void
 */
function ec_artist_genres_bind_taxonomy() {
	if ( taxonomy_exists( 'genre' ) ) {
		register_taxonomy_for_object_type( 'genre', 'artist_profile' );
	}
}
add_action( 'init', 'ec_artist_genres_bind_taxonomy', 5 );

/**
 * Normalize user/ability genre input into a flat list of non-empty strings.
 *
 * @param mixed $value String (possibly comma separated) or string[].
 * @return string[]
 */
function ec_artist_normalize_genre_input( $value ) {
	if ( is_string( $value ) ) {
		$value = explode( ',', $value );
	}
	if ( ! is_array( $value ) ) {
		return array();
	}
	$values = array();
	foreach ( $value as $item ) {
		if ( ! is_scalar( $item ) ) {
			continue;
		}
		$item = trim( sanitize_text_field( (string) $item ) );
		if ( '' !== $item ) {
			$values[] = $item;
		}
	}
	return $values;
}

/**
 * Read the genre terms assigned to an artist profile.
 *
 * @param int $profile_id Artist profile post ID.
 * @return WP_Term[] Genre terms, ordered as assigned. Empty when the taxonomy
 *                   is unavailable (extrachill-network#191 not active).
 */
function ec_artist_get_genre_terms( $profile_id ) {
	if ( ! taxonomy_exists( 'genre' ) ) {
		return array();
	}
	$terms = get_the_terms( (int) $profile_id, 'genre' );
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}
	return $terms;
}

/**
 * Read an artist profile's genre slugs.
 *
 * @param int $profile_id Artist profile post ID.
 * @return string[] Canonical genre slugs. Empty when unassigned or the genre
 *                  vocabulary is unavailable.
 */
function ec_artist_get_genres( $profile_id ) {
	$slugs = array();
	foreach ( ec_artist_get_genre_terms( $profile_id ) as $term ) {
		$slugs[] = (string) $term->slug;
	}
	return $slugs;
}

/**
 * Read an artist profile's genre display labels.
 *
 * @param int $profile_id Artist profile post ID.
 * @return string[] Genre term names in vocabulary order of assignment.
 */
function ec_artist_get_genre_labels( $profile_id ) {
	$labels = array();
	foreach ( ec_artist_get_genre_terms( $profile_id ) as $term ) {
		$labels[] = (string) $term->name;
	}
	return $labels;
}

/**
 * Replace an artist profile's genres with resolved taxonomy terms.
 *
 * The single canonical write path. Input may be a single string (including a
 * comma-separated multi-genre value) or a list of slugs/names. Every value is
 * resolved through the network resolver; anything unresolvable is dropped and
 * no term is ever created. Assigning replaces the existing set, and the
 * bound main-site artist term mirror is refreshed.
 *
 * @param int   $profile_id Artist profile post ID.
 * @param mixed $input      Genre string, comma-separated string, or string[].
 * @return string[]|WP_Error The assigned canonical slugs, or a failure. When
 *                           the genre vocabulary is unavailable the write is
 *                           refused (fail closed) rather than storing raw text.
 */
function ec_artist_set_genres( $profile_id, $input ) {
	$profile_id = (int) $profile_id;
	if ( $profile_id <= 0 || 'artist_profile' !== get_post_type( $profile_id ) ) {
		return new WP_Error(
			'invalid_artist_profile',
			__( 'The artist profile is unavailable.', 'extrachill-artist-platform' )
		);
	}

	if ( ! function_exists( 'extrachill_network_resolve_genres' ) || ! taxonomy_exists( 'genre' ) ) {
		return new WP_Error(
			'genre_vocabulary_unavailable',
			__( 'The genre vocabulary is unavailable, so genres cannot be written.', 'extrachill-artist-platform' )
		);
	}

	$values = ec_artist_normalize_genre_input( $input );
	$slugs  = array();
	if ( ! empty( $values ) ) {
		$resolved = extrachill_network_resolve_genres( implode( ', ', $values ), EC_ARTIST_GENRE_MAX );
		$slugs    = array_slice( array_values( array_unique( array_filter( (array) $resolved ) ) ), 0, EC_ARTIST_GENRE_MAX );
	}

	$assigned = wp_set_object_terms( $profile_id, $slugs, 'genre', false );
	if ( is_wp_error( $assigned ) ) {
		return $assigned;
	}

	ec_artist_mirror_genres_to_term( $profile_id );

	return $slugs;
}

/**
 * Remove a stale genre mirror from a main-site artist term.
 *
 * Called when a profile rebinds to a different artist term so the old term
 * does not keep advertising genres it no longer owns.
 *
 * @param int $term_id Main-site artist term ID.
 * @return void
 */
function ec_artist_genres_clear_term_mirror( $term_id ) {
	$term_id = (int) $term_id;
	if ( $term_id <= 0 || ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}
	$main_blog_id = (int) ec_get_blog_id( 'main' );
	if ( $main_blog_id <= 0 ) {
		return;
	}
	switch_to_blog( $main_blog_id );
	try {
		delete_term_meta( $term_id, '_genres' );
	} finally {
		restore_current_blog();
	}
}

/**
 * Mirror an artist profile's genre slugs onto its bound main-site artist term.
 *
 * Writes `_genres` (JSON array of slugs) termmeta on the `artist` term bound
 * through `_artist_term_id`, deleting the meta when the profile has no genres.
 * Best effort: a missing binding, missing blog mapping, or missing term meta
 * infrastructure leaves the mirror stale rather than failing the write path.
 *
 * @param int $profile_id Artist profile post ID.
 * @return void
 */
function ec_artist_mirror_genres_to_term( $profile_id ) {
	$profile_id = (int) $profile_id;
	if ( $profile_id <= 0 || ! function_exists( 'ec_get_blog_id' ) ) {
		return;
	}

	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	$main_blog_id   = (int) ec_get_blog_id( 'main' );
	if ( $artist_blog_id <= 0 || $main_blog_id <= 0 ) {
		return;
	}

	$did_switch = get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}
	$term_id = (int) get_post_meta( $profile_id, '_artist_term_id', true );
	$slugs   = ec_artist_get_genres( $profile_id );
	if ( $did_switch ) {
		restore_current_blog();
	}

	if ( $term_id <= 0 ) {
		return;
	}

	switch_to_blog( $main_blog_id );
	try {
		if ( empty( $slugs ) ) {
			delete_term_meta( $term_id, '_genres' );
		} else {
			update_term_meta( $term_id, '_genres', wp_json_encode( array_values( $slugs ) ) );
		}
	} finally {
		restore_current_blog();
	}
}
