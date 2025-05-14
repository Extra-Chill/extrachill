<?php
/**
 * Registers the 'band_profile' Custom Post Type.
 */

// CPT registration code will go here.
function bp_register_band_profile_cpt() {

	$labels = array(
		'name'                  => _x( 'Band Profiles', 'Post Type General Name', 'generatepress_child' ),
		'singular_name'         => _x( 'Band Profile', 'Post Type Singular Name', 'generatepress_child' ),
		'menu_name'             => __( 'Band Profiles', 'generatepress_child' ),
		'name_admin_bar'        => __( 'Band Profile', 'generatepress_child' ),
		'archives'              => __( 'Band Profile Archives', 'generatepress_child' ),
		'attributes'            => __( 'Band Profile Attributes', 'generatepress_child' ),
		'parent_item_colon'     => __( 'Parent Band Profile:', 'generatepress_child' ),
		'all_items'             => __( 'All Band Profiles', 'generatepress_child' ),
		'add_new_item'          => __( 'Add New Band Profile', 'generatepress_child' ),
		'add_new'               => __( 'Add New', 'generatepress_child' ),
		'new_item'              => __( 'New Band Profile', 'generatepress_child' ),
		'edit_item'             => __( 'Edit Band Profile', 'generatepress_child' ),
		'update_item'           => __( 'Update Band Profile', 'generatepress_child' ),
		'view_item'             => __( 'View Band Profile', 'generatepress_child' ),
		'view_items'            => __( 'View Band Profiles', 'generatepress_child' ),
		'search_items'          => __( 'Search Band Profile', 'generatepress_child' ),
		'not_found'             => __( 'Not found', 'generatepress_child' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'generatepress_child' ),
		'featured_image'        => __( 'Featured Image', 'generatepress_child' ),
		'set_featured_image'    => __( 'Set featured image', 'generatepress_child' ),
		'remove_featured_image' => __( 'Remove featured image', 'generatepress_child' ),
		'use_featured_image'    => __( 'Use as featured image', 'generatepress_child' ),
		'insert_into_item'      => __( 'Insert into band profile', 'generatepress_child' ),
		'uploaded_to_this_item' => __( 'Uploaded to this band profile', 'generatepress_child' ),
		'items_list'            => __( 'Band Profiles list', 'generatepress_child' ),
		'items_list_navigation' => __( 'Band Profiles list navigation', 'generatepress_child' ),
		'filter_items_list'     => __( 'Filter band profiles list', 'generatepress_child' ),
	);
	$args = array(
		'label'                 => __( 'Band Profile', 'generatepress_child' ),
		'description'           => __( 'Custom Post Type for Band Profiles', 'generatepress_child' ),
		'labels'                => $labels,
		'supports'              => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
		'hierarchical'          => false,
		'public'                => true,
		'show_ui'               => true,
		'show_in_menu'          => true,
		'menu_position'         => 5,
        'menu_icon'             => 'dashicons-groups', // Example icon
		'show_in_admin_bar'     => true,
		'show_in_nav_menus'     => true,
		'can_export'            => true,
		'has_archive'           => true,
		'exclude_from_search'   => false,
		'publicly_queryable'    => true,
        'rewrite'               => array('slug' => 'bands'), // URL slug
		// Restore capability settings -> Re-commenting for now to fix menu visibility
		/*
		'capability_type'       => array('band_profile', 'band_profiles'), // 'singular', 'plural'
        */
        'map_meta_cap'          => true, // Required for custom capabilities and per-post checks
		'show_in_rest'          => true, // Enable Gutenberg editor and REST API support
	);
	register_post_type( 'band_profile', $args );

    // Define primitive capabilities that roles can be granted.
    // These are mapped via 'map_meta_cap' => true.
    // We'll grant these capabilities using the user_has_cap filter later.
    // Note: 'manage_band_members' is a custom capability specific to our logic.
    // Standard capabilities like edit_band_profiles are derived from capability_type.
}
add_action( 'init', 'bp_register_band_profile_cpt', 10 );

// --- Meta Box for Band Settings ---

/**
 * Adds the meta box for band profile settings.
 */
function bp_add_band_settings_meta_box() {
    add_meta_box(
        'bp_band_settings',                     // Unique ID
        __( 'Band Forum Settings', 'generatepress_child' ), // Box title
        'bp_render_band_settings_meta_box',   // Content callback function
        'band_profile',                    // Post type
        'side',                          // Context (normal, side, advanced)
        'low'                           // Priority
    );
}
add_action( 'add_meta_boxes', 'bp_add_band_settings_meta_box' );

/**
 * Renders the content of the band settings meta box.
 *
 * @param WP_Post $post The current post object.
 */
function bp_render_band_settings_meta_box( $post ) {
    // Add a nonce field for security
    wp_nonce_field( 'bp_save_band_settings_meta', 'bp_band_settings_nonce' );

    // Get the current value of the setting
    $allow_public = get_post_meta( $post->ID, '_allow_public_topic_creation', true );

    // Display the checkbox
    echo '<p>';
    echo '<label for="bp_allow_public_topic_creation">';
    echo '<input type="checkbox" id="bp_allow_public_topic_creation" name="bp_allow_public_topic_creation" value="1" ' . checked( $allow_public, '1', false ) . ' /> ';
    echo __( 'Allow non-members to create topics in this band\'s forum?', 'generatepress_child' );
    echo '</label>';
    echo '</p>';
    echo '<p class="description">';
    echo __( 'If checked, any logged-in user with permission to create topics site-wide can post in this band\'s forum. If unchecked, only linked band members can create new topics.', 'generatepress_child' );
    echo '</p>';
}

/**
 * Saves the meta box data for band settings.
 *
 * @param int $post_id The ID of the post being saved.
 */
function bp_save_band_settings_meta( $post_id ) {
    // Check if nonce is set and valid.
    if ( ! isset( $_POST['bp_band_settings_nonce'] ) || ! wp_verify_nonce( $_POST['bp_band_settings_nonce'], 'bp_save_band_settings_meta' ) ) {
        return;
    }

    // Check if the current user has permission to edit the post.
    // Use the specific capability for the CPT.
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }

    // Check if it's an autosave.
    if ( wp_is_post_autosave( $post_id ) ) {
        return;
    }

    // Check if it's a revision.
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Check if the checkbox was checked
    $new_value = isset( $_POST['bp_allow_public_topic_creation'] ) ? '1' : '0';

    // Update the post meta
    update_post_meta( $post_id, '_allow_public_topic_creation', $new_value );
}
// Hook into save_post for the specific CPT
add_action( 'save_post_band_profile', 'bp_save_band_settings_meta' );


// --- End Meta Box --- 

// It's also good practice to flush rewrite rules when the theme/plugin is activated.
