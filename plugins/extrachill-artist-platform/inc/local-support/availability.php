<?php
/**
 * Artist-controlled local support availability.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META = '_local_support_available';
const EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META     = '_local_support_scene';

/**
 * Resolve a canonical Events location through the Users-owned contract.
 *
 * @param string $slug Location slug.
 * @return array|WP_Error
 */
function extrachill_artist_platform_resolve_local_support_scene( $slug ) {
	if ( ! function_exists( 'extrachill_users_resolve_local_scene' ) ) {
		return new WP_Error( 'local_scene_dependency_missing', __( 'Canonical Local Scene resolution is unavailable.', 'extrachill-artist-platform' ) );
	}

	return extrachill_users_resolve_local_scene( sanitize_title( $slug ) );
}

/**
 * Read one artist's local support preference.
 *
 * @param int $artist_id Artist profile ID.
 * @return array|WP_Error
 */
function extrachill_artist_platform_get_local_support_availability( $artist_id ) {
	$artist_id     = absint( $artist_id );
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
	if ( ! $artist_id || ! $artist_blog_id ) {
		return new WP_Error( 'invalid_artist', __( 'A valid artist profile is required.', 'extrachill-artist-platform' ) );
	}

	$did_switch = get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		if ( 'artist_profile' !== get_post_type( $artist_id ) ) {
			return new WP_Error( 'invalid_artist', __( 'A valid artist profile is required.', 'extrachill-artist-platform' ) );
		}

		$available  = '1' === (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META, true );
		$scene_slug = (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META, true );
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}

	$scene = null;
	if ( '' !== $scene_slug ) {
		$scene = extrachill_artist_platform_resolve_local_support_scene( $scene_slug );
		if ( is_wp_error( $scene ) ) {
			return $scene;
		}
	}

	return array(
		'artist_id' => $artist_id,
		'available' => $available,
		'scene'     => $scene,
	);
}

/**
 * Persist one artist's local support preference after exact manager authorization.
 *
 * @param int         $artist_id Artist profile ID.
 * @param bool        $available Whether the artist is available.
 * @param string|null $scene_slug Optional canonical location override.
 * @param int         $actor_id Acting manager user ID.
 * @return array|WP_Error
 */
function extrachill_artist_platform_update_local_support_availability( $artist_id, $available, $scene_slug, $actor_id ) {
	$artist_id = absint( $artist_id );
	$actor_id  = absint( $actor_id );
	if ( ! $artist_id || ! $actor_id || ! function_exists( 'ec_can_manage_artist' ) || ! ec_can_manage_artist( $actor_id, $artist_id ) ) {
		return new WP_Error( 'artist_access_denied', __( 'You are not allowed to manage this artist.', 'extrachill-artist-platform' ) );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ) );
	}

	$did_switch = get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		if ( 'artist_profile' !== get_post_type( $artist_id ) ) {
			return new WP_Error( 'invalid_artist', __( 'A valid artist profile is required.', 'extrachill-artist-platform' ) );
		}

		if ( ! $available ) {
			delete_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META );
			if ( '' !== (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META, true ) ) {
				return new WP_Error( 'local_support_save_failed', __( 'Local support availability could not be disabled.', 'extrachill-artist-platform' ) );
			}
			delete_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META );
			return array(
				'artist_id' => $artist_id,
				'available' => false,
				'scene'     => null,
			);
		}
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}

	if ( null !== $scene_slug && '' !== trim( (string) $scene_slug ) ) {
		$scene = extrachill_artist_platform_resolve_local_support_scene( $scene_slug );
	} elseif ( function_exists( 'extrachill_users_get_local_scene' ) ) {
		$scene = extrachill_users_get_local_scene( $actor_id );
	} else {
		$scene = new WP_Error( 'local_scene_dependency_missing', __( 'Canonical Local Scene resolution is unavailable.', 'extrachill-artist-platform' ) );
	}

	if ( is_wp_error( $scene ) ) {
		return $scene;
	}
	if ( ! is_array( $scene ) || empty( $scene['slug'] ) ) {
		return new WP_Error( 'local_support_scene_required', __( 'Choose a canonical Local Scene before enabling local support availability.', 'extrachill-artist-platform' ) );
	}

	$scene_slug = sanitize_title( $scene['slug'] );
	$did_switch = get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		update_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META, $scene_slug );
		if ( $scene_slug !== (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META, true ) ) {
			return new WP_Error( 'local_support_save_failed', __( 'The matching scene could not be saved.', 'extrachill-artist-platform' ) );
		}

		update_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META, '1' );
		if ( '1' !== (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META, true ) ) {
			return new WP_Error( 'local_support_save_failed', __( 'Local support availability could not be saved.', 'extrachill-artist-platform' ) );
		}
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}

	return array(
		'artist_id' => $artist_id,
		'available' => true,
		'scene'     => $scene,
	);
}

