<?php
/**
 * Handles linking User accounts to Band Profile CPTs.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Function to register the meta box
function bp_add_band_members_meta_box() {
    add_meta_box(
        'bp_band_members',                    // Unique ID
        __( 'Band Members', 'extra-chill-community' ), // Box title
        'bp_render_band_members_meta_box',  // Content callback function
        'band_profile',                   // Post type
        'side',                         // Context (normal, side, advanced)
        'high'                          // Priority (high, core, default, low)
    );
}
add_action( 'add_meta_boxes', 'bp_add_band_members_meta_box' );

// Function to render the meta box content
function bp_render_band_members_meta_box( $post ) {
    // Nonce field for security
    wp_nonce_field( 'bp_save_band_members_meta', 'bp_band_members_nonce' );

    // Get current post ID (Band Profile ID)
    $band_profile_id = $post->ID;

    // --- Display Linked Members --- 
    echo '<h4>' . __( 'Current Members:', 'extra-chill-community' ) . '</h4>';
    echo '<div id="bp-current-members-list">';

    $linked_members = bp_get_linked_members( $band_profile_id );

    if ( ! empty( $linked_members ) ) {
        echo '<ul>';
        foreach ( $linked_members as $member ) {
            $user_info = get_userdata( $member->ID );
            if ( $user_info ) {
                echo '<li data-user-id="' . esc_attr( $user_info->ID ) . '">';
                echo esc_html( $user_info->display_name ) . ' (' . esc_html( $user_info->user_login ) . ')';
                // The remove link/button needs JS to function properly by populating bp_members_to_remove
                echo ' <a href="#" class="bp-remove-member-link" style="color: red; text-decoration: none;" title="' . esc_attr__( 'Remove this member', 'extra-chill-community' ) . '">[x]</a>';
                echo '</li>';
            }
        }
        echo '</ul>';
    } else {
        echo '<p>' . __( 'No members linked yet.', 'extra-chill-community' ) . '</p>';
    }
    echo '</div>';

    // --- Add New Member Section --- 
    // Add nonce field for the AJAX search functionality
    wp_nonce_field( 'bp_member_search_nonce', 'bp_member_search_security', false ); // Add nonce field directly here for JS access

    echo '<h4>' . __( 'Add Member:', 'extra-chill-community' ) . '</h4>';
    echo '<p>';
    echo '<label for="bp-search-user">' . __( 'Search User:', 'extra-chill-community' ) . '</label>';
    // We'll need an input field and potentially AJAX for user search
    echo '<input type="text" id="bp-search-user" name="bp_search_user" placeholder="' . __( 'Username or Email', 'extra-chill-community' ) . '" style="width: 100%;">';
    echo '<div id="bp-user-search-results"></div>';
    echo '<input type="hidden" name="bp_add_user_id" id="bp-add-user-id">';
    echo '<button type="button" id="bp-add-member-button" class="button">' . __( 'Add Selected Member', 'extra-chill-community' ) . '</button>';
    echo '</p>';

    // Hidden fields populated by JS for saving
    echo '<input type="hidden" name="bp_members_to_add" id="bp-members-to-add" value="">';
    echo '<input type="hidden" name="bp_members_to_remove" id="bp-members-to-remove" value="">';

}

// Function to save the meta box data
function bp_save_band_members_meta( $post_id ) {
    if ( ! isset( $_POST['bp_band_members_nonce'] ) || ! wp_verify_nonce( $_POST['bp_band_members_nonce'], 'bp_save_band_members_meta' ) ) {
        return;
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return;
    }
    if ( wp_is_post_autosave( $post_id ) ) {
        return;
    }
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    // Get current band members before changes
    $old_member_ids = get_post_meta( $post_id, '_band_member_ids', true );
    if ( ! is_array( $old_member_ids ) ) {
        $old_member_ids = array();
    }
    // Sanitize and process users to add
    $users_to_add_str = isset( $_POST['bp_members_to_add'] ) ? sanitize_text_field( $_POST['bp_members_to_add'] ) : '';
    $user_ids_to_add = ! empty( $users_to_add_str ) ? array_map( 'intval', explode( ',', $users_to_add_str ) ) : array();
    foreach ( $user_ids_to_add as $user_id ) {
        if ( $user_id > 0 ) {
            bp_add_band_membership( $user_id, $post_id );
        }
    }
    // Sanitize and process users to remove
    $users_to_remove_str = isset( $_POST['bp_members_to_remove'] ) ? sanitize_text_field( $_POST['bp_members_to_remove'] ) : '';
    $user_ids_to_remove = ! empty( $users_to_remove_str ) ? array_map( 'intval', explode( ',', $users_to_remove_str ) ) : array();
    foreach ( $user_ids_to_remove as $user_id ) {
        if ( $user_id > 0 ) {
            bp_remove_band_membership( $user_id, $post_id );
        }
    }
    // Fetch updated member list after all changes
    $new_member_ids = get_post_meta( $post_id, '_band_member_ids', true );
    if ( ! is_array( $new_member_ids ) ) {
        $new_member_ids = array();
    }
    // Detect truly new members
    $actually_new_user_ids = array_diff( $new_member_ids, $old_member_ids );
    if ( ! empty( $actually_new_user_ids ) ) {
        foreach ( $actually_new_user_ids as $user_id ) {
            if ( function_exists( 'bp_send_admin_band_membership_notification' ) ) {
                bp_send_admin_band_membership_notification( $user_id, $post_id );
            }
        }
    }
}
add_action( 'save_post_band_profile', 'bp_save_band_members_meta' );


// --- Helper Functions for Modifying User Meta ---

/**
 * Adds a band profile ID to a user's list of memberships.
 * Also updates the band_profile's _band_member_ids post meta.
 *
 * @param int $user_id   The ID of the user.
 * @param int $band_id   The ID of the band_profile post.
 * @return bool True on success, false on failure or if already a member.
 */
