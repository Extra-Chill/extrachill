<?php
/**
 * Centralized permission system for ExtraChill Artist Platform
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
            $artist_id = get_post_meta( $object_id, '_associated_artist_profile_id', true );
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