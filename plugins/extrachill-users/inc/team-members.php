<?php
/**
 * Team Member Helper Functions
 *
 * @package ExtraChill\Users
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Check team member status with manual override support.
 *
 * @param int $user_id User ID (0 = current user).
 * @return bool
 */
function ec_is_team_member($user_id = 0) {
    if (!$user_id) {
        $user_id = get_current_user_id();
    }

    if (!$user_id) {
        return false;
    }

    $manual_override = get_user_meta($user_id, 'extrachill_team_manual_override', true);

    if ($manual_override === 'add') {
        return true;
    }

    if ($manual_override === 'remove') {
        return false;
    }

    return get_user_meta($user_id, 'extrachill_team', true) == 1;
}

/**
 * Check if user has account on extrachill.com.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function ec_has_main_site_account($user_id) {
    if (!$user_id) {
        return false;
    }

    $has_account = false;

    try {
        switch_to_blog( 1 );
        $has_account = is_user_member_of_blog( $user_id, 1 );
    } finally {
        restore_current_blog();
    }

    return $has_account;
}