function bp_add_band_membership( $user_id, $band_id ) {
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id || get_post_type( $band_id ) !== 'band_profile' ) {
        return false; // Invalid input
    }

    // Step 1: Update User Meta (_band_profile_ids on user)
    $current_band_ids_on_user = get_user_meta( $user_id, '_band_profile_ids', true );
    if ( ! is_array( $current_band_ids_on_user ) ) {
        $current_band_ids_on_user = [];
    }

    $already_member_on_user_meta = in_array( $band_id, $current_band_ids_on_user );

    if ( ! $already_member_on_user_meta ) {
        $current_band_ids_on_user[] = $band_id;
        $current_band_ids_on_user = array_unique( $current_band_ids_on_user );
        if ( ! update_user_meta( $user_id, '_band_profile_ids', $current_band_ids_on_user ) ) {
            // error_log("[Band Platform] Failed to update _band_profile_ids user meta for user $user_id, band $band_id");
            return false; 
        }
    }

    // Step 2: Update Band Profile Post Meta (_band_member_ids on band_profile post)
    $current_member_ids_on_band = get_post_meta( $band_id, '_band_member_ids', true );
    if ( ! is_array( $current_member_ids_on_band ) ) {
        $current_member_ids_on_band = [];
    }

    if ( ! in_array( $user_id, $current_member_ids_on_band ) ) {
        $current_member_ids_on_band[] = $user_id;
        $current_member_ids_on_band = array_unique( array_map( 'absint', $current_member_ids_on_band ) );
        // Filter out any 0 values that might result from absint if non-numeric was present before unique
        $current_member_ids_on_band = array_filter( $current_member_ids_on_band, function($id) { return $id > 0; } );
        if ( ! update_post_meta( $band_id, '_band_member_ids', $current_member_ids_on_band ) ) {
            // error_log("[Band Platform] Failed to update _band_member_ids post meta for band $band_id after adding user $user_id");
            // Optionally, consider if we should revert the user meta update here, though it's tricky.
            // For now, we'll report the error and proceed, as user meta is correct.
        }
    }

    return true; // Success
}

/**
 * Removes a band profile ID from a user's list of memberships.
 * Also removes the user ID from the band_profile's _band_member_ids post meta.
 *
 * @param int $user_id   The ID of the user.
 * @param int $band_id   The ID of the band_profile post.
 * @return bool True on success, false on failure.
 */
