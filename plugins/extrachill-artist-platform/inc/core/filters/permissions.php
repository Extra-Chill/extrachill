<?php
/**
 * Centralized permission system for artist platform
 */

/**
 * Check if user can manage artist profile
 *
 * @param int|null $user_id   User ID (defaults to current user)
 * @param int|null $artist_id Artist profile post ID
 * @return bool               True if user can manage artist
 */
function ec_can_manage_artist( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }

    if ( ! $user_id || ! $artist_id ) {
        return false;
    }

    // Admin always has access
    if ( user_can( $user_id, 'manage_options' ) ) {
        return true;
    }

    // Check if user is post author (primary owner)
    $post = get_post( $artist_id );
    if ( $post && (int) $post->post_author === (int) $user_id ) {
        return true;
    }

    // Check if user is in member list (additional access via meta)
    $user_artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $user_artist_ids ) ) {
        $user_artist_ids = array();
    }

    return in_array( (int) $artist_id, array_map( 'intval', $user_artist_ids ) );
}


/**
 * AJAX permission check for artist management
 *
 * @param array $post_data AJAX request data
 * @return bool            True if current user can manage artist
 */
function ec_ajax_can_manage_artist( $post_data ) {
    $artist_id = isset( $post_data['artist_id'] ) ? (int) $post_data['artist_id'] : 0;
    if ( ! $artist_id ) {
        return false;
    }

    return ec_can_manage_artist( get_current_user_id(), $artist_id );
}

/**
 * AJAX permission check for link page management
 *
 * @param array $post_data AJAX request data
 * @return bool            True if current user can manage link page
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
 * AJAX permission check for admin capabilities
 *
 * @param array $post_data AJAX request data (unused but required for consistency)
 * @return bool            True if current user has admin capabilities
 */
function ec_ajax_is_admin( $post_data ) {
    return current_user_can( 'manage_options' );
}

/**
 * AJAX permission check for artist profile creation
 *
 * Note: ec_can_create_artist_profiles() is provided by extrachill-users plugin (network-activated)
 *
 * @param array $post_data AJAX request data (unused but required for consistency)
 * @return bool            True if current user can create artist profiles
 */
function ec_ajax_can_create_artists( $post_data ) {
    return ec_can_create_artist_profiles( get_current_user_id() );
}

/**
 * Extends WordPress capabilities with custom artist permissions including forum-specific bbPress access control
 *
 * Dynamically grants custom capabilities for artist profile management and bbPress forum access
 * based on user ownership and settings. Handles artist profile post type permissions and
 * forum-specific permissions for artist-linked forums.
 *
 * @param array   $allcaps Array of user capabilities
 * @param array   $caps    Required capabilities for the request
 * @param array   $args    Arguments for the capability check (includes object ID)
 * @param WP_User $user    User object
 * @return array           Modified capabilities array
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

        if ( $cap === 'publish_topics' ) {
            $artist_forum_id = get_post_meta( $object_id, '_artist_forum_id', true );
            if ( $artist_forum_id && isset( $args[2] ) && $args[2] == $artist_forum_id ) {
                $allow_public_creation = get_post_meta( $object_id, '_allow_public_topic_creation', true );
                if ( $allow_public_creation !== '1' && ! ec_can_manage_artist( $user_id, $object_id ) && ! user_can( $user_id, 'manage_options' ) ) {
                    return $allcaps;
                }
            }
        }
    }
    
    return $allcaps;
}

add_filter( 'user_has_cap', 'ec_filter_user_capabilities', 10, 4 );