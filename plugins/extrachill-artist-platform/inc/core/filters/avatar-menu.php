<?php
/**
 * Avatar Menu Filter
 *
 * Adds artist platform menu items to the theme's avatar dropdown menu
 * via the ec_avatar_menu_items filter hook.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add artist platform menu items to avatar dropdown
 *
 * @param array $menu_items Existing menu items
 * @param int $user_id Current user ID
 * @return array Modified menu items array
 */
function extrachill_artist_platform_avatar_menu_items( $menu_items, $user_id ) {
    // Get user's accessible (published) artist profile IDs
    $user_artist_ids = ec_get_user_accessible_artists( $user_id );
    $base_manage_url = 'https://artist.extrachill.com/manage-artist-profiles/';
    $final_manage_url = $base_manage_url;

    if ( ! empty( $user_artist_ids ) ) {
        // User has one or more artist profiles - find the most recently updated one
        $latest_artist_id = 0;
        $latest_modified_timestamp = 0;

        foreach ( $user_artist_ids as $artist_id ) {
            $artist_id_int = absint( $artist_id );
            if ( $artist_id_int > 0 ) {
                $post_modified_gmt = get_post_field( 'post_modified_gmt', $artist_id_int, 'raw' );
                if ( $post_modified_gmt ) {
                    $current_timestamp = strtotime( $post_modified_gmt );
                    if ( $current_timestamp > $latest_modified_timestamp ) {
                        $latest_modified_timestamp = $current_timestamp;
                        $latest_artist_id = $artist_id_int;
                    }
                }
            }
        }

        if ( $latest_artist_id > 0 ) {
            $final_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_manage_url );
        }

        // Add Manage Artist Profile(s) menu item
        $menu_items[] = array(
            'url'      => $final_manage_url,
            'label'    => __( 'Manage Artist Profile(s)', 'extrachill-artist-platform' ),
            'priority' => 5
        );

        // Add Manage Link Page(s) menu item
        $base_link_page_manage_url = 'https://artist.extrachill.com/manage-link-page/';
        $final_link_page_manage_url = $base_link_page_manage_url;

        if ( $latest_artist_id > 0 ) {
            $final_link_page_manage_url = add_query_arg( 'artist_id', $latest_artist_id, $base_link_page_manage_url );
        }

        $menu_items[] = array(
            'url'      => $final_link_page_manage_url,
            'label'    => __( 'Manage Link Page(s)', 'extrachill-artist-platform' ),
            'priority' => 6
        );
    } else {
        // User has no accessible artist profiles - show create option for those who can create
        if ( ec_can_create_artist_profiles( $user_id ) ) {
            $menu_items[] = array(
                'url'      => $base_manage_url,
                'label'    => __( 'Create Artist Profile', 'extrachill-artist-platform' ),
                'priority' => 5
            );
        }
    }

    return $menu_items;
}

// Hook into the theme's avatar menu filter
add_filter( 'ec_avatar_menu_items', 'extrachill_artist_platform_avatar_menu_items', 10, 2 );