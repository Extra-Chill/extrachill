<?php
/**
 * Handles automatic creation and management of bbPress forums associated with Artist Profiles.
 */

/**
 * Creates a hidden bbPress forum when a 'artist_profile' CPT is published for the first time.
 *
 * Hooks into save_post_artist_profile.
 *
 * @param int     $post_id The ID of the post being saved.
 * @param WP_Post $post    The post object.
 * @param bool    $update  Whether this is an update to an existing post.
 */
function bp_create_artist_forum_on_save( $post_id, $post, $update ) {
    error_log('[DEBUG] bp_create_artist_forum_on_save: Function entered for post ID ' . $post_id);

    // Security checks: Nonce verification already happened in user-linking save, but good practice.
    // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        error_log('[DEBUG] bp_create_artist_forum_on_save: Incorrect post type ( ' . get_post_type( $post_id ) . ' ). Returning.');
        return;
    }

    // Check if it's an autosave or revision.
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        error_log('[DEBUG] bp_create_artist_forum_on_save: Is autosave or revision. Returning.');
        return;
    }

    // Check if the post status is 'publish' (or potentially other statuses if needed later).
    // We only want to create the forum when the profile is actually published.
    if ( $post->post_status !== 'publish' ) {
        error_log('[DEBUG] bp_create_artist_forum_on_save: Post status is not publish ( ' . $post->post_status . ' ). Returning.');
        return;
    }

    // Check if the forum already exists.
    $existing_forum_id = get_post_meta( $post_id, '_artist_forum_id', true );
    if ( ! empty( $existing_forum_id ) ) {
        error_log('[DEBUG] bp_create_artist_forum_on_save: Forum already exists (' . $existing_forum_id . '). Updating title if needed and returning.');
        // Forum already exists, maybe update its title if artist name changed?
        $forum = get_post( $existing_forum_id );
        $new_title = sprintf( __( '%s Forum', 'extrachill-artist-platform' ), $post->post_title );
        if ( $forum && $forum->post_title !== $new_title ) {
            wp_update_post( array( 'ID' => $existing_forum_id, 'post_title' => $new_title ) );
        }
        return; // Exit, forum exists.
    }

    error_log('[DEBUG] bp_create_artist_forum_on_save: Proceeding to create new forum for artist profile ' . $post_id);

    // --- Create the new forum --- 

    // Ensure bbPress functions are available.
    if ( ! function_exists( 'bbp_insert_forum' ) ) {
        error_log('[ERROR] bp_create_artist_forum_on_save: bbp_insert_forum function not found. bbPress may not be active. Returning.');
        // Log error or notify admin: bbPress not active?
        return;
    }

    $forum_data = array(
        'post_title'  => sprintf( __( '%s Forum', 'extrachill-artist-platform' ), $post->post_title ),
        'post_content'=> sprintf( __( 'Discussion forum for the artist %s.', 'extrachill-artist-platform' ), $post->post_title ),
        'post_status' => 'publish', // Create forum as public
        // 'post_parent' => 0, // Optional: Assign a parent forum if desired
    );

    // Create the forum post.
    $forum_id = bbp_insert_forum( $forum_data );

    if ( is_wp_error( $forum_id ) ) {
        error_log('[ERROR] bp_create_artist_forum_on_save: Failed to insert forum. WP_Error: ' . $forum_id->get_error_message());
        // Log error
        return;
    }

    error_log('[DEBUG] bp_create_artist_forum_on_save: Successfully created forum with ID ' . $forum_id . '. Proceeding to link meta.');

    // Add meta to the new forum to identify it and link back.
    update_post_meta( $forum_id, '_is_artist_profile_forum', true );
    update_post_meta( $forum_id, '_associated_artist_profile_id', $post_id );

    // Store the new forum ID in the artist profile CPT meta.
    $meta_update_result = update_post_meta( $post_id, '_artist_forum_id', $forum_id );
    error_log('[DEBUG] bp_create_artist_forum_on_save: Updated artist profile ( ' . $post_id . ' ) meta _artist_forum_id with value ' . $forum_id . '. Result: ' . ($meta_update_result ? 'Success' : 'Failure'));

    // Default to allowing public topic creation for new artist forums.
    update_post_meta( $forum_id, '_allow_public_topic_creation', '1' );

    // Set the default forum section for artist forums to 'none' to hide them from main lists.
    update_post_meta( $forum_id, '_bbp_forum_section', 'none' );

    // Optional: Set initial forum settings (e.g., allow topic creation?)
    // update_post_meta( $forum_id, '_bbp_allow_topic_creaton', true ); // Example

    error_log('[DEBUG] bp_create_artist_forum_on_save: Forum creation and linking process completed for artist profile ' . $post_id);

}
// Use a priority lower than the member saving function if order matters, but 10 is usually fine.
// Use 3 arguments for $update check.
// Increase priority to 20 to potentially run after bbPress initialization
add_action( 'save_post_artist_profile', 'bp_create_artist_forum_on_save', 20, 3 );