function bp_remove_band_membership( $user_id, $band_id ) {
    $user_id = absint( $user_id );
    $band_id = absint( $band_id );

    if ( ! $user_id || ! $band_id ) {
        return false; // Invalid input
    }

    // Step 1: Update User Meta (_band_profile_ids on user)
    $current_band_ids_on_user = get_user_meta( $user_id, '_band_profile_ids', true );
    $user_meta_updated_successfully = true; // Assume success unless update fails or not needed

    if ( is_array( $current_band_ids_on_user ) && ! empty( $current_band_ids_on_user ) ) {
        $key_on_user_meta = array_search( $band_id, $current_band_ids_on_user );
        if ( $key_on_user_meta !== false ) {
            unset( $current_band_ids_on_user[$key_on_user_meta] );
            // Re-index to ensure clean array for user meta
            $current_band_ids_on_user = array_values($current_band_ids_on_user);
            if ( ! update_user_meta( $user_id, '_band_profile_ids', $current_band_ids_on_user ) ) {
                // error_log("[Band Platform] Failed to update _band_profile_ids user meta for user $user_id, band $band_id during removal");
                $user_meta_updated_successfully = false; 
                // Do not return false yet, still attempt to clean up band post meta if possible
            }
        }
    }
    
    // If user meta update failed, we might reconsider proceeding, but for data integrity on the band post, let's try.
    // if (!$user_meta_updated_successfully) return false; // Stricter approach

    // Step 2: Update Band Profile Post Meta (_band_member_ids on band_profile post)
    $current_member_ids_on_band = get_post_meta( $band_id, '_band_member_ids', true );
    if ( ! is_array( $current_member_ids_on_band ) ) {
        // If it's not an array, there's nothing to remove this user from. 
        // This could happen if it was never initialized or somehow corrupted.
        $current_member_ids_on_band = [];
    }

    $key_on_band_meta = array_search( $user_id, $current_member_ids_on_band );
    if ( $key_on_band_meta !== false ) {
        unset( $current_member_ids_on_band[$key_on_band_meta] );
        $current_member_ids_on_band = array_values( array_unique( array_map( 'absint', $current_member_ids_on_band ) ) );
        // Filter out any 0 values
        $current_member_ids_on_band = array_filter( $current_member_ids_on_band, function($id) { return $id > 0; } );
        if ( ! update_post_meta( $band_id, '_band_member_ids', $current_member_ids_on_band ) ){
            // error_log("[Band Platform] Failed to update _band_member_ids post meta for band $band_id after removing user $user_id");
            // If user meta was successfully updated but this failed, we have a partial inconsistency.
            // For now, return based on user_meta_updated_successfully as primary success criteria for this function call.
            return $user_meta_updated_successfully ? false : false; // essentially false if this step fails
        }
    }
    
    return $user_meta_updated_successfully; 
}

/**
 * Gets users linked to a specific band profile.
 *
 * @param int $band_profile_id The ID of the band profile CPT.
 * @return array Array of WP_User objects.
 */
function bp_get_linked_members( $band_profile_id ) {
    if ( ! $band_profile_id ) {
        return array();
    }

    // The value part of a serialized array for an integer is i:VALUE;
    // For a string containing that integer it would be s:STRLEN:"VALUE";
    // We need to be careful with LIKE as it can match parts of other numbers.
    // A more specific LIKE would be 'i:BAND_ID;' or 's:len:"BAND_ID";'

    $serialized_int_fragment = sprintf( 'i:%d;', $band_profile_id );
    $string_id = (string) $band_profile_id;
    $serialized_str_fragment = sprintf( 's:%d:"%s";', strlen( $string_id ), $string_id );

    $args = array(
        'meta_query' => array(
            'relation' => 'OR',
            array(
                'key'     => '_band_profile_ids',
                'value'   => $serialized_int_fragment, 
                'compare' => 'LIKE'
            ),
            array(
                'key'     => '_band_profile_ids',
                'value'   => $serialized_str_fragment,
                'compare' => 'LIKE'
            )
        ),
        'fields' => 'all', // Get WP_User objects
    );
    $user_query = new WP_User_Query( $args );
    
    // --- DEBUGGING for bp_get_linked_members ---
    // error_log('[bp_get_linked_members] Querying for band ID: ' . $band_profile_id);
    // error_log('[bp_get_linked_members] SQL Query: ' . $user_query->request);
    // error_log('[bp_get_linked_members] Results count: ' . count($user_query->get_results()));
    // --- END DEBUGGING ---
    
    return $user_query->get_results();
}

// --- AJAX Handler for Artist Search ---

/**
 * Handles AJAX request to search for artists (users with 'user_is_artist' meta) or admins.
 * Excludes users already linked to the specified band profile.
 */
