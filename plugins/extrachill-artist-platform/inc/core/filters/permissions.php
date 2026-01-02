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
 * WordPress capability filtering for artist permissions
 */
function ec_filter_user_capabilities( $allcaps, $caps, $args, $user ) {
    $user_id = $user->ID;
    $cap     = $args[0];
    $object_id = isset( $args[2] ) ? $args[2] : null;
    
    if ( $cap === 'create_artist_profiles' ) {
        if ( ec_can_create_artist_profiles( $user_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    if ( $cap === 'manage_artist_members' && $object_id ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    if ( $cap === 'view_artist_link_page_analytics' && $object_id ) {
        if ( get_post_type( $object_id ) === 'artist_link_page' ) {
            $artist_id = apply_filters('ec_get_artist_id', $object_id);
            if ( $artist_id && ec_can_manage_artist( $user_id, $artist_id ) ) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }
    
    if ( $object_id && get_post_type( $object_id ) === 'artist_profile' ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            $post_caps = array( 'edit_post', 'delete_post', 'read_post', 'publish_post', 'manage_artist_members' );
            if ( in_array( $cap, $post_caps ) ) {
                $allcaps[$cap] = true;
            }
        }
    }
    
    return $allcaps;
}

add_filter( 'user_has_cap', 'ec_filter_user_capabilities', 10, 4 );