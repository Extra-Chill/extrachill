<?php
/**
 * Artist Profile bbPress Forum Integration
 * 
 * Handles automatic creation and management of bbPress forums 
 * associated with Artist Profiles.
 */

/**
 * Creates bbPress forum when artist profile is published
 * 
 * Automatically creates and links a forum for new artist profiles.
 * Updates forum title if artist name changes.
 */
function bp_create_artist_forum_on_save( $post_id, $post, $update ) {

    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }

    if ( $post->post_status !== 'publish' ) {
        return;
    }

    $existing_forum_id = get_post_meta( $post_id, '_artist_forum_id', true );
    if ( ! empty( $existing_forum_id ) ) {
        $forum = get_post( $existing_forum_id );
        $new_title = sprintf( __( '%s Forum', 'extrachill-artist-platform' ), $post->post_title );
        if ( $forum && $forum->post_title !== $new_title ) {
            wp_update_post( array( 'ID' => $existing_forum_id, 'post_title' => $new_title ) );
        }
        return;
    }

    if ( ! function_exists( 'bbp_insert_forum' ) ) {
        error_log( '[Artist Forums] Cannot create forum for artist profile ID ' . $post_id . ': bbPress not active' );
        return;
    }

    $forum_data = array(
        'post_title'  => sprintf( __( '%s Forum', 'extrachill-artist-platform' ), $post->post_title ),
        'post_content'=> sprintf( __( 'Discussion forum for the artist %s.', 'extrachill-artist-platform' ), $post->post_title ),
        'post_status' => 'publish',
    );

    $forum_id = bbp_insert_forum( $forum_data );

    if ( is_wp_error( $forum_id ) ) {
        error_log( '[Artist Forums] Failed to create forum for artist profile ID ' . $post_id . ': ' . $forum_id->get_error_message() );
        return;
    }

    update_post_meta( $forum_id, '_is_artist_profile_forum', true );
    update_post_meta( $forum_id, '_associated_artist_profile_id', $post_id );
    update_post_meta( $post_id, '_artist_forum_id', $forum_id );
    update_post_meta( $forum_id, '_allow_public_topic_creation', '1' );

    error_log( '[Artist Forums] Successfully created forum ID ' . $forum_id . ' for artist profile ID ' . $post_id . ' (' . $post->post_title . ')' );
}

// Use WordPress native action hook for all artist profile saves
add_action( 'save_post_artist_profile', 'bp_create_artist_forum_on_save', 20, 3 );


/**
 * Handles deletion/trashing of associated forum
 * 
 * Matches forum status to artist profile status (delete/trash/restore).
 */
function bp_handle_artist_profile_deletion( $post_id ) {
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_artist_forum_id', true );

    if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        $is_artist_forum = get_post_meta( $forum_id, '_is_artist_profile_forum', true );

        if ( $forum && $is_artist_forum ) {
            if ( did_action( 'before_delete_post' ) > did_action( 'wp_trash_post' ) ) {
                wp_delete_post( $forum_id, true );
            } else {
                wp_trash_post( $forum_id );
            }
        }
    }
}
add_action( 'wp_trash_post', 'bp_handle_artist_profile_deletion' );
add_action( 'before_delete_post', 'bp_handle_artist_profile_deletion' );

/**
 * Handles restoration of associated forum when artist profile is untrashed
 */
function bp_handle_artist_profile_untrash( $post_id ) {
    if ( get_post_type( $post_id ) !== 'artist_profile' ) {
        return;
    }

    $forum_id = get_post_meta( $post_id, '_artist_forum_id', true );

    if ( ! empty( $forum_id ) ) {
        $forum = get_post( $forum_id );
        if ( $forum && get_post_status( $forum_id ) === 'trash' ) {
            $is_artist_forum = get_post_meta( $forum_id, '_is_artist_profile_forum', true );
            if ( $is_artist_forum ) {
                wp_untrash_post( $forum_id );
            }
        }
    }
}
add_action( 'untrash_post', 'bp_handle_artist_profile_untrash' );



/**
 * Ensures bbPress functions are loaded for artist profile templates
 * 
 * Loads bbPress template functions when CPT template loads before bbPress initializes.
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
 * Injects hidden forum ID field for topic forms on artist profile pages
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
 * Redirects artist forum permalinks to artist profile pages
 * 
 * Forums associated with artist profiles redirect to the profile page
 * instead of the standard forum URL.
 */
function bp_filter_artist_forum_permalink( $link, $post ) {
    // Only proceed if this is a forum post type and bbPress functions are available
    if ( function_exists('bbp_get_forum_post_type') && $post->post_type === bbp_get_forum_post_type() ) {
        
        // Check if it's one of our artist profile forums
        $is_artist_forum = get_post_meta( $post->ID, '_is_artist_profile_forum', true );

        if ( $is_artist_forum ) {
            // Get the associated artist profile ID
            $artist_profile_id = apply_filters('ec_get_artist_id', $post->ID);

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
 * Injects artist forum ID into global query vars
 * 
 * Enables bbPress functions like bbp_get_forum_id() to work correctly
 * within artist profile templates.
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
 * Cleanup user meta when artist profile is deleted
 * 
 * Removes deleted artist profile ID from all users' '_artist_profile_ids' meta.
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

/**
 * Automatically add artist profile creator to bidirectional relationship meta
 * Ensures creator has immediate access via both post_author and meta
 */
add_action('save_post_artist_profile', 'bp_sync_artist_creator_membership', 10, 3);

function bp_sync_artist_creator_membership($post_id, $post, $update) {
    // Only run on NEW artist profiles (not updates)
    if ($update) {
        return;
    }

    // Prevent infinite loops
    remove_action('save_post_artist_profile', 'bp_sync_artist_creator_membership', 10);

    // Get post author
    $author_id = (int) $post->post_author;
    if (!$author_id) {
        add_action('save_post_artist_profile', 'bp_sync_artist_creator_membership', 10, 3);
        return;
    }

    // Add creator as member (bidirectional relationship)
    if (function_exists('bp_add_artist_membership')) {
        bp_add_artist_membership($author_id, $post_id);
    }

    // Restore hook
    add_action('save_post_artist_profile', 'bp_sync_artist_creator_membership', 10, 3);
}

// Forum-to-artist profile redirect moved to artist-platform-rewrite-rules.php for consolidation