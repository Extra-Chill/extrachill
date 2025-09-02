<?php
/**
 * Admin Access Control
 * 
 * Centralized logic for restricting wp-admin access to administrators only.
 * Includes session token authentication fallback for cross-domain functionality.
 * 
 * @package Extra Chill Community
 */

/**
 * Restrict wp-admin access to administrators only
 */
function extrachill_redirect_admin() {
    // Primary: Check WordPress native authentication first
    if (is_user_logged_in() && current_user_can('administrator')) {
        return; // Admin authenticated via WordPress - allow access
    }
    
    // Fallback: Only if NOT logged in, check session token authentication
    if (!is_user_logged_in() && isset($_COOKIE['ecc_user_session_token'])) {
        global $wpdb;
        $token = $_COOKIE['ecc_user_session_token'];
        $table_name = $wpdb->prefix . 'user_session_tokens';
        
        // Get user ID from valid session token
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$table_name} WHERE token = %s AND expiration > NOW()",
            $token
        ));
        
        if ($user_id) {
            $user = get_user_by('id', $user_id);
            if ($user && in_array('administrator', $user->roles)) {
                // Only set auth cookies if user is NOT already logged in
                if (!is_user_logged_in()) {
                    wp_set_current_user($user_id);
                    wp_set_auth_cookie($user_id, true);
                }
                return;
            }
        }
    }
    
    // Final check: If still not admin after both authentication methods, restrict access
    if (!current_user_can('administrator') && is_admin() && !wp_doing_ajax()) {
        wp_safe_redirect(home_url('/'));
        exit();
    }
}
add_action('admin_init', 'extrachill_redirect_admin');

/**
 * Hide admin bar for non-administrators
 * Runs early on init to prevent admin bar from showing
 */
function extrachill_hide_admin_bar_for_non_admins() {
    if (!current_user_can('administrator')) {
        show_admin_bar(false);
    }
}
add_action('init', 'extrachill_hide_admin_bar_for_non_admins', 5);

/**
 * Ensure administrators can access wp-admin after login
 */
function extrachill_prevent_admin_auth_redirect($redirect_to, $requested_redirect_to, $user) {
    // If user is administrator and trying to access wp-admin, ensure they get there
    if (isset($user->ID) && current_user_can('administrator', $user->ID)) {
        if (!empty($requested_redirect_to) && strpos($requested_redirect_to, '/wp-admin') !== false) {
            return $requested_redirect_to; // Send admin directly to wp-admin
        }
        if (!empty($redirect_to) && strpos($redirect_to, '/wp-admin') !== false) {
            return $redirect_to; // Send admin directly to wp-admin
        }
    }
    return $redirect_to;
}
add_filter('login_redirect', 'extrachill_prevent_admin_auth_redirect', 5, 3); // High priority