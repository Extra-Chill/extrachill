<?php
/**
 * Logout Functionality
 *
 * @package ExtraChill\Users
 */

/**
 * Filter logout URL for custom redirect.
 *
 * @param string $logout_url Default logout URL
 * @param string $redirect Redirect destination
 * @return string Modified logout URL
 */
function extrachill_custom_logout_url($logout_url, $redirect) {
    $action = 'custom-logout-action';
    $current_url = (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $logout_url = add_query_arg('custom_logout', '1', $current_url);
    $logout_url = wp_nonce_url($logout_url, $action, 'logout_nonce');
    return $logout_url;
}
add_filter('logout_url', 'extrachill_custom_logout_url', 10, 2);

/**
 * Handle custom logout with nonce verification.
 */
function extrachill_handle_custom_logout() {
    if (isset($_GET['custom_logout']) && $_GET['custom_logout'] == '1') {
        $nonce = $_GET['logout_nonce'] ?? '';
        if (wp_verify_nonce($nonce, 'custom-logout-action')) {
            wp_logout();
            wp_safe_redirect(home_url());
            exit;
        }
    }
}
add_action('init', 'extrachill_handle_custom_logout');