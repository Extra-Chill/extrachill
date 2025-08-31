<?php
/**
 * Admin Dashboard Widget: Manage Default Band Profile & Link Page
 *
 * Adds a widget for admins to create the default band profile and/or its link page if missing.
 */

add_action('wp_dashboard_setup', function() {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget(
        'extrch_default_artist_profile_widget',
        __('Extra Chill: Default Band Profile/Link Page', 'extrachill-artist-platform'),
        'extrch_render_default_artist_profile_widget'
    );
});

/**
 * Retrieves or creates the default site artist profile and its associated link page.
 * The default artist profile slug is 'extra-chill'.
 *
 * @param bool $create_if_missing Whether to create the items if they don't exist. Defaults to true.
 * @return array|null An array containing 'artist_id' and 'link_page_id', or null if not found and not creating.
 */
function extrch_get_or_create_default_admin_link_page( $create_if_missing = true ) {
    $default_artist_slug = 'extra-chill'; // Ensure this matches your actual default artist profile slug
    $default_artist_profile = get_page_by_path( $default_artist_slug, OBJECT, 'artist_profile' );
    $artist_id = null;
    $link_page_id = null;

    if ( ! $default_artist_profile ) {
        if ( ! $create_if_missing ) {
            return null; // Don't create, just report as not found
        }
        // Create the default artist profile if it doesn't exist AND $create_if_missing is true.
        $new_artist_id = wp_insert_post( array(
            'post_type'   => 'artist_profile',
            'post_title'  => 'Extra Chill',
            'post_name'   => $default_artist_slug,
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $new_artist_id ) || ! $new_artist_id ) {
            return null;
        }
        $artist_id = $new_artist_id;
        $admin_user_id = get_current_user_id();
        if ( $admin_user_id && function_exists('bp_add_artist_membership') ) {
            bp_add_artist_membership( $admin_user_id, $artist_id );
        }
    } else {
        $artist_id = $default_artist_profile->ID;
    }

    // Now check for or create the link page for this artist_id.
    $link_page_id = apply_filters('ec_get_link_page_id', $artist_id);
    $link_page_post_exists = $link_page_id ? (get_post_status($link_page_id) && get_post_type($link_page_id) === 'artist_link_page') : false;

    if ( ! $link_page_post_exists ) {
        if ( ! $create_if_missing ) {
            // If we are not creating, and it doesn't exist (or meta points to invalid post), return null for link_page_id
            return array('artist_id' => $artist_id, 'link_page_id' => null);
        }

        $artist_profile_post_obj = get_post( $artist_id );
        if ( ! $artist_profile_post_obj ) {
            return null;
        }
        // Create the link page if it doesn't exist using centralized creation system
        $creation_result = ec_create_link_page( $artist_id );
        if ( is_wp_error( $creation_result ) ) {
            error_log( 'Link page creation failed: ' . $creation_result->get_error_message() );
            return array('artist_id' => $artist_id, 'link_page_id' => null);
        }
        $link_page_id = $creation_result;

        if ( ! $link_page_id || !get_post_status($link_page_id) ) { // Check if it was actually created
            return array('artist_id' => $artist_id, 'link_page_id' => null);
        }
    }
    
    // Ensure the default link page has a specific title if it exists and needs one
    if ($link_page_id && get_post_status($link_page_id) && get_the_title($link_page_id) !== 'Extra Chill Landing Page') {
        wp_update_post(array(
            'ID' => $link_page_id,
            'post_title' => 'Extra Chill Landing Page'
        ));
    }

    return array(
        'artist_id'      => $artist_id,
        'link_page_id' => ($link_page_id && get_post_status($link_page_id)) ? $link_page_id : null,
    );
}

