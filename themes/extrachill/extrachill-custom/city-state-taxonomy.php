<?php

// this code is used to add a custom taxonomy for city and state locations
/**
 * Register the 'location' taxonomy for posts and events.
 */
function extra_chill_register_location_taxonomy() {
    $labels = array(
        'name'              => _x( 'Locations', 'taxonomy general name', 'textdomain' ),
        'singular_name'     => _x( 'Location', 'taxonomy singular name', 'textdomain' ),
        'search_items'      => __( 'Search Locations', 'textdomain' ),
        'all_items'         => __( 'All Locations', 'textdomain' ),
        'parent_item'       => __( 'Parent Location', 'textdomain' ),
        'parent_item_colon' => __( 'Parent Location:', 'textdomain' ),
        'edit_item'         => __( 'Edit Location', 'textdomain' ),
        'update_item'       => __( 'Update Location', 'textdomain' ),
        'add_new_item'      => __( 'Add New Location', 'textdomain' ),
        'new_item_name'     => __( 'New Location Name', 'textdomain' ),
        'menu_name'         => __( 'Location', 'textdomain' ),
    );

    $args = array(
        'hierarchical'      => true,
        'labels'            => $labels,
        'public'            => true, // Ensures the taxonomy is visible publicly
        'show_ui'           => true, // Enables the taxonomy UI in admin
        'show_admin_column' => true, // Adds the taxonomy to the admin columns
        'show_in_quick_edit'=> true, // Enables Quick Edit support
        'show_in_rest'      => true, // Enables Gutenberg support
        'query_var'         => true,
        'rewrite'           => array(
            'slug'         => 'location',
            'with_front'   => false,
            'hierarchical' => true,
        ),
        // 'meta_box_cb' is omitted to use the default meta box
    );

    // Associate the taxonomy with 'post', 'tribe_events', and 'festival_wire' post types
    register_taxonomy( 'location', array( 'post', 'tribe_events', 'festival_wire' ), $args );
}
add_action( 'init', 'extra_chill_register_location_taxonomy', 0 );

/**
 * Exclude 'tribe_events' from Location taxonomy archive pages.
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 */
function exclude_tribe_events_from_location_archive( $query ) {
    // Ensure we're modifying the main query on the frontend,
    // specifically on a 'location' taxonomy archive,
    // AND NOT on a 'festival_wire' post type archive page.
    if ( ! is_admin() && $query->is_main_query() && is_tax( 'location' ) && ! is_post_type_archive( 'festival_wire' ) ) {
        // Set the post types to include both 'post' and 'festival_wire'.
        // This ensures standard posts and festival wire posts appear on the location archive page (e.g., /location/austin/)
        // while still excluding tribe_events (since it's not listed here).
        // It will not affect /festival-wire/?location=austin because is_post_type_archive('festival_wire') will be true.
        $query->set( 'post_type', array( 'post', 'festival_wire' ) );

    }
}
add_action( 'pre_get_posts', 'exclude_tribe_events_from_location_archive' ); // Re-enable the action
