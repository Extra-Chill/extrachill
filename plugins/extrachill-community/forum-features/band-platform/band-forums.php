<?php
/**
 * Handles automatic creation and management of bbPress forums associated with Band Profiles.
 */

/**
 * Creates a hidden bbPress forum when a 'band_profile' CPT is published for the first time.
 *
 * Hooks into save_post_band_profile.
 *
 * @param int     $post_id The ID of the post being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an update to an existing post.
 */
function bp_create_band_forum_on_save( $post_id, $post, $update ) {
    // Security checks: Nonce verification already happened in user-linking save, but good practice.
    // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'band_profile' ) {
        return;
    }

    // Check if it's an autosave or revision.
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }

    // Check user permissions (redundant if save_post check is sufficient, but safe).
    if ( ! current_user_can( 'edit_post', $post_id ) ) { 
        return;
    }

    // Check if the post status is 'publish' (or potentially other statuses if needed later).
    // We only want to create the forum when the profile is actually published.
    if ( $post->post_status !== 'publish' ) {
        return;
    }

    // Check if the forum already exists.
    $existing_forum_id = get_post_meta( $post_id, '_band_forum_id', true );
    if ( ! empty( $existing_forum_id ) ) {
        // Forum already exists, maybe update its title if band name changed?
        $forum = get_post( $existing_forum_id );
        $new_title = sprintf( __( '%s Forum', 'generatepress_child' ), $post->post_title );
        if ( $forum && $forum->post_title !== $new_title ) {
            wp_update_post( array( 'ID' => $existing_forum_id, 'post_title' => $new_title ) );
        }
        return; // Exit, forum exists.
    }

    // --- Create the new forum --- 

    // Ensure bbPress functions are available.
    if ( ! function_exists( 'bbp_insert_forum' ) ) {
        // Log error or notify admin: bbPress not active?
        return;
    }

    $forum_data = array(
        'post_title'  => sprintf( __( '%s Forum', 'generatepress_child' ), $post->post_title ),
        'post_content'=> sprintf( __( 'Discussion forum for the band %s.', 'generatepress_child' ), $post->post_title ),
        'post_status' => 'publish', // Ensure forum is created as public
        // 'post_parent' => 0, // Optional: Assign a parent forum if desired
    );

    // Create the forum post.
    $forum_id = bbp_insert_forum( $forum_data );

    if ( is_wp_error( $forum_id ) ) {
        // Log error
        return;
    }

    // Make the forum hidden.
    // bbp_hide_forum( $forum_id ); // <-- RE-COMMENT THIS LINE

    // Add meta to the new forum to identify it and link back.
    update_post_meta( $forum_id, '_is_band_profile_forum', true );
    update_post_meta( $forum_id, '_associated_band_profile_id', $post_id );

    // Store the new forum ID in the band profile CPT meta.
    $meta_update_result = update_post_meta( $post_id, '_band_forum_id', $forum_id );

    // Optional: Set initial forum settings (e.g., allow topic creation?)
    // update_post_meta( $forum_id, '_bbp_allow_topic_creaton', true ); // Example

}
// Use a priority lower than the member saving function if order matters, but 10 is usually fine.
// Use 3 arguments for $update check.
// Increase priority to 20 to potentially run after bbPress initialization
add_action( 'save_post_band_profile', 'bp_create_band_forum_on_save', 20, 3 );


/**
 * Handles deletion/trashing of the associated forum when a band profile is deleted or trashed.
 *
 * @param int $post_id The ID of the post being deleted/trashed.
 */
function bp_handle_band_profile_deletion( $post_id ) {
     // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'band_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_band_forum_id', true );

    if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        // Ensure it's actually one of our band forums before deleting.
        $is_band_forum = get_post_meta( $forum_id, '_is_band_profile_forum', true );

        if ( $forum && $is_band_forum ) {
            // Determine if the band profile is being permanently deleted or just trashed.
            if ( did_action( 'before_delete_post' ) > did_action( 'wp_trash_post' ) ) {
                // Permanently deleting the band profile - permanently delete the forum.
                wp_delete_post( $forum_id, true ); // true = force delete
            } else {
                // Trashing the band profile - trash the forum.
                wp_trash_post( $forum_id );
            }
        }
    }
}
add_action( 'wp_trash_post', 'bp_handle_band_profile_deletion' );
add_action( 'before_delete_post', 'bp_handle_band_profile_deletion' );

/**
 * Handles restoration of the associated forum when a band profile is untrashed.
 *
 * @param int $post_id The ID of the post being restored.
 */
function bp_handle_band_profile_untrash( $post_id ) {
     // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'band_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_band_forum_id', true );

     if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        // Check if the forum is in the trash.
         if ( $forum && get_post_status( $forum_id ) === 'trash' ) {
            // Ensure it's actually one of our band forums before untrashing.
             $is_band_forum = get_post_meta( $forum_id, '_is_band_profile_forum', true );
             if ( $is_band_forum ) {
                wp_untrash_post( $forum_id );
                 // Optional: bbPress might automatically set status back, but confirm if it needs hiding again.
                 // bbp_hide_forum( $forum_id ); -> REMOVED - Don't re-hide on untrash
            }
        }
    }
}
add_action( 'untrash_post', 'bp_handle_band_profile_untrash' );

