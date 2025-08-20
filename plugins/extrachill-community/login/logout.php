<?php
/**
 * Custom Logout Functionality
 */

function extrachill_custom_logout_url($logout_url, $redirect) {
    // Nonce for security
    $action = 'custom-logout-action';
    // Current URL for staying on the same page
    $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $logout_url = add_query_arg('custom_logout', '1', $current_url);
    $logout_url = wp_nonce_url($logout_url, $action, 'logout_nonce');
    return $logout_url;
}
add_filter('logout_url', 'extrachill_custom_logout_url', 10, 2);

function extrachill_handle_custom_logout() {
    if (isset($_GET['custom_logout']) && $_GET['custom_logout'] == '1') {
        // Verify the nonce for security
        $nonce = $_GET['logout_nonce'] ?? '';
        if (wp_verify_nonce($nonce, 'custom-logout-action')) {
            wp_logout();
            // Redirect to homepage
            wp_safe_redirect(home_url()); 
            exit;
        }
    }
}
add_action('init', 'extrachill_handle_custom_logout');