/**
 * Handles deletion/trashing of the associated forum when a artist profile is deleted or trashed.
 *
 * @param int $post_id The ID of the post being deleted/trashed.
 */
function bp_handle_artist_profile_deletion( $post_id ) {
     // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_artist_forum_id', true );

    if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        // Ensure it's actually one of our artist forums before deleting.
        $is_artist_forum = get_post_meta( $forum_id, '_is_artist_profile_forum', true );

        if ( $forum && $is_artist_forum ) {
            // Determine if the artist profile is being permanently deleted or just trashed.
            if ( did_action( 'before_delete_post' ) > did_action( 'wp_trash_post' ) ) {
                // Permanently deleting the artist profile - permanently delete the forum.
                wp_delete_post( $forum_id, true ); // true = force delete
            } else {
                // Trashing the artist profile - trash the forum.
                wp_trash_post( $forum_id );
            }
        }
    }
}
add_action( 'wp_trash_post', 'bp_handle_artist_profile_deletion' );
add_action( 'before_delete_post', 'bp_handle_artist_profile_deletion' );

/**
 * Handles restoration of the associated forum when a artist profile is untrashed.
 *
 * @param int $post_id The ID of the post being restored.
 */
function bp_handle_artist_profile_untrash( $post_id ) {
     // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_artist_forum_id', true );

     if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        // Check if the forum is in the trash.
         if ( $forum && get_post_status( $forum_id ) === 'trash' ) {
            // Ensure it's actually one of our artist forums before untrashing.
             $is_artist_forum = get_post_meta( $forum_id, '_is_artist_profile_forum', true );
             if ( $is_artist_forum ) {
                wp_untrash_post( $forum_id );
                 // Optional: bbPress might automatically set status back, but confirm if it needs hiding again.
                 // bbp_hide_forum( $forum_id ); -> REMOVED - Don't re-hide on untrash
            }
        }
    }
}
add_action( 'untrash_post', 'bp_handle_artist_profile_untrash' );

/**
 * Hides artist profile forums from the main forum list table in WP Admin.
 *
 * @param WP_Query $query The WP_Query instance (passed by reference).
 */
