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
		switch_to_blog( 4 );
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

	switch_to_blog( 4 );
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