/**
 * Hides band profile forums from the main forum list table in WP Admin.
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 */
function bp_hide_band_forums_from_admin_list( $query ) {
    // Check if we are in the admin area, on the main query for the 'edit-forum' screen.
    if ( is_admin() && $query->is_main_query() && function_exists('bbp_get_forum_post_type') ) {
        
        // Get current screen information
        $screen = get_current_screen();

        // Check if it's the forum list table screen and the post type is forum
        if ( isset($screen->id) && $screen->id === 'edit-forum' && $query->get('post_type') === bbp_get_forum_post_type() ) {
            
            // Get existing meta query
            $meta_query = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query ) ) {
                $meta_query = array();
            }

            // Add our condition to exclude band forums
            // 'relation' => 'AND' is the default
            $meta_query[] = array(
                'key'     => '_is_band_profile_forum',
                'compare' => 'NOT EXISTS', // Exclude if the key exists
                // Alternatively, if we always set it (even to false):
                // 'value'   => '1', 
                // 'compare' => '!='
            );

            // Set the modified meta query back to the main query object
            $query->set( 'meta_query', $meta_query );
        }
    }
}
add_action( 'pre_get_posts', 'bp_hide_band_forums_from_admin_list' ); 

/**
 * Ensures bbPress core template functions are loaded for single band profile views.
 *
 * Addresses scenarios where the CPT template loads before bbPress initializes fully.
 */
function bp_ensure_bbpress_loaded_for_band_profile() {
    // Only run on frontend single band_profile views
    if ( ! is_admin() && is_singular('band_profile') ) {
        // Check if the key function needed by the template exists
        if ( ! function_exists( 'bbp_topic_index' ) ) {
            // Check if bbPress core is loaded
            if ( function_exists( 'bbpress' ) ) {
                 bbpress()->setup_globals(); // Try running bbPress's own context setup

                 // After setup_globals, check again if the function exists or try including
                 if ( ! function_exists( 'bbp_topic_index' ) && isset( bbpress()->includes_dir ) ) {
                     $bbp_topic_template_functions = bbpress()->includes_dir . 'topics/template.php';
                     if ( file_exists( $bbp_topic_template_functions ) ) {
                         require_once( $bbp_topic_template_functions );
                     }
                 }
            }
        }
    }
}
// Hook into 'template_redirect' which runs later, before the template file is included.
add_action( 'template_redirect', 'bp_ensure_bbpress_loaded_for_band_profile', 5 ); 

/**
 * Manually inject the hidden forum ID field for the new topic form
 * specifically when viewed on a single band profile page.
 */
function bp_inject_hidden_forum_id_for_band_profile( ) {
    // Only run on the frontend single band_profile page context
    if ( ! is_admin() && is_singular('band_profile') ) {
        // Get the band profile ID from the global query
        $band_profile_id = get_the_ID(); 
        if ( $band_profile_id ) {
            $forum_id = get_post_meta( $band_profile_id, '_band_forum_id', true );
            if ( ! empty( $forum_id ) ) {
                echo '<input type="hidden" name="bbp_forum_id" value="' . esc_attr( $forum_id ) . '">';
            }
        }
    }
}
// Hook just before the submit button wrapper inside the form.
add_action( 'bbp_theme_before_topic_form_submit_wrapper', 'bp_inject_hidden_forum_id_for_band_profile' ); 

/**
 * Filters the permalink for bbPress forums associated with band profiles.
 *
 * Redirects links for band-specific forums to the corresponding band profile page.
 *
 * @param string $link The original permalink.
 * @param WP_Post $post The post object.
 * @return string The potentially modified permalink.
 */
function bp_filter_band_forum_permalink( $link, $post ) {
    // Only proceed if this is a forum post type and bbPress functions are available
    if ( function_exists('bbp_get_forum_post_type') && $post->post_type === bbp_get_forum_post_type() ) {
        
        // Check if it's one of our band profile forums
        $is_band_forum = get_post_meta( $post->ID, '_is_band_profile_forum', true );

        if ( $is_band_forum ) {
            // Get the associated band profile ID
            $band_profile_id = get_post_meta( $post->ID, '_associated_band_profile_id', true );

            if ( ! empty( $band_profile_id ) ) {
                // Get the permalink for the band profile
                $band_profile_link = get_permalink( $band_profile_id );

                // If we successfully got a link, return it
                if ( $band_profile_link ) {
                    return $band_profile_link;
                }
            }
        }
    }

    // If it's not a band forum or we couldn't get the link, return the original
    return $link;
}
add_filter( 'post_type_link', 'bp_filter_band_forum_permalink', 20, 2 ); // Use priority 20 to potentially run after other filters 

/**
 * Increment the view count for a band profile.
 *
 * @param int $band_id The ID of the band_profile post.
 */
function bp_increment_band_profile_view_count( $band_id ) {
    if ( empty( $band_id ) || get_post_type( $band_id ) !== 'band_profile' ) {
        return;
    }

    // Don't count views for admins or the post author to avoid skewing counts during edits/management.
    if ( current_user_can( 'manage_options' ) || get_current_user_id() == get_post_field( 'post_author', $band_id ) ) {
        // Optionally, you could allow admins to see it increment for testing by commenting out this return.
        // return;
    }

    $count_key = '_band_profile_view_count';
    $count = (int) get_post_meta( $band_id, $count_key, true );
    $count++;
    update_post_meta( $band_id, $count_key, $count );
} 