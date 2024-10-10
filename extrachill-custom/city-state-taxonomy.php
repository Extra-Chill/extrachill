<?php
/**
 * Register the 'location' taxonomy for posts.
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

    // Associate the taxonomy with the 'post' post type
    register_taxonomy( 'location', array( 'post' ), $args );
}
add_action( 'init', 'extra_chill_register_location_taxonomy', 0 );

/**
 * Add a custom admin menu for assigning locations.
 */
function add_custom_location_button() {
    add_menu_page(
        'Assign Location',
        'Assign Location',
        'manage_options',
        'assign-location',
        'assign_location_callback',
        'dashicons-admin-site-alt3',
        20
    );
}
add_action( 'admin_menu', 'add_custom_location_button' );

/**
 * Callback function for the 'Assign Location' admin page.
 */
function assign_location_callback() {
    if ( isset( $_POST['run_assign_location'] ) ) {
        // Uncomment the following line if you have a migration function
        // migrate_city_state_to_location();
        assign_default_location_to_existing_posts(); // Assign default locations
        echo '<div class="notice notice-success is-dismissible"><p>Location taxonomy has been updated for existing posts.</p></div>';
    }
    ?>
    <div class="wrap">
        <h1>Assign Default Location</h1>
        <form method="post">
            <?php wp_nonce_field( 'assign_location_action', 'assign_location_nonce' ); ?>
            <input type="hidden" name="run_assign_location" value="true">
            <input type="submit" class="button-primary" value="Run Script">
        </form>
    </div>
    <?php
}

/**
 * Assigns a default 'Planet Earth' location to posts without any location.
 */
function assign_default_location_to_existing_posts() {
    // Check for nonce security
    if ( ! isset( $_POST['assign_location_nonce'] ) || ! wp_verify_nonce( $_POST['assign_location_nonce'], 'assign_location_action' ) ) {
        return;
    }

    // Ensure the "Planet Earth" term exists in the location taxonomy.
    $term = term_exists( 'earth', 'location' );
    if ( $term === 0 || $term === null ) {
        // If the term doesn't exist, create it.
        $term = wp_insert_term( 'Planet Earth', 'location', array( 'slug' => 'earth' ) );
        if ( is_wp_error( $term ) ) {
            // Handle error if term creation fails
            echo '<div class="notice notice-error is-dismissible"><p>Failed to create the default location term.</p></div>';
            return;
        }
    }

    // Now query all posts that do not have a location term assigned.
    $args = array(
        'post_type'      => 'post',
        'posts_per_page' => -1,
        'fields'         => 'ids', // Optimize query by retrieving only post IDs
        'tax_query'      => array(
            array(
                'taxonomy' => 'location',
                'operator' => 'NOT EXISTS',
            ),
        ),
    );

    $query = new WP_Query( $args );

    if ( $query->have_posts() ) {
        foreach ( $query->posts as $post_id ) {
            // Assign the "Planet Earth" location to posts without a relevant location term.
            wp_set_post_terms( $post_id, intval( $term['term_id'] ), 'location', true );
        }
        wp_reset_postdata();
    }
}
