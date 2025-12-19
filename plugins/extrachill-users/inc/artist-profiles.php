<?php
/**
 * Artist Profile Functions for Users
 *
 * Network-wide functions for retrieving user's artist profile relationships.
 * Centralized location for user-artist data access across the entire multisite network.
 *
 * @package ExtraChill\Users
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get published artist profile by slug.
 *
 * Network-wide canonical helper for mapping an `artist` taxonomy term slug (on any site)
 * to a published `artist_profile` post on the artist site.
 *
 * @param string $slug Artist profile slug.
 * @return array|false Array with `id` and `permalink`, or false.
 */
function ec_get_artist_profile_by_slug( $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( empty( $slug ) ) {
		return false;
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$posts = get_posts(
			array(
				'post_type'      => 'artist_profile',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return false;
		}

		$artist_id  = (int) $posts[0];
		$permalink  = get_permalink( $artist_id );
		$permalink  = $permalink ? (string) $permalink : '';

		if ( ! $permalink ) {
			return false;
		}

		return array(
			'id'        => $artist_id,
			'permalink' => $permalink,
		);
	} finally {
		restore_current_blog();
	}
}

/**
 * Get artist profiles for user
 *
 * Single source of truth for user-artist profile relationships across the network.
 * Handles both frontend (user's own artists) and management contexts (admin override for all artists).
 *
 * @param int|null $user_id       User ID (defaults to current user)
 * @param bool     $admin_override Allow admins to access ALL artists (default: false)
 * @return array                   Array of published artist profile IDs
 */
function ec_get_artists_for_user( $user_id = null, $admin_override = false ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return array();
	}

	// Admin override: return ALL artists (only for management contexts)
	if ( $admin_override && user_can( $user_id, 'manage_options' ) ) {
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
		if ( ! $artist_blog_id ) {
			return array();
		}

		switch_to_blog( $artist_blog_id );
		try {
			$artist_posts = get_posts( array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids'
			) );

			return is_array( $artist_posts ) ? $artist_posts : array();
		} finally {
			restore_current_blog();
		}
	}

	// Default: return user's owned artists (for both regular users and admins in frontend contexts)
	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
	if ( ! is_array( $user_artist_ids ) ) {
		return array();
	}

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return array();
	}

	switch_to_blog( $artist_blog_id );
	try {
		$published_artists = array();
		foreach ( $user_artist_ids as $artist_id ) {
			$artist_id_int = absint( $artist_id );
			if ( $artist_id_int > 0 && get_post_status( $artist_id_int ) === 'publish' ) {
				$published_artists[] = $artist_id_int;
			}
		}

		return $published_artists;
	} finally {
		restore_current_blog();
	}
}

/**
 * Check if user can create artist profiles
 *
 * @param int|null $user_id User ID (defaults to current user)
 * @return bool             True if user has permission to create artist profiles
 */
function ec_can_create_artist_profiles( $user_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return false;
	}

	return user_can( $user_id, 'edit_pages' ) ||
	       get_user_meta( $user_id, 'user_is_artist', true ) === '1' ||
	       get_user_meta( $user_id, 'user_is_professional', true ) === '1';
}

/**
 * Get the most recently active artist ID for a user
 *
 * Determines which artist the user has most recently worked on
 * based on link page modification time. Falls back to first artist
 * if no link pages exist.
 *
 * @param int|null $user_id User ID (defaults to current user)
 * @return int Artist profile ID, or 0 if none found
 */
function ec_get_latest_artist_for_user( $user_id = null ) {
	$user_artists = ec_get_artists_for_user( $user_id );

	if ( empty( $user_artists ) ) {
		return 0;
	}

	$latest_artist_id          = 0;
	$latest_modified_timestamp = 0;

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return 0;
	}

	switch_to_blog( $artist_blog_id );
	try {
		foreach ( $user_artists as $artist_id ) {
			$link_pages = get_posts( array(
				'post_type'      => 'artist_link_page',
				'meta_key'       => '_associated_artist_profile_id',
				'meta_value'     => (string) $artist_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );

			if ( ! empty( $link_pages ) ) {
				$link_page_id      = (int) $link_pages[0];
				$post_modified_gmt = get_post_field( 'post_modified_gmt', $link_page_id, 'raw' );
				if ( $post_modified_gmt ) {
					$current_timestamp = strtotime( $post_modified_gmt );
					if ( $current_timestamp > $latest_modified_timestamp ) {
						$latest_modified_timestamp = $current_timestamp;
						$latest_artist_id          = $artist_id;
					}
				}
			}
		}
	} finally {
		restore_current_blog();
	}

	// Fall back to first artist if no link pages found
	return $latest_artist_id > 0 ? $latest_artist_id : reset( $user_artists );
}

/**
 * Check if user can manage a specific artist profile
 *
 * Network-wide permission check for artist management. Returns true if user is:
 * - An administrator (manage_options capability)
 * - The post author of the artist profile
 * - Listed in the user's _artist_profile_ids meta (roster member)
 *
 * @param int|null $user_id   User ID (defaults to current user)
 * @param int|null $artist_id Artist profile post ID
 * @return bool               True if user can manage the artist
 */
function ec_can_manage_artist( $user_id = null, $artist_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id || ! $artist_id ) {
		return false;
	}

	// Admins can manage any artist
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	// Check if user owns this artist via user meta
	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
	if ( is_array( $user_artist_ids ) && in_array( (int) $artist_id, array_map( 'intval', $user_artist_ids ), true ) ) {
		return true;
	}

	// Check if user is post author (requires blog switch for cross-site check)
	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( $artist_blog_id ) {
		switch_to_blog( $artist_blog_id );
		try {
			$post = get_post( $artist_id );
			if ( $post && (int) $post->post_author === (int) $user_id ) {
				return true;
			}
		} finally {
			restore_current_blog();
		}
	}

	return false;
}

/**
 * Get count of link pages for a user's artists
 *
 * Counts how many link pages exist across all of a user's artist profiles.
 *
 * @param int|null $user_id User ID (defaults to current user)
 * @return int Count of link pages
 */
function ec_get_link_page_count_for_user( $user_id = null ) {
	$user_artists = ec_get_artists_for_user( $user_id );

	if ( empty( $user_artists ) ) {
		return 0;
	}

	$link_page_count = 0;

	$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
	if ( ! $artist_blog_id ) {
		return 0;
	}

	switch_to_blog( $artist_blog_id );
	try {
		foreach ( $user_artists as $artist_id ) {
			$link_pages = get_posts( array(
				'post_type'      => 'artist_link_page',
				'post_status'    => 'publish',
				'meta_key'       => '_associated_artist_profile_id',
				'meta_value'     => (string) $artist_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			) );

			if ( ! empty( $link_pages ) ) {
				$link_page_count++;
			}
		}
	} finally {
		restore_current_blog();
	}

	return $link_page_count;
}
