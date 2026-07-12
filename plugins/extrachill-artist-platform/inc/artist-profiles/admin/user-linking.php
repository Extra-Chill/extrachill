<?php
/**
 * Core Artist-User Relationship Functions
 *
 * Bidirectional relationship management between users and artist profiles.
 * This plugin owns both the relationship domain and its operator tooling.
 *
 * @package ExtraChillArtistPlatform
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a artist profile ID to a user's list of memberships.
 * Also updates the artist_profile's _artist_member_ids post meta.
 *
 * @param int $user_id   The ID of the user.
 * @param int $artist_id   The ID of the artist_profile post.
 * @return bool True on success, false on failure or if already a member.
 */
function ec_add_artist_membership( $user_id, $artist_id ) {
    $user_id  = absint( $user_id );
    $artist_id = absint( $artist_id );

    if ( ! $user_id || ! $artist_id ) {
        return false;
    }

    $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;

    // Step 1: Verify artist exists (artist site)
    try {
        if ( $artist_blog_id ) {
            switch_to_blog( $artist_blog_id );
        }

        if ( get_post_type( $artist_id ) !== 'artist_profile' ) {
            return false;
        }

        // Step 2: Update Artist Profile Post Meta (_artist_member_ids on artist_profile post)
        $current_member_ids_on_artist = get_post_meta( $artist_id, '_artist_member_ids', true );
        if ( ! is_array( $current_member_ids_on_artist ) ) {
            $current_member_ids_on_artist = [];
        }

        if ( ! in_array( $user_id, $current_member_ids_on_artist, true ) ) {
            $current_member_ids_on_artist[] = $user_id;
            $current_member_ids_on_artist   = array_unique( array_map( 'absint', $current_member_ids_on_artist ) );
            $current_member_ids_on_artist   = array_values( array_filter( $current_member_ids_on_artist ) );
            update_post_meta( $artist_id, '_artist_member_ids', $current_member_ids_on_artist );
        }
    } finally {
        if ( $artist_blog_id ) {
            restore_current_blog();
        }
    }

    // Step 3: Update User Meta (_artist_profile_ids on user)
    $current_artist_ids_on_user = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $current_artist_ids_on_user ) ) {
        $current_artist_ids_on_user = [];
    }

    if ( ! in_array( $artist_id, $current_artist_ids_on_user, true ) ) {
        $current_artist_ids_on_user[] = $artist_id;
        $current_artist_ids_on_user   = array_unique( array_map( 'absint', $current_artist_ids_on_user ) );
        $current_artist_ids_on_user   = array_values( array_filter( $current_artist_ids_on_user ) );

        if ( ! update_user_meta( $user_id, '_artist_profile_ids', $current_artist_ids_on_user ) ) {
            return false;
        }
    }

    return true;
}

/**
 * Removes a artist profile ID from a user's list of memberships.
 * Also removes the user ID from the artist_profile's _artist_member_ids post meta.
 *
 * @param int $user_id   The ID of the user.
 * @param int $artist_id   The ID of the artist_profile post.
 * @return bool True on success, false on failure.
 */
function ec_remove_artist_membership( $user_id, $artist_id ) {
     $user_id  = absint( $user_id );
     $artist_id = absint( $artist_id );
 
     if ( ! $user_id || ! $artist_id ) {
         return false;
     }
 
     // Step 1: Update User Meta (_artist_profile_ids on user)
     $current_artist_ids_on_user      = get_user_meta( $user_id, '_artist_profile_ids', true );
     $user_meta_updated_successfully = true;
 
     if ( is_array( $current_artist_ids_on_user ) && ! empty( $current_artist_ids_on_user ) ) {
         $key_on_user_meta = array_search( $artist_id, $current_artist_ids_on_user, true );
         if ( $key_on_user_meta !== false ) {
             unset( $current_artist_ids_on_user[ $key_on_user_meta ] );
             $current_artist_ids_on_user = array_values( $current_artist_ids_on_user );
             if ( ! update_user_meta( $user_id, '_artist_profile_ids', $current_artist_ids_on_user ) ) {
                 $user_meta_updated_successfully = false;
             }
         }
     }
 
     $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
 
     // Step 2: Update Artist Profile Post Meta (_artist_member_ids on artist_profile post)
     try {
         if ( $artist_blog_id ) {
             switch_to_blog( $artist_blog_id );
         }
 
         $current_member_ids_on_artist = get_post_meta( $artist_id, '_artist_member_ids', true );
         if ( ! is_array( $current_member_ids_on_artist ) ) {
             $current_member_ids_on_artist = [];
         }
 
         $key_on_artist_meta = array_search( $user_id, $current_member_ids_on_artist, true );
         if ( $key_on_artist_meta !== false ) {
             unset( $current_member_ids_on_artist[ $key_on_artist_meta ] );
             $current_member_ids_on_artist = array_unique( array_map( 'absint', $current_member_ids_on_artist ) );
             $current_member_ids_on_artist = array_values( array_filter( $current_member_ids_on_artist ) );
             update_post_meta( $artist_id, '_artist_member_ids', $current_member_ids_on_artist );
         }
     } finally {
         if ( $artist_blog_id ) {
             restore_current_blog();
         }
     }
 
     return $user_meta_updated_successfully;
 }

/**
 * Gets users linked to a specific artist profile.
 *
 * @param int $artist_profile_id The ID of the artist profile CPT.
 * @return array Array of WP_User objects.
 */