function bp_hide_artist_forums_from_admin_list( $query ) {
    // Check if we are in the admin area, on the main query for the 'edit-forum' screen.
    if ( is_admin() && $query->is_main_query() && function_exists('bbp_get_forum_post_type') ) {
        
        // If a specific query parameter is set to show artist forums, bail out early.
        if ( isset( $_GET['show_artist_forums'] ) && $_GET['show_artist_forums'] === '1' ) {
            return;
        }

        // Get current screen information
        $screen = get_current_screen();

        // Check if it's the forum list table screen and the post type is forum
        if ( isset($screen->id) && $screen->id === 'edit-forum' && $query->get('post_type') === bbp_get_forum_post_type() ) {
            
            // Get existing meta query
            $meta_query = $query->get( 'meta_query' );
            if ( ! is_array( $meta_query ) ) {
                $meta_query = array();
            }

            // Add our condition to exclude artist forums
            // 'relation' => 'AND' is the default
            $meta_query[] = array(
                'key'     => '_is_artist_profile_forum',
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
add_action( 'pre_get_posts', 'bp_hide_artist_forums_from_admin_list' ); 

/**
 * Adds a toggle link to the "All Forums" admin page to show/hide artist-specific forums.
 */
function bp_add_toggle_artist_forums_link_admin( $post_type ) {
    // Only add to the 'edit-forum' screen and for the forum post type
    if ( isset($_GET['post_type']) && $_GET['post_type'] === 'forum' ) {
        $show_artist_forums = isset( $_GET['show_artist_forums'] ) && $_GET['show_artist_forums'] === '1';

        if ( $show_artist_forums ) {
            $url = remove_query_arg( 'show_artist_forums' );
            $link_text = __( 'Hide Artist Forums', 'extrachill-artist-platform' );
        } else {
            $url = add_query_arg( 'show_artist_forums', '1' );
            $link_text = __( 'Show Artist Forums', 'extrachill-artist-platform' );
        }

        echo '<div class="alignleft actions extrachill-forum-toggle">';
        echo '<a href="' . esc_url( $url ) . '" class="button">' . esc_html( $link_text ) . '</a>';
        echo '</div>';
    }
}

add_action( 'restrict_manage_posts', 'bp_add_toggle_artist_forums_link_admin', 20, 1 );

/**
 * Ensures bbPress core template functions are loaded for single artist profile views.
 *
 * Addresses scenarios where the CPT template loads before bbPress initializes fully.
 */
function bp_ensure_bbpress_loaded_for_artist_profile() {
    // Only run on frontend single artist_profile views
    if ( ! is_admin() && is_singular('artist_profile') ) {
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
add_action( 'template_redirect', 'bp_ensure_bbpress_loaded_for_artist_profile', 5 ); 

/**
 * Manually inject the hidden forum ID field for the new topic form
 * specifically when viewed on a single artist profile page.
 */
function bp_inject_hidden_forum_id_for_artist_profile( ) {
    // Only run on the frontend single artist_profile page context
    if ( ! is_admin() && is_singular('artist_profile') ) {
        // Get the artist profile ID from the global query
        $artist_profile_id = get_the_ID(); 
        if ( $artist_profile_id ) {
            $forum_id = get_post_meta( $artist_profile_id, '_artist_forum_id', true );
            if ( ! empty( $forum_id ) ) {
                echo '<input type="hidden" name="bbp_forum_id" value="' . esc_attr( $forum_id ) . '">';
            }
        }
    }
}
// Hook just before the submit button wrapper inside the form.
add_action( 'bbp_theme_before_topic_form_submit_wrapper', 'bp_inject_hidden_forum_id_for_artist_profile' ); 

/**
 * Filters the permalink for bbPress forums associated with artist profiles.
 *
 * Redirects links for artist-specific forums to the corresponding artist profile page.
 *
 * @param string $link The original permalink.
 * @param WP_Post $post The post object.
 * @return string The potentially modified permalink.
 */
function bp_filter_artist_forum_permalink( $link, $post ) {
    // Only proceed if this is a forum post type and bbPress functions are available
    if ( function_exists('bbp_get_forum_post_type') && $post->post_type === bbp_get_forum_post_type() ) {
        
        // Check if it's one of our artist profile forums
        $is_artist_forum = get_post_meta( $post->ID, '_is_artist_profile_forum', true );

        if ( $is_artist_forum ) {
            // Get the associated artist profile ID
            $artist_profile_id = get_post_meta( $post->ID, '_associated_artist_profile_id', true );

            if ( ! empty( $artist_profile_id ) ) {
                // Get the permalink for the artist profile
                $artist_profile_link = get_permalink( $artist_profile_id );

                // If we successfully got a link, return it
                if ( $artist_profile_link ) {
                    return $artist_profile_link;
                }
            }
        }
    }

    // If it's not an artist forum or we couldn't get the link, return the original
    return $link;
}
add_filter( 'post_type_link', 'bp_filter_artist_forum_permalink', 20, 2 ); // Use priority 20 to potentially run after other filters 

/**
 * Increment the view count for a artist profile.
 *
 * @param int $artist_id The ID of the artist_profile post.
 */
function bp_increment_artist_profile_view_count( $artist_id ) {
    if ( empty( $artist_id ) || get_post_type( $artist_id ) !== 'artist_profile' ) {
        return;
    }

    // Don't count views for admins or the post author to avoid skewing counts during edits/management.
    if ( current_user_can( 'manage_options' ) || get_current_user_id() == get_post_field( 'post_author', $artist_id ) ) {
        // Optionally, you could allow admins to see it increment for testing by commenting out this return.
        // return;
    }

    $count_key = '_artist_profile_view_count';
    $count = (int) get_post_meta( $artist_id, $count_key, true );
    $count++;
    update_post_meta( $artist_id, $count_key, $count );
} 

/**
 * Injects the associated artist forum ID into the global query vars when viewing a single artist profile.
 * This helps bbPress functions like bbp_get_forum_id() work correctly in the context of the artist profile template.
 */
function bp_inject_artist_forum_id_into_global_query_vars() {
    // Only run on frontend single artist_profile views
    if ( ! is_admin() && is_singular('artist_profile') ) {
        // Get the artist profile ID from the global query
        $artist_profile_id = get_the_ID(); 
        if ( $artist_profile_id ) {
            $forum_id = get_post_meta( $artist_profile_id, '_artist_forum_id', true );
            if ( ! empty( $forum_id ) ) {
                global $wp_query;
                $wp_query->set( 'bbp_forum_id', $forum_id );
            }
        }
    }
}
// Hook into 'template_redirect' which runs later, before the template file is included.
add_action( 'template_redirect', 'bp_inject_artist_forum_id_into_global_query_vars', 5 ); 

/**
 * Removes the deleted artist profile ID from the _artist_profile_ids user meta for all users.
 *
 * Hooks into before_delete_post.
 *
 * @param int $post_id The ID of the post being deleted.
 */
function bp_cleanup_user_meta_on_artist_profile_deletion( $post_id ) {
    // Check if it's the correct post type.
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    // Get all users. For a large number of users, consider a more efficient method if performance becomes an issue.
    $all_users = get_users( array( 'fields' => array( 'ID' ) ) );

    if ( $all_users ) {
        foreach ( $all_users as $user ) {
            $user_artist_ids = get_user_meta( $user->ID, '_artist_profile_ids', true );

            // Ensure the meta exists and is an array before trying to modify
            if ( ! empty( $user_artist_ids ) && is_array( $user_artist_ids ) ) {
                // Find the index of the deleted artist ID in the array
                $key = array_search( $post_id, $user_artist_ids );

                // If the artist ID is found, remove it
                if ( $key !== false ) {
                    unset( $user_artist_ids[ $key ] );

                    // Re-index the array if necessary (optional, but good practice)
                    $user_artist_ids = array_values( $user_artist_ids );

                    // Update the user meta. If the array is now empty, delete the meta key.
                    if ( ! empty( $user_artist_ids ) ) {
                        update_user_meta( $user->ID, '_artist_profile_ids', $user_artist_ids );
                    } else {
                        delete_user_meta( $user->ID, '_artist_profile_ids' );
                    }
                }
            }
        }
    }
}
add_action( 'before_delete_post', 'bp_cleanup_user_meta_on_artist_profile_deletion' ); // Hook into before_delete_post