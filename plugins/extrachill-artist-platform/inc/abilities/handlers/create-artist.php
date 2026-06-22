<?php
/**
 * Handler: extrachill/create-artist
 *
 * @package ExtraChillArtistPlatform
 * @since 1.7.0
 */

defined( 'ABSPATH' ) || exit;

/*
 * Event-name contract (Extra-Chill/extrachill-users#129).
 * -------------------------------------------------------
 * The canonical artist-funnel event_type name is defined ONCE in
 * extrachill-analytics (inc/core/event-types.php) as
 * `EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED`. extrachill-analytics owns
 * the analytics substrate and the `extrachill/track-analytics-event`
 * ability this handler already calls at runtime, and is network-active,
 * so the constant is guaranteed present here — referencing it adds no new
 * coupling. The emit site below references the analytics constant directly
 * (no local copy of the literal), so a rename happens in exactly one place.
 */

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

	// Duplicate guard: reject when this same user already owns an
	// artist_profile with an identical case-insensitive, trimmed title.
	// Enforced server-side regardless of client (Extra-Chill/extrachill-artist-platform#80).
	// Mirrors the established one-per-artist link-page guard at
	// inc/core/filters/create.php:128 (return WP_Error 'already_exists').
	// ec_get_artists_for_user() (extrachill-users) is the single source of
	// truth for a user's published profiles and handles its own blog switch,
	// so call it before switching context here.
	if ( $user_id && function_exists( 'ec_get_artists_for_user' ) ) {
		$existing_artist_ids = ec_get_artists_for_user( $user_id );
		if ( ! empty( $existing_artist_ids ) ) {
			$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
			if ( $artist_blog_id ) {
				switch_to_blog( $artist_blog_id );
				$normalized_new = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
				foreach ( $existing_artist_ids as $existing_id ) {
					$existing_title      = trim( (string) get_the_title( (int) $existing_id ) );
					$normalized_existing = function_exists( 'mb_strtolower' ) ? mb_strtolower( $existing_title ) : strtolower( $existing_title );
					if ( $normalized_existing === $normalized_new ) {
						restore_current_blog();
						return new WP_Error(
							'duplicate_artist_name',
							sprintf(
								/* translators: %s: the duplicate artist profile name. */
								__( 'You already have an artist profile named "%s". Open the existing profile instead of creating a duplicate.', 'extrachill-artist-platform' ),
								$name
							),
							array( 'existing_artist_id' => (int) $existing_id )
						);
					}
				}
				restore_current_blog();
			}
		}
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

	// Emit the funnel event while still in the artist blog context so the
	// event row is stamped with the artist site's blog_id. The existing
	// extrachill/get-analytics-summary reader aggregates this with no new
	// read surface. user_id is carried in the payload (and stamped on the
	// row when the request runs as the creating user) for cohort joins, and
	// the anonymous first-party visitor_id is passed so this completion step
	// stitches to the upstream artist_signup_started / user_registration rows
	// for the same member (Extra-Chill/extrachill-users#145 stitching).
	ec_artist_platform_emit_funnel_event(
		EC_ANALYTICS_EVENT_ARTIST_PROFILE_CREATED,
		array(
			'user_id'   => $user_id,
			'artist_id' => (int) $artist_id,
		)
	);

	restore_current_blog();

	if ( function_exists( 'ec_add_artist_membership' ) ) {
		ec_add_artist_membership( $user_id, $artist_id );
	}

	$get_ability = wp_get_ability( 'extrachill/get-artist-data' );
	if ( $get_ability ) {
		return $get_ability->execute( array( 'artist_id' => $artist_id ) );
	}

	return array(
		'id'   => (int) $artist_id,
		'name' => $name,
	);
}