/**
 * Return reciprocal, existing artist managers as notification recipient IDs.
 *
 * @param int $artist_id Artist profile ID in the current artist-blog context.
 * @return int[]
 */
function extrachill_artist_platform_get_local_support_manager_ids( $artist_id ) {
	$member_ids = get_post_meta( $artist_id, '_artist_member_ids', true );
	if ( ! is_array( $member_ids ) ) {
		return array();
	}

	$manager_ids = array();
	foreach ( array_unique( array_map( 'absint', $member_ids ) ) as $user_id ) {
		$artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
		if ( $user_id && get_userdata( $user_id ) && is_array( $artist_ids ) && in_array( (int) $artist_id, array_map( 'absint', $artist_ids ), true ) ) {
			$manager_ids[] = $user_id;
		}
	}

	return $manager_ids;
}

/**
 * Resolve privacy-safe candidates for an authorized producer.
 *
 * @param string $producer Producer identifier authorized by its owning plugin.
 * @param string $scene_slug Canonical Events location slug.
 * @param string $genre Optional exact, case-insensitive genre.
 * @param int[]  $exclude_artist_ids Artist profile IDs already attached to an event.
 * @return array|WP_Error
 */
function extrachill_artist_platform_resolve_local_support_candidates( $producer, $scene_slug, $genre = '', $exclude_artist_ids = array() ) {
	$producer = sanitize_key( $producer );
	$scene    = extrachill_artist_platform_resolve_local_support_scene( $scene_slug );
	if ( is_wp_error( $scene ) ) {
		return $scene;
	}
	if ( '' === $producer || ! apply_filters( 'extrachill_artist_platform_local_support_producer_authorized', false, $producer, $scene ) ) {
		return new WP_Error( 'local_support_producer_forbidden', __( 'This producer is not authorized to resolve local support candidates.', 'extrachill-artist-platform' ) );
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
	if ( ! $artist_blog_id ) {
		return new WP_Error( 'dependency_missing', __( 'The artist site is unavailable.', 'extrachill-artist-platform' ) );
	}

	$genre              = trim( sanitize_text_field( $genre ) );
	$exclude_artist_ids = array_unique( array_filter( array_map( 'absint', (array) $exclude_artist_ids ) ) );
	$did_switch         = get_current_blog_id() !== $artist_blog_id;
	if ( $did_switch ) {
		switch_to_blog( $artist_blog_id );
	}

	try {
		$artist_ids = get_posts(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => EXTRACHILL_ARTIST_LOCAL_SUPPORT_AVAILABLE_META,
				'meta_value'     => '1',
			)
		);
		$candidates = array();
		foreach ( $artist_ids as $artist_id ) {
			$artist_id = absint( $artist_id );
			if ( in_array( $artist_id, $exclude_artist_ids, true ) || (string) $scene['slug'] !== (string) get_post_meta( $artist_id, EXTRACHILL_ARTIST_LOCAL_SUPPORT_SCENE_META, true ) ) {
				continue;
			}

			$artist_genre = (string) get_post_meta( $artist_id, '_genre', true );
			if ( '' !== $genre && 0 !== strcasecmp( $genre, trim( $artist_genre ) ) ) {
				continue;
			}

			$manager_ids = extrachill_artist_platform_get_local_support_manager_ids( $artist_id );
			$term_id     = function_exists( 'ec_get_artist_term_id' ) ? absint( ec_get_artist_term_id( $artist_id ) ) : 0;
			if ( ! $term_id || empty( $manager_ids ) ) {
				continue;
			}

			$profile_image_id = get_post_thumbnail_id( $artist_id );
			$header_image_id  = absint( get_post_meta( $artist_id, '_artist_profile_header_image_id', true ) );
			$candidates[]     = array(
				'artist_profile_id' => $artist_id,
				'artist_term_id'    => $term_id,
				'name'              => get_the_title( $artist_id ),
				'slug'              => get_post_field( 'post_name', $artist_id ),
				'permalink'         => get_permalink( $artist_id ),
				'genre'             => '' !== $artist_genre ? $artist_genre : null,
				'local_city'        => get_post_meta( $artist_id, '_local_city', true ) ?: null,
				'profile_image_url' => $profile_image_id ? wp_get_attachment_image_url( $profile_image_id, 'medium' ) : null,
				'header_image_url'  => $header_image_id ? wp_get_attachment_image_url( $header_image_id, 'large' ) : null,
				'manager_user_ids'  => $manager_ids,
			);
		}
	} finally {
		if ( $did_switch ) {
			restore_current_blog();
		}
	}

	return array(
		'location'   => $scene,
		'candidates' => $candidates,
	);
}