function extrch_render_default_artist_profile_widget() {

    $status = isset( $_GET['extrch_default_artist_status'] ) ? sanitize_text_field( $_GET['extrch_default_artist_status'] ) : '';
    if ( $status === 'created_all' ) {
        echo '<div class="notice notice-success"><p>Default band profile and link page are set up.</p></div>';
    } elseif ( $status === 'error' ) {
        echo '<div class="notice notice-error"><p>There was an error setting up the default band profile or link page.</p></div>';
    }

    // Check for existing default items without creating them initially for display
    $default_ids = extrch_get_or_create_default_admin_link_page( false ); // Pass false to not create

    if ( ! $default_ids || ! isset( $default_ids['artist_id'] ) || ! $default_ids['artist_id'] ) {
        echo '<p>The default "Extra Chill" band profile is missing.</p>';
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=extrch_ensure_default_link_items' ), 'extrch_ensure_default_link_items_action' );
        echo '<a href="' . esc_url( $url ) . '" class="button button-primary">Create Default Band Profile & Link Page</a>';
    } elseif ( ! isset( $default_ids['link_page_id'] ) || ! $default_ids['link_page_id'] ) {
        echo '<p>The default "Extra Chill" band profile exists (ID: ' . intval($default_ids['artist_id']) . '), but its link page is missing.</p>';
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=extrch_ensure_default_link_items' ), 'extrch_ensure_default_link_items_action' );
        echo '<a href="' . esc_url( $url ) . '" class="button button-primary">Create Default Link Page</a>';
    } else {
        $default_artist_id = $default_ids['artist_id'];
        $default_link_page_id = $default_ids['link_page_id'];

        echo '<p><span style="color:green;font-weight:bold;">Default artist profile and link page are present.</span></p>';
        echo '<ul>';
        echo '<li>Artist Profile ID: ' . intval( $default_artist_id ) . '</li>';
        echo '<li>Link Page ID: ' . intval( $default_link_page_id ) . '</li>';
        echo '</ul>';
        // Add simple frontend management links
        $frontend_manage_profile_url = site_url( '/manage-artist-profile/?artist_id=' . $default_artist_id );
        $frontend_manage_link_page_url = site_url( '/manage-link-page/?artist_id=' . $default_artist_id );
        echo '<p>';
        echo '<a href="' . esc_url( $frontend_manage_link_page_url ) . '" class="button button-primary">Manage Admin Link Page</a> ';
        echo '<a href="' . esc_url( $frontend_manage_profile_url ) . '" class="button">Manage Admin Artist Profile</a>';
        echo '</p>';
    }
}

// Handle the action for ensuring default items exist (called by the button if needed)
add_action( 'admin_post_extrch_ensure_default_link_items', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'extrch_ensure_default_link_items_action' ) ) {
        wp_die( 'Not allowed.' );
    }

    if ( ! function_exists( 'extrch_get_or_create_default_admin_link_page' ) ) {
        require_once dirname( __FILE__ ) . '/../link-pages/create-link-page.php';
    }
    // When the action is triggered, force creation if missing
    $result = extrch_get_or_create_default_admin_link_page( true );

    if ( $result && isset( $result['artist_id'] ) && $result['artist_id'] && isset( $result['link_page_id'] ) && $result['link_page_id'] ) {
        wp_redirect( admin_url( 'index.php?extrch_default_artist_status=created_all' ) );
    } else {
        wp_redirect( admin_url( 'index.php?extrch_default_artist_status=error' ) );
    }
    exit;
} );

// Remove old admin_post actions as they are now handled by the centralized function
// remove_action('admin_post_extrch_create_default_artist_profile', 'OLD_FUNCTION_NAME_IF_NAMED_ELSE_INLINE_CLOSURE');
// remove_action('admin_post_extrch_create_default_artist_link_page', 'OLD_FUNCTION_NAME_IF_NAMED_ELSE_INLINE_CLOSURE');
// Since the original actions were anonymous functions, we can't easily remove them by name.
// The best approach is to ensure the new extrch_ensure_default_link_items action is the one used.
// The old buttons that triggered those specific actions will be removed by the changes in extrch_render_default_artist_profile_widget.