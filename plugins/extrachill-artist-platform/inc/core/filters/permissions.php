<?php
/**
 * Centralized permission system for Extra Chill Artist Platform
 * 
 * Single source of truth for all artist membership and management permissions.
 * Replaces scattered permission checks throughout the codebase.
 */

/**
 * Check if a user can manage a specific artist
 * 
 * @param int $user_id User ID to check
 * @param int $artist_id Artist profile ID
 * @return bool True if user can manage the artist
 */
function ec_can_manage_artist( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id || ! $artist_id ) {
        return false;
    }
    
    // Administrators can always manage
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }
    
    // Check if user is linked to this artist profile
    $user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $user_artist_ids ) ) {
        $user_artist_ids = array();
    }
    
    return in_array( (int) $artist_id, array_map( 'intval', $user_artist_ids ) );
}


/**
 * AJAX-specific permission helper: Can manage artist based on POST data
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool True if user can manage the artist
 */
function ec_ajax_can_manage_artist( $post_data ) {
    $artist_id = isset( $post_data['artist_id'] ) ? (int) $post_data['artist_id'] : 0;
    if ( ! $artist_id ) {
        return false;
    }
    
    return ec_can_manage_artist( get_current_user_id(), $artist_id );
}

/**
 * AJAX-specific permission helper: Can manage link page based on POST data
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool True if user can manage the link page
 */
function ec_ajax_can_manage_link_page( $post_data ) {
    $link_page_id = isset( $post_data['link_page_id'] ) ? (int) $post_data['link_page_id'] : 0;
    if ( ! $link_page_id ) {
        return false;
    }
    
    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);
    if ( ! $artist_id ) {
        return false;
    }
    
    return ec_can_manage_artist( get_current_user_id(), $artist_id );
}

/**
 * AJAX-specific permission helper: Admin capabilities
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool True if user has admin capabilities
 */
function ec_ajax_is_admin( $post_data ) {
    return current_user_can( 'manage_options' );
}

/**
 * AJAX-specific permission helper: Can create artist profiles
 * 
 * @param array $post_data POST data from AJAX request
 * @return bool True if user can create artist profiles
 */
function ec_ajax_can_create_artists( $post_data ) {
    return ec_can_create_artist_profiles( get_current_user_id() );
}

/**
 * Check if user can create artist profiles
 * 
 * @param int $user_id User ID to check
 * @return bool True if user can create artist profiles
 */
function ec_can_create_artist_profiles( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return false;
    }
    
    // Check if user can edit pages or is marked as artist/professional
    return user_can( $user_id, 'edit_pages' ) || 
           get_user_meta( $user_id, 'user_is_artist', true ) === '1' || 
           get_user_meta( $user_id, 'user_is_professional', true ) === '1';
}

/**
 * Get all artist profiles accessible to a user
 * 
 * Admin users get access to all published artist profiles.
 * Regular users get access to their assigned artist profiles only.
 * 
 * @param int $user_id User ID to check (defaults to current user)
 * @return array Array of artist profile IDs the user can access
 */
function ec_get_user_accessible_artists( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    // Administrators can access all published artist profiles
    if ( user_can( $user_id, 'manage_options' ) ) {
        $artist_posts = get_posts( array(
            'post_type' => 'artist_profile',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids'
        ) );
        
        return is_array( $artist_posts ) ? $artist_posts : array();
    }
    
    // Regular users get their assigned artist profiles (published only)
    $user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $user_artist_ids ) ) {
        return array();
    }
    
    // Filter to only published artist profiles
    $published_artists = array();
    foreach ( $user_artist_ids as $artist_id ) {
        $artist_id_int = absint( $artist_id );
        if ( $artist_id_int > 0 && get_post_status( $artist_id_int ) === 'publish' ) {
            $published_artists[] = $artist_id_int;
        }
    }
    
    return $published_artists;
}

/**
 * Get artist profiles owned by a user
 * 
 * Unlike ec_get_user_accessible_artists(), this function returns only the artist 
 * profiles that actually belong to the user (from _artist_profile_ids meta), 
 * regardless of admin capabilities. Use this for personal/ownership contexts
 * like avatar menus and profile counts.
 * 
 * @param int $user_id User ID to check (defaults to current user)
 * @return array Array of artist profile IDs owned by the user
 */
