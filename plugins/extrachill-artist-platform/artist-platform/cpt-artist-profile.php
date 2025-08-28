<?php
/**
 * Registers the 'artist_profile' Custom Post Type.
 */

// CPT registration code will go here.
function bp_register_artist_profile_cpt() {
	error_log('[DEBUG] Registering artist_profile CPT');

	$labels = array(
		'name'                  => _x( 'Artist Profiles', 'Post Type General Name', 'extrachill-artist-platform' ),
		'singular_name'         => _x( 'Artist Profile', 'Post Type Singular Name', 'extrachill-artist-platform' ),
		'menu_name'             => __( 'Artist Profiles', 'extrachill-artist-platform' ),
		'name_admin_bar'        => __( 'Artist Profile', 'extrachill-artist-platform' ),
		'archives'              => __( 'Artist Profile Archives', 'extrachill-artist-platform' ),
		'attributes'            => __( 'Artist Profile Attributes', 'extrachill-artist-platform' ),
		'parent_item_colon'     => __( 'Parent Artist Profile:', 'extrachill-artist-platform' ),
		'all_items'             => __( 'All Artist Profiles', 'extrachill-artist-platform' ),
		'add_new_item'          => __( 'Add New Artist Profile', 'extrachill-artist-platform' ),
		'add_new'               => __( 'Add New', 'extrachill-artist-platform' ),
		'new_item'              => __( 'New Artist Profile', 'extrachill-artist-platform' ),
		'edit_item'             => __( 'Edit Artist Profile', 'extrachill-artist-platform' ),
		'update_item'           => __( 'Update Artist Profile', 'extrachill-artist-platform' ),
		'view_item'             => __( 'View Artist Profile', 'extrachill-artist-platform' ),
		'view_items'            => __( 'View Artist Profiles', 'extrachill-artist-platform' ),
		'search_items'          => __( 'Search Artist Profile', 'extrachill-artist-platform' ),
		'not_found'             => __( 'Not found', 'extrachill-artist-platform' ),
		'not_found_in_trash'    => __( 'Not found in Trash', 'extrachill-artist-platform' ),
		'featured_image'        => __( 'Featured Image', 'extrachill-artist-platform' ),
		'set_featured_image'    => __( 'Set featured image', 'extrachill-artist-platform' ),
		'remove_featured_image' => __( 'Remove featured image', 'extrachill-artist-platform' ),
		'use_featured_image'    => __( 'Use as featured image', 'extrachill-artist-platform' ),
		'insert_into_item'      => __( 'Insert into artist profile', 'extrachill-artist-platform' ),
		'uploaded_to_this_item' => __( 'Uploaded to this artist profile', 'extrachill-artist-platform' ),
		'items_list'            => __( 'Artist Profiles list', 'extrachill-artist-platform' ),
		'items_list_navigation' => __( 'Artist Profiles list navigation', 'extrachill-artist-platform' ),
		'filter_items_list'     => __( 'Filter artist profiles list', 'extrachill-artist-platform' ),
	);
	$args = array(
		'label'                 => __( 'Artist Profile', 'extrachill-artist-platform' ),
		'description'           => __( 'Custom Post Type for Artist Profiles', 'extrachill-artist-platform' ),
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
        'rewrite'               => array('slug' => 'artists'), // URL slug
		// Restore capability settings -> Re-commenting for now to fix menu visibility
		/*
		'capability_type'       => array('artist_profile', 'artist_profiles'), // 'singular', 'plural'
        */
        'map_meta_cap'          => true, // Required for custom capabilities and per-post checks
		'show_in_rest'          => true, // Enable Gutenberg editor and REST API support
	);
	register_post_type( 'artist_profile', $args );

    // Define primitive capabilities that roles can be granted.
    // These are mapped via 'map_meta_cap' => true.
    // We'll grant these capabilities using the user_has_cap filter later.
    // Note: 'manage_artist_members' is a custom capability specific to our logic.
    // Standard capabilities like edit_artist_profiles are derived from capability_type.
}

// Register immediately since we're already in init
error_log('[DEBUG] Registering artist_profile CPT immediately');
bp_register_artist_profile_cpt();

// --- Meta Box for Artist Settings ---

/**
 * Adds the meta box for artist profile settings.
 */
function bp_add_artist_settings_meta_box() {
    add_meta_box(
        'bp_artist_settings',                     // Unique ID
        __( 'Artist Forum Settings', 'extrachill-artist-platform' ), // Box title
        'bp_render_artist_settings_meta_box',   // Content callback function
        'artist_profile',                    // Post type
        'side',                          // Context (normal, side, advanced)
        'low'                           // Priority
    );
}
add_action( 'add_meta_boxes', 'bp_add_artist_settings_meta_box' );

/**
 * Renders the content of the artist settings meta box.
 *
 * @param WP_Post $post The current post object.
 */
function bp_render_artist_settings_meta_box( $post ) {
    // Add a nonce field for security
    wp_nonce_field( 'bp_save_artist_settings_meta', 'bp_artist_settings_nonce' );

    // Get the current value of the setting
    $allow_public = get_post_meta( $post->ID, '_allow_public_topic_creation', true );

    // Display the checkbox
    echo '<p>';
    echo '<label for="bp_allow_public_topic_creation">';
    echo '<input type="checkbox" id="bp_allow_public_topic_creation" name="bp_allow_public_topic_creation" value="1" ' . checked( $allow_public, '1', false ) . ' /> ';
    echo __( 'Allow non-members to create topics in this artist\'s forum?', 'extrachill-artist-platform' );
    echo '</label>';
    echo '</p>';
    echo '<p class="description">';
    echo __( 'If checked, any logged-in user with permission to create topics site-wide can post in this artist\'s forum. If unchecked, only linked artist members can create new topics.', 'extrachill-artist-platform' );
    echo '</p>';
}

/**
 * Saves the meta box data for artist settings.
 *
 * @param int $post_id The ID of the post being saved.
 */
function bp_save_artist_settings_meta( $post_id ) {
    // Check if nonce is set and valid.
    if ( ! isset( $_POST['bp_artist_settings_nonce'] ) || ! wp_verify_nonce( $_POST['bp_artist_settings_nonce'], 'bp_save_artist_settings_meta' ) ) {
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
add_action( 'save_post_artist_profile', 'bp_save_artist_settings_meta' );


// --- End Meta Box --- 

// It's also good practice to flush rewrite rules when the theme/plugin is activated.
