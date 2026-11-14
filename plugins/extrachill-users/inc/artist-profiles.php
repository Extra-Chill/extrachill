<?php
/**
 * Artist Profile Functions for Users
 *
 * Network-wide functions for retrieving user's artist profile relationships.
 * Centralized location for user-artist data access across the entire multisite network.
 *
 * Note: ec_get_artist_profile_by_slug() moved to extrachill-network/inc/cross-site-links/entity-links.php
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
 * @param int|null $user_id       User ID (defaults to current user).
 * @param bool     $admin_override Allow admins to access ALL artists (default: false).
 * @return array                   Array of published artist profile IDs
 */
function ec_get_artists_for_user( $user_id = null, $admin_override = false ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id ) {
		return array();
	}

	// Admin override: return ALL artists (only for management contexts).
	if ( $admin_override && user_can( $user_id, 'manage_options' ) ) {
		$artist_blog_id = ec_get_blog_id( 'artist' );
		if ( ! $artist_blog_id ) {
			return array();
		}

		switch_to_blog( $artist_blog_id );
		try {
			$artist_posts = get_posts(
				array(
					'post_type'   => 'artist_profile',
					'post_status' => 'publish',
					'numberposts' => -1,
					'fields'      => 'ids',
				)
			);

			return $artist_posts;
		} finally {
			restore_current_blog();
		}
	}

	// Default: return user's owned artists (for regular users and frontend admins).
	$repository      = new \ExtraChill\Users\Teams\TeamMembershipRepository();
	$user_artist_ids = $repository->term_ids( 'artist', array(
		'user_id' => (int) $user_id,
		'status'  => 'active',
	) );

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return array();
	}

	switch_to_blog( $artist_blog_id );
	try {
		$published_artists = array();
		foreach ( $user_artist_ids as $artist_id ) {
			$artist_id_int = absint( $artist_id );
			if ( 0 >= $artist_id_int || 'artist_profile' !== get_post_type( $artist_id_int ) || 'publish' !== get_post_status( $artist_id_int ) ) {
				continue;
			}

			$published_artists[] = $artist_id_int;
		}

		return $published_artists;
	} finally {
		restore_current_blog();
	}
}

/**
 * Check if user can create artist profiles
 *
 * @deprecated-soon Slated for migration to ec_user_can( 'create_artist_profile' )
 *                  and deletion per Extra-Chill/extrachill-users#60 (pending the
 *                  refactor tooling in homeboy#3075). Kept as a thin compat
 *                  function; ec_user_can() delegates here so logic has one home.
 *
 * @param int|null $user_id User ID (defaults to current user).
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
 * Resolve the Link Page bound to an artist profile, in any post status.
 *
 * The Link Pages runtime owner reference (`post:<artist_blog>:artist_profile:<id>`)
 * is the only artist/page binding. Returns 0 when the runtime is unavailable,
 * no page is bound, or the binding is ambiguous.
 *
 * @param int $artist_id Artist profile ID on the Artist site.
 * @return int Link Page ID on the storage blog, or 0.
 */
function ec_users_get_artist_link_page_id( $artist_id ) {
	$artist_id      = absint( $artist_id );
	$artist_blog_id = (int) ec_get_blog_id( 'artist' );
	if ( ! $artist_id || ! $artist_blog_id || ! function_exists( 'ec_format_link_page_owner_reference' ) || ! function_exists( 'ec_get_link_page_id_for_owner' ) ) {
		return 0;
	}

	$reference = ec_format_link_page_owner_reference(
		array(
			'kind'      => 'post',
			'blog_id'   => $artist_blog_id,
			'subtype'   => 'artist_profile',
			'object_id' => $artist_id,
		)
	);
	if ( is_wp_error( $reference ) ) {
		return 0;
	}

	$link_page_id = ec_get_link_page_id_for_owner( $reference );
	return is_wp_error( $link_page_id ) ? 0 : (int) $link_page_id;
}

/**
 * Get the most recently active artist ID for a user
 *
 * Determines which artist the user has most recently worked on
 * based on link page modification time. Falls back to first artist
 * if no link pages exist.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return int Artist profile ID, or 0 if none found
 */