function ec_get_user_owned_artists( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    // Get user's assigned artist profiles (published only)
    $user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $user_artist_ids ) ) {
        return array();
    }
    
    // Filter to only published artist profiles
    $published_artists = array();
    foreach ( $user_artist_ids as $artist_id ) {
        $artist_id_int = absint( $artist_id );
        if ( $artist_id_int > 0 && get_post_status( $artist_id_int ) === 'publish' ) {
            $published_artists[] = $artist_id_int;
        }
    }
    
    return $published_artists;
}

/**
 * Central capability filter that grants all necessary permissions based on artist membership
 * 
 * @param array   $allcaps An array of all the user's capabilities
 * @param array   $caps    Array of capabilities being checked
 * @param array   $args    Context for the capability check
 * @param WP_User $user    The user object
 * @return array Filtered array of the user's capabilities
 */
function ec_filter_user_capabilities( $allcaps, $caps, $args, $user ) {
    $user_id = $user->ID;
    $cap     = $args[0];
    $object_id = isset( $args[2] ) ? $args[2] : null;
    
    // Handle create_artist_profiles capability
    if ( $cap === 'create_artist_profiles' ) {
        if ( ec_can_create_artist_profiles( $user_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    // Handle manage_artist_members capability
    if ( $cap === 'manage_artist_members' && $object_id ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            $allcaps[$cap] = true;
        }
        return $allcaps;
    }
    
    // Handle link page analytics viewing
    if ( $cap === 'view_artist_link_page_analytics' && $object_id ) {
        if ( get_post_type( $object_id ) === 'artist_link_page' ) {
            $artist_id = apply_filters('ec_get_artist_id', $object_id);
            if ( $artist_id && ec_can_manage_artist( $user_id, $artist_id ) ) {
                $allcaps[$cap] = true;
            }
        }
        return $allcaps;
    }
    
    // Handle artist profile specific capabilities
    if ( $object_id && get_post_type( $object_id ) === 'artist_profile' ) {
        if ( ec_can_manage_artist( $user_id, $object_id ) ) {
            // Grant standard WordPress post capabilities
            $post_caps = array( 'edit_post', 'delete_post', 'read_post', 'publish_post', 'manage_artist_members' );
            if ( in_array( $cap, $post_caps ) ) {
                $allcaps[$cap] = true;
            }
            
            // Handle bbPress forum capabilities
            $artist_forum_id = get_post_meta( $object_id, '_artist_forum_id', true );
            if ( $artist_forum_id && isset( $args[2] ) && $args[2] == $artist_forum_id ) {
                $forum_caps = array(
                    'spectate', 'participate', 'read_private_forums', 'publish_topics', 'edit_topics',
                    'publish_replies', 'edit_replies', 'delete_topics', 'delete_replies',
                    'moderate', 'throttle', 'assign_topic_tags', 'edit_topic_tags',
                    'edit_others_topics', 'edit_others_replies', 'delete_others_topics', 'delete_others_replies'
                );
                if ( in_array( $cap, $forum_caps ) ) {
                    $allcaps[$cap] = true;
                }
            }
        }
        
        // Handle public topic creation setting
        if ( $cap === 'publish_topics' ) {
            $artist_forum_id = get_post_meta( $object_id, '_artist_forum_id', true );
            if ( $artist_forum_id && isset( $args[2] ) && $args[2] == $artist_forum_id ) {
                $allow_public_creation = get_post_meta( $object_id, '_allow_public_topic_creation', true );
                if ( $allow_public_creation !== '1' && ! ec_can_manage_artist( $user_id, $object_id ) && ! user_can( $user_id, 'manage_options' ) ) {
                    // Don't grant capability for non-members when public creation is disabled
                    return $allcaps;
                }
            }
        }
    }
    
    return $allcaps;
}

// Hook the centralized capability filter
add_filter( 'user_has_cap', 'ec_filter_user_capabilities', 10, 4 );