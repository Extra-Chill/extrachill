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

require_once __DIR__ . '/membership.php';

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
                    ec_get_user_artist_profiles( $user->ID )
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