function bp_ajax_search_artists() {
    // Check nonce for security
    check_ajax_referer( 'bp_member_search_nonce', 'security' );

    // Get search term and band profile ID from request
    $search_term = isset( $_POST['search_term'] ) ? sanitize_text_field( $_POST['search_term'] ) : '';
    $band_profile_id = isset( $_POST['band_profile_id'] ) ? absint( $_POST['band_profile_id'] ) : 0;

    if ( empty( $search_term ) ) {
        wp_send_json_error( __( 'Search term cannot be empty.', 'extra-chill-community' ) );
    }
     if ( ! $band_profile_id ) {
        wp_send_json_error( __( 'Band Profile ID not provided.', 'extra-chill-community' ) );
    }

    // Get IDs of already linked members to exclude them
    $linked_member_objects = bp_get_linked_members( $band_profile_id );
    $linked_member_ids = wp_list_pluck( $linked_member_objects, 'ID' );

    $user_query_args = array(
        'search'         => '*' . esc_attr( $search_term ) . '*',
        'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
        'fields'         => array( 'ID', 'display_name', 'user_email', 'user_login' ),
        'meta_query'     => array(
            'relation' => 'OR',
            array(
                'key'     => 'user_is_artist',
                'value'   => '1',
                'compare' => '=',
            ),
            array(
                'key'     => 'user_is_professional',
                'value'   => '1',
                'compare' => '=',
            ),
        ),
        'exclude'        => $linked_member_ids, // Exclude already linked members
    );

    $user_query = new WP_User_Query( $user_query_args );

    // --- DEBUGGING for bp_ajax_search_artists ---
    // error_log('[bp_ajax_search_artists] Search Term: ' . $search_term);
    // error_log('[bp_ajax_search_artists] Band Profile ID: ' . $band_profile_id);
    // error_log('[bp_ajax_search_artists] Linked Member IDs to Exclude: ' . implode(',', $linked_member_ids));
    // error_log('[bp_ajax_search_artists] User Query Args: ' . print_r($user_query_args, true));
    // Accessing the SQL request before get_results might not be accurate for all cases, 
    // but can give insight. get_results runs the query.
    $results = $user_query->get_results();
    // error_log('[bp_ajax_search_artists] Raw Results Count: ' . count($results));
    // error_log('[bp_ajax_search_artists] Raw Results (first 5): ' . print_r(array_slice($results, 0, 5), true));
    // --- END DEBUGGING ---

    $results = $user_query->get_results();

    // Remove duplicates if an admin is also an artist
    $unique_results = array();
    $found_ids = array();
    foreach ( $results as $user ) {
        if ( ! in_array( $user->ID, $found_ids ) ) {
            $unique_results[] = $user;
            $found_ids[] = $user->ID;
        }
    }

    // Limit the combined results again if necessary (optional)
     $unique_results = array_slice($unique_results, 0, 10);

    if ( ! empty( $unique_results ) ) {
        wp_send_json_success( $unique_results );
    } else {
        wp_send_json_error( __( 'No matching artists found.', 'extra-chill-community' ) );
    }

    wp_die(); // this is required to terminate immediately and return a proper response
}
add_action( 'wp_ajax_bp_search_artists', 'bp_ajax_search_artists' ); // Hook for logged-in users

// We also need a nonce for the AJAX search
function bp_add_member_search_nonce() {
     if (get_current_screen()->id === 'band_profile') { // Only add nonce on the band profile edit screen
         wp_nonce_field( 'bp_member_search_nonce', 'bp_member_search_security' );
     }
 }
 // Add nonce field somewhere accessible by JS, maybe near the search box or using wp_localize_script later.
 // For simplicity now, let's add it directly in the meta box render function, but it should be done properly with script localization.

 // Let's modify the render function to include the nonce directly for now:
 // Need to re-apply edit to bp_render_band_members_meta_box to add the nonce field there.


// --- User List Admin Column --- 

/**
 * Adds the 'Band Memberships' column to the users list table.
 *
 * @param array $columns Existing columns.
 * @return array Modified columns.
 */
function bp_add_user_band_memberships_column( $columns ) {
    $columns['band_memberships'] = __( 'Band Memberships', 'extra-chill-community' );
    return $columns;
}
add_filter( 'manage_users_columns', 'bp_add_user_band_memberships_column' );

/**
 * Displays the content for the custom 'Band Memberships' column.
 *
 * @param string $value       Custom column output. Default empty.
 * @param string $column_name Column name.
 * @param int    $user_id     ID of the currently-listed user.
 * @return string Column output.
 */
function bp_display_user_band_memberships_column( $value, $column_name, $user_id ) {
    if ( 'band_memberships' === $column_name ) {
        $band_ids = get_user_meta( $user_id, '_band_profile_ids', true );

        if ( ! empty( $band_ids ) && is_array( $band_ids ) ) {
            $band_links = array();
            foreach ( $band_ids as $band_id ) {
                $band_post = get_post( $band_id );
                if ( $band_post && $band_post->post_type === 'band_profile' ) {
                    // Link to the band profile edit screen
                    $edit_link = get_edit_post_link( $band_id );
                    if ( $edit_link ) {
                         $band_links[] = '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $band_post->post_title ) . '</a>';
                    } else {
                        $band_links[] = esc_html( $band_post->post_title ); // Fallback if no edit link
                    }
                }
            }
            if ( ! empty( $band_links ) ) {
                return implode( '<br>', $band_links );
            }
        } else {
            return '—'; // Display dash if no memberships
        }
    }

    return $value; // Return original value for other columns
}
add_action( 'manage_users_custom_column', 'bp_display_user_band_memberships_column', 10, 3 );

