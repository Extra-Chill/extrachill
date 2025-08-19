<?php
/**
 * Admin Dashboard Widget: Manage Default Band Profile & Link Page
 *
 * Adds a widget for admins to create the default band profile and/or its link page if missing.
 */

add_action('wp_dashboard_setup', function() {
    if (!current_user_can('manage_options')) return;
    wp_add_dashboard_widget(
        'extrch_default_band_profile_widget',
        __('Extra Chill: Default Band Profile/Link Page', 'extra-chill-community'),
        'extrch_render_default_band_profile_widget'
    );
});

function extrch_render_default_band_profile_widget() {
    // Ensure the centralized creation function is available
    if ( ! function_exists( 'extrch_get_or_create_default_admin_link_page' ) ) {
        require_once dirname( __FILE__ ) . '/extrch.co-link-page/create-link-page.php';
    }

    $status = isset( $_GET['extrch_default_band_status'] ) ? sanitize_text_field( $_GET['extrch_default_band_status'] ) : '';
    if ( $status === 'created_all' ) {
        echo '<div class="notice notice-success"><p>Default band profile and link page are set up.</p></div>';
    } elseif ( $status === 'error' ) {
        echo '<div class="notice notice-error"><p>There was an error setting up the default band profile or link page.</p></div>';
    }

    // Check for existing default items without creating them initially for display
    $default_ids = extrch_get_or_create_default_admin_link_page( false ); // Pass false to not create

    if ( ! $default_ids || ! isset( $default_ids['band_id'] ) || ! $default_ids['band_id'] ) {
        echo '<p>The default "Extra Chill" band profile is missing.</p>';
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=extrch_ensure_default_link_items' ), 'extrch_ensure_default_link_items_action' );
        echo '<a href="' . esc_url( $url ) . '" class="button button-primary">Create Default Band Profile & Link Page</a>';
    } elseif ( ! isset( $default_ids['link_page_id'] ) || ! $default_ids['link_page_id'] ) {
        echo '<p>The default "Extra Chill" band profile exists (ID: ' . intval($default_ids['band_id']) . '), but its link page is missing.</p>';
        $url = wp_nonce_url( admin_url( 'admin-post.php?action=extrch_ensure_default_link_items' ), 'extrch_ensure_default_link_items_action' );
        echo '<a href="' . esc_url( $url ) . '" class="button button-primary">Create Default Link Page</a>';
    } else {
        $default_band_id = $default_ids['band_id'];
        $default_link_page_id = $default_ids['link_page_id'];

        echo '<p><span style="color:green;font-weight:bold;">Default band profile and link page are present.</span></p>';
        echo '<ul>';
        echo '<li>Band Profile ID: ' . intval( $default_band_id ) . '</li>';
        echo '<li>Link Page ID: ' . intval( $default_link_page_id ) . '</li>';
        echo '</ul>';
        // Add simple frontend management links
        $frontend_manage_profile_url = site_url( '/manage-band-profile/?band_id=' . $default_band_id );
        $frontend_manage_link_page_url = site_url( '/manage-link-page/?band_id=' . $default_band_id );
        echo '<p>';
        echo '<a href="' . esc_url( $frontend_manage_link_page_url ) . '" class="button button-primary">Manage Admin Link Page</a> ';
        echo '<a href="' . esc_url( $frontend_manage_profile_url ) . '" class="button">Manage Admin Band Profile</a>';
        echo '</p>';
    }
}

// Handle the action for ensuring default items exist (called by the button if needed)
add_action( 'admin_post_extrch_ensure_default_link_items', function() {
    if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'extrch_ensure_default_link_items_action' ) ) {
        wp_die( 'Not allowed.' );
    }

    if ( ! function_exists( 'extrch_get_or_create_default_admin_link_page' ) ) {
        require_once dirname( __FILE__ ) . '/extrch.co-link-page/create-link-page.php';
    }
    // When the action is triggered, force creation if missing
    $result = extrch_get_or_create_default_admin_link_page( true );

    if ( $result && isset( $result['band_id'] ) && $result['band_id'] && isset( $result['link_page_id'] ) && $result['link_page_id'] ) {
        wp_redirect( admin_url( 'index.php?extrch_default_band_status=created_all' ) );
    } else {
        wp_redirect( admin_url( 'index.php?extrch_default_band_status=error' ) );
    }
    exit;
} );

// Remove old admin_post actions as they are now handled by the centralized function
// remove_action('admin_post_extrch_create_default_band_profile', 'OLD_FUNCTION_NAME_IF_NAMED_ELSE_INLINE_CLOSURE');
// remove_action('admin_post_extrch_create_default_band_link_page', 'OLD_FUNCTION_NAME_IF_NAMED_ELSE_INLINE_CLOSURE');
// Since the original actions were anonymous functions, we can't easily remove them by name.
// The best approach is to ensure the new extrch_ensure_default_link_items action is the one used.
// The old buttons that triggered those specific actions will be removed by the changes in extrch_render_default_band_profile_widget.