function ec_get_latest_artist_for_user( $user_id = null ) {
	$user_artists = ec_get_artists_for_user( $user_id );

	if ( empty( $user_artists ) ) {
		return 0;
	}

	$find_latest_artist_id = static function () use ( $user_artists ) {
		$latest_artist_id          = 0;
		$latest_modified_timestamp = 0;

		foreach ( $user_artists as $artist_id ) {
			$link_page_id = ec_users_get_artist_link_page_id( $artist_id );

			if ( $link_page_id && 'publish' === get_post_status( $link_page_id ) ) {
				$post_modified_gmt = get_post_field( 'post_modified_gmt', $link_page_id, 'raw' );
				if ( is_string( $post_modified_gmt ) && $post_modified_gmt ) {
					$current_timestamp = strtotime( $post_modified_gmt );
					if ( $current_timestamp > $latest_modified_timestamp ) {
						$latest_modified_timestamp = $current_timestamp;
						$latest_artist_id          = $artist_id;
					}
				}
			}
		}

		return $latest_artist_id;
	};

	// Link pages are queried through the canonical storage helper so the
	// lookup follows the storage blog (the dedicated Link Pages site)
	// instead of assuming the artist blog.
	if ( function_exists( 'ec_with_link_page_storage_blog' ) ) {
		$result           = ec_with_link_page_storage_blog( $find_latest_artist_id );
		$latest_artist_id = is_wp_error( $result ) ? 0 : (int) $result;
	} else {
		$artist_blog_id = ec_get_blog_id( 'artist' );
		if ( ! $artist_blog_id ) {
			return 0;
		}

		switch_to_blog( $artist_blog_id );
		try {
			$latest_artist_id = (int) $find_latest_artist_id();
		} finally {
			restore_current_blog();
		}
	}

	// Fall back to first artist if no link pages are found.
	return $latest_artist_id > 0 ? $latest_artist_id : reset( $user_artists );
}

/**
 * Check if user can manage a specific artist profile
 *
 * Network-wide permission check for artist management. The target must be a
 * published artist profile. Returns true if the user is:
 * - An administrator (manage_options capability)
 * - An active artist team member
 *
 * @deprecated-soon Slated for migration to ec_user_can( 'manage_artist', [ 'artist_id' => $id ] )
 *                  and deletion per Extra-Chill/extrachill-users#60 (pending the
 *                  refactor tooling in homeboy#3075). Kept as a thin compat
 *                  function; ec_user_can() delegates here so logic has one home.
 *
 * @param int|null $user_id   User ID (defaults to current user).
 * @param int|null $artist_id Artist profile post ID.
 * @return bool               True if user can manage the artist
 */
function ec_can_manage_artist( $user_id = null, $artist_id = null ) {
	if ( ! $user_id ) {
		$user_id = get_current_user_id();
	}

	if ( ! $user_id || ! $artist_id ) {
		return false;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return false;
	}

	switch_to_blog( $artist_blog_id );
	try {
		$post = get_post( $artist_id );
		if ( ! $post instanceof WP_Post || 'artist_profile' !== $post->post_type || 'publish' !== $post->post_status ) {
			return false;
		}
	} finally {
		restore_current_blog();
	}

	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	return in_array( (int) $artist_id, ec_get_artists_for_user( $user_id ), true );
}

/**
 * Get count of link pages for a user's artists
 *
 * Counts how many link pages exist across all of a user's artist profiles.
 *
 * @param int|null $user_id User ID (defaults to current user).
 * @return int Count of link pages
 */
function ec_get_link_page_count_for_user( $user_id = null ) {
	$user_artists = ec_get_artists_for_user( $user_id );

	if ( empty( $user_artists ) ) {
		return 0;
	}

	$count_link_pages = static function () use ( $user_artists ) {
		$link_page_count = 0;

		foreach ( $user_artists as $artist_id ) {
			$link_page_id = ec_users_get_artist_link_page_id( $artist_id );

			if ( $link_page_id && 'publish' === get_post_status( $link_page_id ) ) {
				++$link_page_count;
			}
		}

		return $link_page_count;
	};

	// Link pages are queried through the canonical storage helper so the
	// count follows the storage blog (the dedicated Link Pages site)
	// instead of assuming the artist blog.
	if ( function_exists( 'ec_with_link_page_storage_blog' ) ) {
		$result = ec_with_link_page_storage_blog( $count_link_pages );
		return is_wp_error( $result ) ? 0 : (int) $result;
	}

	$artist_blog_id = ec_get_blog_id( 'artist' );
	if ( ! $artist_blog_id ) {
		return 0;
	}

	switch_to_blog( $artist_blog_id );
	try {
		return (int) $count_link_pages();
	} finally {
		restore_current_blog();
	}
}
