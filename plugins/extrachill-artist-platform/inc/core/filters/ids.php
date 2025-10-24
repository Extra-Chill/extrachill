<?php
/**
 * ID Filter Functions for Extra Chill Artist Platform
 * 
 * Single source of truth for all ID-related operations.
 * Clean, filterable functions for retrieving entity IDs throughout the system.
 */

/**
 * Universal artist ID resolver - handles all context types
 * 
 * @param mixed $context Context for ID resolution (int, array, or null)
 * @return int Artist profile ID (0 if not found)
 */
function ec_get_artist_id( $context = null ) {
    // Handle array context ($_GET, $_POST, query vars)
    if ( is_array( $context ) ) {
        // Check for all artist ID key variations
        $artist_id_keys = ['artist_id', 'artist_profile_id', 'new_artist_id', 'target_artist_id', 'created_artist_id'];
        foreach ( $artist_id_keys as $key ) {
            if ( isset( $context[$key] ) && $context[$key] ) {
                $artist_id = absint( $context[$key] );
                if ( $artist_id && get_post_type( $artist_id ) === 'artist_profile' ) {
                    return $artist_id;
                }
            }
        }
        // Check for link_page_id in array to resolve to artist
        if ( isset( $context['link_page_id'] ) && $context['link_page_id'] ) {
            $link_page_id = absint( $context['link_page_id'] );
            if ( $link_page_id && get_post_type( $link_page_id ) === 'artist_link_page' ) {
                $artist_id = get_post_meta( $link_page_id, '_associated_artist_profile_id', true );
                $artist_id = $artist_id ? (int) $artist_id : 0;
                return $artist_id;
            }
        }
        return 0;
    }
    
    // Handle numeric context - auto-detect what type of ID this is
    if ( is_numeric( $context ) && $context > 0 ) {
        $id = (int) $context;
        $post_type = get_post_type( $id );
        
        // If it's already an artist profile, return it
        if ( $post_type === 'artist_profile' ) {
            return $id;
        }
        
        // If it's a link page, resolve to artist
        if ( $post_type === 'artist_link_page' ) {
            $artist_id = get_post_meta( $id, '_associated_artist_profile_id', true );
            $artist_id = $artist_id ? (int) $artist_id : 0;
            return $artist_id;
        }
        
        // If it's a forum, check if it's an artist forum and get associated artist ID
        if ( $post_type === 'forum' && function_exists('bbp_get_forum_post_type') ) {
            $is_artist_forum = get_post_meta( $id, '_is_artist_profile_forum', true );
            if ( $is_artist_forum ) {
                $artist_id = get_post_meta( $id, '_associated_artist_profile_id', true );
                $artist_id = $artist_id ? (int) $artist_id : 0;
                return $artist_id;
            }
        }
        
        if ( $post_type === false ) { // Likely a user ID
            $user_artist_ids = ec_get_artists_for_user( $id );
            if ( ! empty( $user_artist_ids ) ) {
                $first_artist_id = (int) $user_artist_ids[0];
                return $first_artist_id;
            }
        }
        
        return 0;
    }
    
    // Handle null context - current context resolution (integrate ec_get_current_artist_id logic)
    if ( is_null( $context ) ) {
        // From query var
        $qv = get_query_var( 'ec_get_artist_id' );
        if ( $qv ) {
            $aid = (int) $qv;
            if ( $aid > 0 && get_post_type( $aid ) === 'artist_profile' ) {
                return $aid;
            }
        }
        
        // From global $post if it's a link page
        global $post;
        if ( $post && isset( $post->ID ) && 'artist_link_page' === get_post_type( $post->ID ) ) {
            $artist_id = get_post_meta( $post->ID, '_associated_artist_profile_id', true );
            $artist_id = $artist_id ? (int) $artist_id : 0;
            if ( $artist_id ) {
                return $artist_id;
            }
        }
        
        // From global $post if it's an artist profile
        if ( $post && isset( $post->ID ) && 'artist_profile' === get_post_type( $post->ID ) ) {
            return (int) $post->ID;
        }
        
        return 0;
    }
    
    return 0;
}

/**
 * Universal link page ID resolver - handles all context types
 * 
 * @param mixed $context Context for ID resolution (int, array, or null)
 * @return int Link page ID (0 if not found)
 */
