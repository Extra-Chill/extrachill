<?php
/**
 * Core Artist-User Relationship Functions
 *
 * Bidirectional relationship management between users and artist profiles.
 * UI and admin tooling moved to extrachill-admin-tools plugin.
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
function bp_add_artist_membership( $user_id, $artist_id ) {
    $user_id = absint( $user_id );
    $artist_id = absint( $artist_id );

    if ( ! $user_id || ! $artist_id || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return false;
    }

    // Step 1: Update User Meta (_artist_profile_ids on user)
    $current_artist_ids_on_user = get_user_meta( $user_id, '_artist_profile_ids', true );
    if ( ! is_array( $current_artist_ids_on_user ) ) {
        $current_artist_ids_on_user = [];
    }

    $already_member_on_user_meta = in_array( $artist_id, $current_artist_ids_on_user );

    if ( ! $already_member_on_user_meta ) {
        $current_artist_ids_on_user[] = $artist_id;
        $current_artist_ids_on_user = array_unique( $current_artist_ids_on_user );
        if ( ! update_user_meta( $user_id, '_artist_profile_ids', $current_artist_ids_on_user ) ) {
            return false;
        }
    }

    // Step 2: Update Artist Profile Post Meta (_artist_member_ids on artist_profile post)
    $current_member_ids_on_artist = get_post_meta( $artist_id, '_artist_member_ids', true );
    if ( ! is_array( $current_member_ids_on_artist ) ) {
        $current_member_ids_on_artist = [];
    }

    if ( ! in_array( $user_id, $current_member_ids_on_artist ) ) {
        $current_member_ids_on_artist[] = $user_id;
        $current_member_ids_on_artist = array_unique( array_map( 'absint', $current_member_ids_on_artist ) );
        $current_member_ids_on_artist = array_filter( $current_member_ids_on_artist, function($id) { return $id > 0; } );
        if ( ! update_post_meta( $artist_id, '_artist_member_ids', $current_member_ids_on_artist ) ) {
            // User meta is correct even if this fails
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
function bp_remove_artist_membership( $user_id, $artist_id ) {
    $user_id = absint( $user_id );
    $artist_id = absint( $artist_id );

    if ( ! $user_id || ! $artist_id ) {
        return false;
    }

    // Step 1: Update User Meta (_artist_profile_ids on user)
    $current_artist_ids_on_user = get_user_meta( $user_id, '_artist_profile_ids', true );
    $user_meta_updated_successfully = true;

    if ( is_array( $current_artist_ids_on_user ) && ! empty( $current_artist_ids_on_user ) ) {
        $key_on_user_meta = array_search( $artist_id, $current_artist_ids_on_user );
        if ( $key_on_user_meta !== false ) {
            unset( $current_artist_ids_on_user[$key_on_user_meta] );
            $current_artist_ids_on_user = array_values($current_artist_ids_on_user);
            if ( ! update_user_meta( $user_id, '_artist_profile_ids', $current_artist_ids_on_user ) ) {
                $user_meta_updated_successfully = false;
            }
        }
    }

    // Step 2: Update Artist Profile Post Meta (_artist_member_ids on artist_profile post)
    $current_member_ids_on_artist = get_post_meta( $artist_id, '_artist_member_ids', true );
    if ( ! is_array( $current_member_ids_on_artist ) ) {
        $current_member_ids_on_artist = [];
    }

    $key_on_artist_meta = array_search( $user_id, $current_member_ids_on_artist );
    if ( $key_on_artist_meta !== false ) {
        unset( $current_member_ids_on_artist[$key_on_artist_meta] );
        $current_member_ids_on_artist = array_values( array_unique( array_map( 'absint', $current_member_ids_on_artist ) ) );
        $current_member_ids_on_artist = array_filter( $current_member_ids_on_artist, function($id) { return $id > 0; } );
        if ( ! update_post_meta( $artist_id, '_artist_member_ids', $current_member_ids_on_artist ) ){
            return $user_meta_updated_successfully ? false : false;
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
function bp_get_linked_members( $artist_profile_id ) {
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
function bp_get_user_artist_memberships( $user_id ) {
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