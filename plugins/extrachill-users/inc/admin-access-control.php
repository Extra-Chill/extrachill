<?php
/**
 * Network-Wide Admin Access Control
 *
 * Restricts wp-admin access to administrators across the entire multisite network.
 * Preserves AJAX functionality while redirecting unauthorized users to homepage.
 *
 * @package ExtraChill\Users
 * @since 0.1.0
 */

function extrachill_redirect_admin() {
    global $pagenow;

    if (!current_user_can('administrator') && !ec_is_team_member() && is_admin() && !wp_doing_ajax() && $pagenow !== 'admin-post.php') {
        wp_safe_redirect(home_url('/'));
        exit();
    }
}
add_action('admin_init', 'extrachill_redirect_admin');

function extrachill_hide_admin_bar_for_non_admins() {
    if (!current_user_can('administrator') && !ec_is_team_member()) {
        show_admin_bar(false);
    }
}
add_action('init', 'extrachill_hide_admin_bar_for_non_admins', 5);

/**
 * Prevent login redirect to admin for non-administrators
 */
function extrachill_prevent_admin_auth_redirect($redirect_to, $requested_redirect_to, $user) {
    if (isset($user->ID) && (current_user_can('administrator', $user->ID) || ec_is_team_member($user->ID))) {
        if (!empty($requested_redirect_to) && strpos($requested_redirect_to, '/wp-admin') !== false) {
            return $requested_redirect_to;
        }
        if (!empty($redirect_to) && strpos($redirect_to, '/wp-admin') !== false) {
            return $redirect_to;
        }
    }
    return $redirect_to;
}
add_filter('login_redirect', 'extrachill_prevent_admin_auth_redirect', 5, 3);