function ec_get_link_page_id( $context = null ) {
    // Handle array context ($_GET, $_POST, query vars)
    if ( is_array( $context ) ) {
        // Check for all link page ID key variations
        $link_page_id_keys = ['link_page_id', 'new_link_page_id', 'created_link_page_id'];
        foreach ( $link_page_id_keys as $key ) {
            if ( isset( $context[$key] ) && $context[$key] ) {
                $link_page_id = absint( $context[$key] );
                if ( $link_page_id && get_post_type( $link_page_id ) === 'artist_link_page' ) {
                    return $link_page_id;
                }
            }
        }
        // Check for artist_id in array to resolve to link page
        if ( isset( $context['artist_id'] ) && $context['artist_id'] ) {
            $artist_id = absint( $context['artist_id'] );
            if ( $artist_id && get_post_type( $artist_id ) === 'artist_profile' ) {
                $link_pages = get_posts( array(
                    'post_type' => 'artist_link_page',
                    'meta_key' => '_associated_artist_profile_id',
                    'meta_value' => (string) $artist_id,
                    'posts_per_page' => 1,
                    'fields' => 'ids'
                ) );
                $link_page_id = ! empty( $link_pages ) ? (int) $link_pages[0] : 0;
                return $link_page_id;
            }
        }
        return 0;
    }
    
    // Handle numeric context - auto-detect what type of ID this is
    if ( is_numeric( $context ) && $context > 0 ) {
        $id = (int) $context;
        $post_type = get_post_type( $id );
        
        // If it's already a link page, return it
        if ( $post_type === 'artist_link_page' ) {
            return $id;
        }
        
        // If it's an artist profile, resolve to link page
        if ( $post_type === 'artist_profile' ) {
            $link_pages = get_posts( array(
                'post_type' => 'artist_link_page',
                'meta_key' => '_associated_artist_profile_id',
                'meta_value' => (string) $id,
                'posts_per_page' => 1,
                'fields' => 'ids'
            ) );
            $link_page_id = ! empty( $link_pages ) ? (int) $link_pages[0] : 0;
            return $link_page_id;
        }
        
        if ( $post_type === false ) { // Likely a user ID
            $user_artist_ids = ec_get_artists_for_user( $id );
            if ( ! empty( $user_artist_ids ) ) {
                $first_artist_id = (int) $user_artist_ids[0];
                $link_pages = get_posts( array(
                    'post_type' => 'artist_link_page',
                    'meta_key' => '_associated_artist_profile_id',
                    'meta_value' => (string) $first_artist_id,
                    'posts_per_page' => 1,
                    'fields' => 'ids'
                ) );
                $link_page_id = ! empty( $link_pages ) ? (int) $link_pages[0] : 0;
                return $link_page_id;
            }
        }
        
        return 0;
    }
    
    // Handle null context - current context resolution (integrate ec_get_current_link_page_id logic)
    if ( is_null( $context ) ) {
        // From query var
        $qv = get_query_var( 'ec_get_link_page_id' );
        if ( $qv ) {
            $lpid = (int) $qv;
            if ( $lpid > 0 && get_post_type( $lpid ) === 'artist_link_page' ) {
                return $lpid;
            }
        }
        
        // From global $post
        global $post;
        if ( $post && isset( $post->ID ) ) {
            $pt = get_post_type( $post->ID );
            if ( 'artist_profile' === $pt ) {
                $link_pages = get_posts( array(
                    'post_type' => 'artist_link_page',
                    'meta_key' => '_associated_artist_profile_id',
                    'meta_value' => (string) $post->ID,
                    'posts_per_page' => 1,
                    'fields' => 'ids'
                ) );
                $link_page_id = ! empty( $link_pages ) ? (int) $link_pages[0] : 0;
                if ( $link_page_id ) {
                    return $link_page_id;
                }
            }
            if ( 'artist_link_page' === $pt ) {
                return (int) $post->ID;
            }
        }
        
        return 0;
    }
    
    return 0;
}

/**
 * Register query vars used by previews and cross-context retrieval
 */
add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'ec_get_artist_id';
    $vars[] = 'ec_get_link_page_id';
    $vars[] = 'is_extrch_preview_iframe';
    return $vars;
} );

/**
 * Register ID resolution functions as WordPress filters
 */
add_filter( 'ec_get_artist_id', 'ec_get_artist_id', 10, 2 );
add_filter( 'ec_get_link_page_id', 'ec_get_link_page_id', 10, 2 );