// --- End User List Admin Column --- 

// --- User Profile Edit Screen Management --- 

/**
 * Displays the band membership management fields on user profile edit screens.
 *
 * @param WP_User $user The user object being edited.
 */
function bp_show_band_membership_fields( $user ) {
    // Only show this section for users with capability to edit others (e.g., Admins)
    // or maybe specific roles. Prevents regular users seeing it on their own profile.
    // For simplicity, let's restrict to users who can edit other users.
    if ( ! current_user_can( 'edit_users' ) ) {
        return;
    }

    echo '<h2>' . __( 'Band Memberships', 'extra-chill-community' ) . '</h2>';
    echo '<table class="form-table"><tbody>';
    echo '<tr>';
    echo '<th scope="row">' . __( 'Member Of', 'extra-chill-community' ) . '</th>';
    echo '<td>';

    // Add nonce for security
    wp_nonce_field( 'bp_save_user_band_memberships', 'bp_user_band_nonce' );

    // Get all published band profiles
    $all_bands = get_posts( array(
        'post_type' => 'band_profile',
        'post_status' => 'publish',
        'numberposts' => -1, // Get all
        'orderby' => 'title',
        'order' => 'ASC'
    ) );

    // Get current memberships for this user
    $current_band_ids = get_user_meta( $user->ID, '_band_profile_ids', true );
    if ( ! is_array( $current_band_ids ) ) {
        $current_band_ids = array();
    }

    if ( ! empty( $all_bands ) ) {
        echo '<fieldset style="max-height: 200px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px;">';
        echo '<legend class="screen-reader-text"><span>' . __( 'Select bands', 'extra-chill-community' ) . '</span></legend>';
        foreach ( $all_bands as $band ) {
            $is_member = in_array( $band->ID, $current_band_ids );
            echo '<label for="band_member_' . esc_attr( $band->ID ) . '">';
            echo '<input type="checkbox" id="band_member_' . esc_attr( $band->ID ) . '" name="bp_band_memberships[]" value="' . esc_attr( $band->ID ) . '" ' . checked( $is_member, true, false ) . ' /> ';
            echo esc_html( $band->post_title );
            echo '</label><br />';
        }
         echo '</fieldset>';
         echo '<p class="description">'.__( 'Select the bands this user is a member of.', 'extra-chill-community' ).'</p>';
    } else {
        echo '<p>' . __( 'No band profiles found.', 'extra-chill-community' ) . '</p>';
    }

    echo '</td>';
    echo '</tr>';
    echo '</tbody></table>';
}
// Add fields to user edit screen (for admins editing others)
add_action( 'edit_user_profile', 'bp_show_band_membership_fields' );
// Optionally add to own profile screen (if needed, maybe read-only or restricted)
// add_action( 'show_user_profile', 'bp_show_band_membership_fields' ); 

/**
 * Saves the band membership data from the user profile edit screen.
 *
 * @param int $user_id The ID of the user being updated.
 */
function bp_save_user_band_memberships( $user_id ) {
    if ( ! current_user_can( 'edit_user', $user_id ) ) {
        return;
    }
    if ( ! isset( $_POST['bp_user_band_nonce'] ) || ! wp_verify_nonce( $_POST['bp_user_band_nonce'], 'bp_save_user_band_memberships' ) ) {
        return;
    }
    $submitted_band_ids = isset( $_POST['bp_band_memberships'] ) ? array_map( 'intval', (array) $_POST['bp_band_memberships'] ) : array();
    $current_band_ids = get_user_meta( $user_id, '_band_profile_ids', true );
    if ( ! is_array( $current_band_ids ) ) {
        $current_band_ids = array();
    }
    // Detect newly added bands
    $new_band_ids = array_diff( $submitted_band_ids, $current_band_ids );
    update_user_meta( $user_id, '_band_profile_ids', $submitted_band_ids );
    // Send admin notification for each new band
    if ( ! empty( $new_band_ids ) ) {
        foreach ( $new_band_ids as $band_id ) {
            if ( function_exists( 'bp_send_admin_band_membership_notification' ) ) {
                bp_send_admin_band_membership_notification( $user_id, $band_id );
            }
        }
    }
}
add_action( 'personal_options_update', 'bp_save_user_band_memberships' ); // Saving own profile (if shown)
add_action( 'edit_user_profile_update', 'bp_save_user_band_memberships' ); // Saving others' profiles

// --- End User Profile Edit Screen Management ---

