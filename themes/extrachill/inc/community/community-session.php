<?php

/**
 * Community User Authentication Functions
 *
 * Handles user authentication via native WordPress multisite functions
 * and ad-free access verification through database lookup.
 */




/**
 * Check if user has ad-free access via database lookup
 */
function is_user_ad_free($userDetails = null) {
    if (!is_user_logged_in()) {
        return false;
    }

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
