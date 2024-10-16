<?php
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

    // Associate the taxonomy with both 'post' and 'tribe_events' post types
    register_taxonomy( 'location', array( 'post', 'tribe_events' ), $args );
}
add_action( 'init', 'extra_chill_register_location_taxonomy', 0 );

/**
 * Add a custom admin menu for assigning locations.
 */
function add_custom_location_button() {
    add_menu_page(
        'Assign Location',            // Page title
        'Assign Location',            // Menu title
        'manage_options',             // Capability
        'assign-location',            // Menu slug
        'assign_location_callback',   // Callback function
        'dashicons-admin-site-alt3',  // Icon
        20                            // Position
    );
}
add_action( 'admin_menu', 'add_custom_location_button' );

/**
 * Callback function for the 'Assign Location' admin page.
 */
function assign_location_callback() {
    if ( isset( $_POST['run_assign_location'] ) ) {
        // Assign 'charleston' location to existing events
        assign_charleston_to_existing_events();
        echo '<div class="notice notice-success is-dismissible"><p>Location taxonomy has been successfully updated for existing events.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Assign Default Location</h1>
        <form method="post">
            <?php wp_nonce_field( 'assign_location_action', 'assign_location_nonce' ); ?>
            <input type="hidden" name="run_assign_location" value="true">
            <p>Click the button below to assign the "charleston" location to all existing events.</p>
            <input type="submit" class="button-primary" value="Assign Charleston Location">
        </form>
    </div>
    <?php
}

/**
 * Assigns the 'charleston' location to all existing 'tribe_events' posts.
 */
function assign_charleston_to_existing_events() {
    // Check for nonce security
    if ( ! isset( $_POST['assign_location_nonce'] ) || ! wp_verify_nonce( $_POST['assign_location_nonce'], 'assign_location_action' ) ) {
        echo '<div class="notice notice-error is-dismissible"><p>Nonce verification failed. Please try again.</p></div>';
        return;
    }

    // Ensure the "charleston" term exists in the location taxonomy.
    $term = term_exists( 'charleston', 'location' );
    if ( $term === 0 || $term === null ) {
        // If the term doesn't exist, create it.
        $term = wp_insert_term( 'Charleston', 'location', array( 'slug' => 'charleston' ) );
        if ( is_wp_error( $term ) ) {
            // Handle error if term creation fails
            echo '<div class="notice notice-error is-dismissible"><p>Failed to create the "charleston" location term.</p></div>';
            return;
        }
    }

    // Get the term ID
    $term_id = is_array( $term ) ? intval( $term['term_id'] ) : intval( $term->term_id );

    // Define the post types to update
    $post_types = array( 'tribe_events' );

    foreach ( $post_types as $post_type ) {
        // Query all posts of type 'tribe_events'
        $args = array(
            'post_type'      => $post_type,
            'posts_per_page' => -1,
            'fields'         => 'ids', // Optimize query by retrieving only post IDs
        );

        $query = new WP_Query( $args );

        if ( $query->have_posts() ) {
            foreach ( $query->posts as $post_id ) {
                // Assign the "charleston" location to all tribe_events posts.
                wp_set_object_terms( $post_id, $term_id, 'location', false );

                // Optionally, log the assignment
                error_log( "Custom TEC Imports: Assigned 'charleston' location to event ID {$post_id}." );
            }
            wp_reset_postdata();
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>No events found to update.</p></div>';
            return;
        }
    }

    // Success message already echoed in the callback
}


/**
 * Exclude 'tribe_events' from Location taxonomy archive pages.
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 */
function exclude_tribe_events_from_location_archive( $query ) {
    // Ensure we're modifying the main query on the frontend and on a 'location' taxonomy archive.
    if ( ! is_admin() && $query->is_main_query() && is_tax( 'location' ) ) {
        // Set the post types to include only 'post'.
        $query->set( 'post_type', 'post' );

        // Optional: If you have other post types you want to include, add them to the array.
        // Example:
        // $query->set( 'post_type', array( 'post', 'custom_post_type' ) );

    }
}
add_action( 'pre_get_posts', 'exclude_tribe_events_from_location_archive' );
