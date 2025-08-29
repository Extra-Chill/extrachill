<?php
/**
 * Centralized data helper functions for ExtraChill Artist Platform
 * 
 * Single source of truth for common data retrieval patterns.
 * Replaces scattered get_post_meta calls throughout the codebase.
 */

/**
 * Get the artist profile ID associated with a link page
 * 
 * @param int $link_page_id Link page post ID
 * @return int|false Artist profile ID or false if not found
 */
function ec_get_artist_for_link_page( $link_page_id ) {
    if ( ! $link_page_id || get_post_type( $link_page_id ) !== 'artist_link_page' ) {
        return false;
    }
    
    $artist_id = get_post_meta( $link_page_id, '_associated_artist_profile_id', true );
    return $artist_id ? (int) $artist_id : false;
}

/**
 * Get all artist profile IDs for a user
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of artist profile IDs
 */
function ec_get_user_artist_ids( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $artist_ids ) ) {
        return array();
    }
    
    return array_map( 'intval', $artist_ids );
}

/**
 * Get the forum ID associated with an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @return int|false Forum ID or false if not found
 */
function ec_get_forum_for_artist( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return false;
    }
    
    $forum_id = get_post_meta( $artist_id, '_artist_forum_id', true );
    return $forum_id ? (int) $forum_id : false;
}

/**
 * Check if a user is a member of a specific artist profile
 * 
 * @param int $user_id User ID (defaults to current user)
 * @param int $artist_id Artist profile ID
 * @return bool True if user is a member
 */
function ec_is_user_artist_member( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id || ! $artist_id ) {
        return false;
    }
    
    $user_artist_ids = ec_get_user_artist_ids( $user_id );
    return in_array( (int) $artist_id, $user_artist_ids );
}

/**
 * Get all followed artist profile IDs for a user
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of followed artist profile IDs
 */
function ec_get_user_followed_artists( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $followed_ids = get_user_meta( $user_id, '_followed_artist_profile_ids', true );
    if ( ! is_array( $followed_ids ) ) {
        return array();
    }
    
    return array_map( 'intval', $followed_ids );
}

/**
 * Check if a user is following a specific artist
 * 
 * @param int $user_id User ID (defaults to current user)
 * @param int $artist_id Artist profile ID
 * @return bool True if user is following the artist
 */
function ec_is_user_following_artist( $user_id = null, $artist_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id || ! $artist_id ) {
        return false;
    }
    
    $followed_artists = ec_get_user_followed_artists( $user_id );
    return in_array( (int) $artist_id, $followed_artists );
}

/**
 * Get the link page ID associated with an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @return int|false Link page ID or false if not found
 */
function ec_get_link_page_for_artist( $artist_id ) {
    if ( ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return false;
    }
    
    // Query for link page with this artist association
    $link_pages = get_posts( array(
        'post_type' => 'artist_link_page',
        'meta_key' => '_associated_artist_profile_id',
        'meta_value' => (string) $artist_id,
        'posts_per_page' => 1,
        'fields' => 'ids'
    ) );
    
    return ! empty( $link_pages ) ? (int) $link_pages[0] : false;
}

/**
 * Get all artist profiles for current user (with caching)
 * 
 * @param int $user_id User ID (defaults to current user)
 * @return array Array of WP_Post objects for artist profiles
 */
function ec_get_user_artist_profiles( $user_id = null ) {
    if ( ! $user_id ) {
        $user_id = get_current_user_id();
    }
    
    if ( ! $user_id ) {
        return array();
    }
    
    $artist_ids = ec_get_user_artist_ids( $user_id );
    if ( empty( $artist_ids ) ) {
        return array();
    }
    
    return get_posts( array(
        'post_type' => 'artist_profile',
        'post__in' => $artist_ids,
        'posts_per_page' => -1,
        'post_status' => 'publish'
    ) );
}

/**
 * Get all subscribers for an artist profile
 * 
 * @param int $artist_id Artist profile post ID
 * @param array $args Optional arguments for pagination/filtering
 * @return array Array of subscriber data
 */
function ec_get_artist_subscribers( $artist_id, $args = array() ) {
    global $wpdb;
    
    if ( ! $artist_id ) {
        return array();
    }
    
    $defaults = array(
        'per_page' => 20,
        'page' => 1,
        'include_exported' => false
    );
    $args = wp_parse_args( $args, $defaults );
    
    $table_name = $wpdb->prefix . 'artist_subscribers';
    $offset = ( $args['page'] - 1 ) * $args['per_page'];
    
    $where_clause = $wpdb->prepare( "WHERE artist_profile_id = %d", $artist_id );
    if ( ! $args['include_exported'] ) {
        $where_clause .= " AND (exported = 0 OR exported IS NULL)";
    }
    
    $sql = $wpdb->prepare(
        "SELECT * FROM {$table_name} {$where_clause} ORDER BY subscription_date DESC LIMIT %d OFFSET %d",
        $args['per_page'],
        $offset
    );
    
    return $wpdb->get_results( $sql );
}