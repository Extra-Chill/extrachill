<?php
/**
 * Artist Platform Permission Helpers
 *
 * Provides permission helpers and WordPress capability filtering for artist-platform specific contexts.
 * Core permission function ec_can_manage_artist() is defined in extrachill-users plugin (network-activated).
 */

/**
 * Extract artist ID from request data
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return int Artist ID or 0 if not found
 */
function ec_get_permission_artist_id( $data ) {
    $artist_id = isset( $data['artist_id'] ) ? (int) $data['artist_id'] : 0;
    if ( ! $artist_id ) {
        return 0;
    }

    return ec_can_manage_artist( get_current_user_id(), $artist_id ) ? $artist_id : 0;
}

/**
 * Extract link page ID from request data and validate permissions
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return int|false Artist ID if user can manage link page, false otherwise
 */
function ec_get_permission_link_page_id( $data ) {
    $link_page_id = isset( $data['link_page_id'] ) ? (int) $data['link_page_id'] : 0;
    if ( ! $link_page_id ) {
        return false;
    }

    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    if ( ! $artist_id ) {
        return false;
    }

    return ec_can_manage_artist( get_current_user_id(), $artist_id ) ? $artist_id : false;
}

/**
 * Check if current user is admin from request data
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return bool True if user can manage options
 */
function ec_get_permission_is_admin( $data ) {
    return current_user_can( 'manage_options' );
}

/**
 * Check if user can create artists from request data
 * 
 * @param array $data Request data (POST, GET, or other)
 * @return bool True if user can create artist profiles
 */
function ec_get_permission_can_create_artists( $data ) {
    return ec_can_create_artist_profiles( get_current_user_id() );
}

/**
 * Resolve an artist-owned object to its artist profile.
 *
 * Revisions and autosaves inherit ownership from their parent. Future private
 * artist-owned post types can add their canonical relationship here.
 *
 * @param int $object_id Post or revision ID.
 * @return int Artist profile ID, or 0 when the object is not artist-owned.
 */
function ec_get_artist_id_for_owned_object( $object_id ) {
	$post = get_post( absint( $object_id ) );
	if ( ! $post ) {
		return 0;
	}

	if ( 'revision' === $post->post_type ) {
		$post = get_post( $post->post_parent );
		if ( ! $post ) {
			return 0;
		}
	}

	return 'artist_profile' === $post->post_type ? (int) $post->ID : 0;
}

/**
 * Check object access against both sides of the reciprocal membership.
 *
 * @param int $user_id   User ID.
 * @param int $artist_id Artist profile ID.
 * @return bool Whether the user may manage the artist.
 */
function ec_user_can_manage_artist_object( $user_id, $artist_id ) {
	if ( user_can( $user_id, 'manage_options' ) ) {
		return true;
	}

	$user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
	$artist_user_ids = get_post_meta( $artist_id, '_artist_member_ids', true );

	return is_array( $user_artist_ids )
		&& is_array( $artist_user_ids )
		&& in_array( (int) $artist_id, array_map( 'intval', $user_artist_ids ), true )
		&& in_array( (int) $user_id, array_map( 'intval', $artist_user_ids ), true );
}

/**
 * Map revision deletion to the owning artist profile.
 *
 * Core already maps edit/read checks on revisions to the parent, but deliberately
 * maps revision deletion to do_not_allow. The revisions REST controller checks
 * both parent and revision deletion, so artist revisions need the parent's map.
 *
 * @param string[] $caps    Primitive capabilities required by WordPress.
 * @param string   $cap     Requested capability.
 * @param int      $user_id User ID.
 * @param array    $args    Capability arguments, beginning with object ID.
 * @return string[] Required primitive capabilities.
 */
function ec_map_artist_object_capabilities( $caps, $cap, $user_id, $args ) {
	if ( 'delete_post' !== $cap || empty( $args[0] ) ) {
		return $caps;
	}

	$post = get_post( absint( $args[0] ) );
	if ( ! $post || 'revision' !== $post->post_type || ! ec_get_artist_id_for_owned_object( $post->ID ) ) {
		return $caps;
	}

	return map_meta_cap( 'delete_post', $user_id, $post->post_parent );
}
add_filter( 'map_meta_cap', 'ec_map_artist_object_capabilities', 10, 4 );

/**
 * Satisfy WordPress's mapped primitive caps for an artist-owned object.
 *
 * @param bool[]   $allcaps User's available primitive capabilities.
 * @param string[] $caps   Primitive capabilities required by WordPress.
 * @param array    $args    Requested capability, user ID, and object ID.
 * @param WP_User  $user    User object.
 * @return bool[] Filtered primitive capabilities.
 */
function ec_filter_user_capabilities( $allcaps, $caps, $args, $user ) {
	$admin_caps = array(
		'edit_artist_profiles',
		'edit_others_artist_profiles',
		'edit_private_artist_profiles',
		'edit_published_artist_profiles',
		'delete_artist_profiles',
		'delete_others_artist_profiles',
		'delete_private_artist_profiles',
		'delete_published_artist_profiles',
		'publish_artist_profiles',
		'read_private_artist_profiles',
	);
	$object_caps = array(
		'edit_post',
		'read_post',
		'delete_post',
		'publish_post',
		'edit_artist_profile',
		'read_artist_profile',
		'delete_artist_profile',
		'edit_post_meta',
		'add_post_meta',
		'delete_post_meta',
	);
	$meta_caps = array(
		'edit_post_meta',
		'add_post_meta',
		'delete_post_meta',
	);
	$meta_edit_caps = array(
		'edit_artist_profiles',
		'edit_others_artist_profiles',
		'edit_private_artist_profiles',
		'edit_published_artist_profiles',
	);
	$cap         = $args[0] ?? '';
	$object_id   = isset( $args[2] ) ? absint( $args[2] ) : 0;
	if ( in_array( $cap, $admin_caps, true ) && user_can( $user->ID, 'manage_options' ) ) {
		$allcaps[ $cap ] = true;
		return $allcaps;
	}

	if ( ! $object_id || ! in_array( $cap, $object_caps, true ) ) {
		return $allcaps;
	}

	$artist_id = ec_get_artist_id_for_owned_object( $object_id );
	if ( ! $artist_id || ! ec_user_can_manage_artist_object( $user->ID, $artist_id ) ) {
		return $allcaps;
	}

	foreach ( $caps as $primitive_cap ) {
		if ( in_array( $cap, $meta_caps, true ) ) {
			if ( in_array( $primitive_cap, $meta_edit_caps, true ) ) {
				$allcaps[ $primitive_cap ] = true;
			}
		} elseif ( 'do_not_allow' !== $primitive_cap ) {
			$allcaps[ $primitive_cap ] = true;
		}
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'ec_filter_user_capabilities', 10, 4 );
