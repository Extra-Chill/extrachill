<?php

/**
 * Community Session Management
 * Native WordPress multisite authentication - replaces custom session token system
 * Leverages WordPress multisite cross-domain authentication capabilities
 *
 * @package ExtraChill
 * @since 1.0
 */




/**
 * Check if current user has purchased ad-free access
 * Uses native WordPress authentication with direct database query
 *
 * @param array|null $userDetails Optional user details array to avoid duplicate queries
 * @return bool True if user has ad-free access, false otherwise
 * @since 1.0
 */
function is_user_ad_free($userDetails = null) {
    if (!is_user_logged_in()) {
        return false;
    }

    // Use provided userDetails or get current user directly
    if (!$userDetails) {
        $user = wp_get_current_user();
        $username = $user->user_nicename;
    } else {
        $username = $userDetails['username'];
    }

    if (empty($username)) {
        return false;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'extrachill_ad_free';

    return (bool) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE username = %s", sanitize_text_field($username))
    );
}
