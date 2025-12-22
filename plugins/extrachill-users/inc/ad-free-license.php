<?php
/**
 * Ad-Free License Management
 *
 * Network-wide license validation and creation via user meta.
 *
 * @package ExtraChill\Users
 * @since 0.1.0
 */

/**
 * Check if user has ad-free license
 *
 * Checks user meta for ad-free license purchase.
 * Works with logged-in users or username lookup.
 *
 * @since 0.1.0
 * @param array|null $userDetails Optional user details with 'username' key
 * @return bool True if user has ad-free license
 */
function is_user_ad_free($userDetails = null) {
    if (!$userDetails && !is_user_logged_in()) {
        return false;
    }

    // Get user ID from details or current user
    if (!$userDetails) {
        $user_id = get_current_user_id();
    } else {
        // Lookup user by username
        $username = $userDetails['username'] ?? '';
        if (empty($username)) {
            return false;
        }

        $user = get_user_by('login', sanitize_text_field($username));
        if (!$user) {
            return false;
        }

        $user_id = $user->ID;
    }

    // Check user meta for ad-free license
    $license_data = get_user_meta($user_id, 'extrachill_ad_free_purchased', true);

    return !empty($license_data);
}

/**
 * Output Mediavine blocklist when ads should be blocked.
 *
 * Ad-free users are blocked globally, and site-specific plugins can request
 * ad blocking via the `extrachill_should_block_ads` filter.
 *
 * @since 0.1.0
 */
function extrachill_users_output_mediavine_blocklist() {
	if ( is_admin() ) {
		return;
	}

	$context = array(
		'blog_id'        => get_current_blog_id(),
		'post_type'      => is_singular() ? (string) get_post_type() : '',
		'is_front_page'  => is_front_page(),
		'is_home'        => is_home(),
		'is_page'        => is_page(),
		'is_search'      => is_search(),
		'is_archive'     => is_archive(),
		'is_singular'    => is_singular(),
		'is_post_type_archive' => is_post_type_archive(),
	);

	$should_block_ads = false;

	if ( is_user_logged_in() && is_user_ad_free() ) {
		$should_block_ads = true;
	} else {
		$should_block_ads = (bool) apply_filters( 'extrachill_should_block_ads', false, $context );
	}

	if ( ! $should_block_ads ) {
		return;
	}

	echo '<div id="mediavine-settings" data-blocklist-all="1"></div>' . "\n";
}
add_action( 'wp_head', 'extrachill_users_output_mediavine_blocklist', 1 );


/**
 * Create ad-free license for user
 *
 * Central function for license creation regardless of which site/plugin initiates purchase.
 * Stores license in user meta for network-wide availability.
 *
 * @since 0.1.0
 * @param string $username Community username
 * @param array $order_data Order details array with 'order_id' and optional 'timestamp'
 * @return bool|WP_Error True on success, WP_Error on failure
 */
function ec_create_ad_free_license($username, $order_data = array()) {
    // Validate username
    if (empty($username)) {
        return new WP_Error('empty_username', 'Username is required');
    }

    $username = sanitize_text_field($username);

    // Get user by login (bbPress/WordPress username)
    $user = get_user_by('login', $username);

    if (!$user) {
        return new WP_Error('user_not_found', "User not found for username: {$username}");
    }

    // Prepare license data
    $license_data = array(
        'purchased' => isset($order_data['timestamp']) ? $order_data['timestamp'] : current_time('mysql'),
        'order_id' => isset($order_data['order_id']) ? intval($order_data['order_id']) : 0,
        'username' => $username
    );

    // Store ad-free license in user meta
    $result = update_user_meta($user->ID, 'extrachill_ad_free_purchased', $license_data);

    if (!$result) {
        return new WP_Error('meta_update_failed', 'Failed to update user meta');
    }

    return true;
}