function ec_get_linked_members( $artist_profile_id ) {
    if ( ! $artist_profile_id ) {
        return array();
    }

    $serialized_int_fragment = sprintf( 'i:%d;', $artist_profile_id );
    $string_id = (string) $artist_profile_id;
    $serialized_str_fragment = sprintf( 's:%d:"%s";', strlen( $string_id ), $string_id );

    $args = array(
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key'     => '_artist_profile_ids',
                'value'   => $serialized_int_fragment,
                'compare' => 'LIKE'
            ),
            array(
                'key'     => '_artist_profile_ids',
                'value'   => $serialized_str_fragment,
                'compare' => 'LIKE'
            )
        ),
        'fields' => 'all',
    );
    $user_query = new WP_User_Query( $args );

    return $user_query->get_results();
}

/**
 * Gets artist profiles linked to a specific user.
 *
 * @param int $user_id The ID of the user.
 * @return array Array of artist profile post objects.
 */
function ec_get_user_artist_memberships( $user_id ) {
    if ( ! $user_id ) {
        return array();
    }

    $artist_ids = get_user_meta( $user_id, '_artist_profile_ids', true );

    if ( empty( $artist_ids ) || ! is_array( $artist_ids ) ) {
        return array();
    }

    $args = array(
        'post_type' => 'artist_profile',
        'post_status' => 'any',
        'post__in' => $artist_ids,
        'orderby' => 'title',
        'order' => 'ASC',
        'posts_per_page' => -1
    );

    return get_posts( $args );
}

/**
 * Lists artist-user relationships for network administration.
 *
 * @param string $view   View mode: artists or users.
 * @param string $search Optional search term.
 * @return array|WP_Error Relationship rows or an artist-site configuration error.
 */
function ec_get_artist_relationships_for_admin( $view = 'artists', $search = '' ) {
    $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
    if ( ! $artist_blog_id ) {
        return new WP_Error( 'no_artist_site', __( 'Artist site not configured.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
    }

    $view   = 'users' === $view ? 'users' : 'artists';
    $search = sanitize_text_field( $search );
    $items  = array();

    switch_to_blog( $artist_blog_id );
    try {
        if ( 'artists' === $view ) {
            $args = array(
                'post_type'      => 'artist_profile',
                'post_status'    => 'any',
                'posts_per_page' => 50,
                'orderby'        => 'title',
                'order'          => 'ASC',
            );
            if ( '' !== $search ) {
                $args['s'] = $search;
            }

            foreach ( get_posts( $args ) as $artist ) {
                $members = array_map(
                    static function ( $member ) {
                        return array(
                            'ID'           => $member->ID,
                            'user_login'   => $member->user_login,
                            'display_name' => $member->display_name,
                        );
                    },
                    ec_get_linked_members( $artist->ID )
                );
                $items[] = array(
                    'id'      => $artist->ID,
                    'title'   => $artist->post_title,
                    'members' => $members,
                );
            }
        } else {
            $args = array(
                'number'     => 50,
                'meta_query' => array(
                    'relation' => 'OR',
                    array( 'key' => 'user_is_artist', 'value' => '1', 'compare' => '=' ),
                    array( 'key' => 'user_is_professional', 'value' => '1', 'compare' => '=' ),
                ),
            );
            if ( '' !== $search ) {
                $args['search']         = '*' . $search . '*';
                $args['search_columns'] = array( 'user_login', 'user_email', 'display_name' );
            }

            foreach ( ( new WP_User_Query( $args ) )->get_results() as $user ) {
                $artists = array_map(
                    static function ( $artist ) {
                        return array( 'ID' => $artist->ID, 'post_title' => $artist->post_title );
                    },
                    ec_get_user_artist_memberships( $user->ID )
                );
                $items[] = array(
                    'ID'           => $user->ID,
                    'user_login'   => $user->user_login,
                    'user_email'   => $user->user_email,
                    'display_name' => $user->display_name,
                    'artists'      => $artists,
                );
            }
        }
    } finally {
        restore_current_blog();
    }

    return $items;
}

/**
 * Finds user-side relationship entries whose artist post no longer exists.
 *
 * @return array|WP_Error Orphan rows or an artist-site configuration error.
 */
function ec_get_orphaned_artist_relationships() {
    $artist_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'artist' ) : null;
    if ( ! $artist_blog_id ) {
        return new WP_Error( 'no_artist_site', __( 'Artist site not configured.', 'extrachill-artist-platform' ), array( 'status' => 400 ) );
    }

    $orphans = array();
    switch_to_blog( $artist_blog_id );
    try {
        foreach ( get_users( array( 'meta_key' => '_artist_profile_ids' ) ) as $user ) {
            $artist_ids = get_user_meta( $user->ID, '_artist_profile_ids', true );
            if ( ! is_array( $artist_ids ) ) {
                continue;
            }
            foreach ( $artist_ids as $artist_id ) {
                if ( ! get_post( $artist_id ) || 'artist_profile' !== get_post_type( $artist_id ) ) {
                    $orphans[] = array(
                        'user'              => array(
                            'ID'           => $user->ID,
                            'user_login'   => $user->user_login,
                            'display_name' => $user->display_name,
                        ),
                        'invalid_artist_id' => $artist_id,
                    );
                }
            }
        }
    } finally {
        restore_current_blog();
    }

    return $orphans;
}
