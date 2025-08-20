<?php 

// this code is used to preload user session details for the community site
function preload_user_details() {
    if (isset($_COOKIE['ecc_user_session_token'])) {
        $sessionToken = $_COOKIE['ecc_user_session_token'];
        // Assuming get_user_details_directly() is a function you've created that mimics get_user_details() but is used directly in PHP
        $userDetails = get_user_details_directly($sessionToken);

        if ($userDetails) {
            return $userDetails;
        }
    }
    return false;
}
function ecc_preload_user_session() {
    $userDetails = preload_user_details();
    if ($userDetails) {
        echo '<script type="text/javascript">';
        echo 'window.preloadedUserDetails = ' . json_encode($userDetails) . ';';
        echo '</script>';
    }
}
add_action('wp_footer', 'ecc_preload_user_session'); // or wp_head, depending on your setup


function get_user_details_directly($sessionToken) {
    // Check if user details are cached as a transient
    $cachedUserDetails = get_transient('user_details_' . md5($sessionToken));

    if ($cachedUserDetails !== false) {
        // Cached details are available, return them without making an API call
        return $cachedUserDetails;
    }

    // Endpoint URL
    $url = 'https://community.extrachill.com/wp-json/extrachill/v1/user_details';

    // Prepare the headers
    $args = array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $sessionToken,
        ),
    );

    // Make the request to the community site
    $response = wp_remote_get($url, $args);

    // Check for errors
    if (is_wp_error($response)) {
        error_log('Error retrieving user details: ' . $response->get_error_message());
        return false;
    }

    // Check for correct response code
    $statusCode = wp_remote_retrieve_response_code($response);
    if ($statusCode != 200) {
        error_log('Error retrieving user details: HTTP Status Code ' . $statusCode);
        return false;
    }

    // Decode the response body
    $body = wp_remote_retrieve_body($response);
    $userDetails = json_decode($body, true); // True to get an associative array

    // Check if the details are correctly retrieved
    if (isset($userDetails['username'], $userDetails['email'], $userDetails['userID'])) {
        // Cache the user details in a transient for 6 months
        set_transient('user_details_' . md5($sessionToken), $userDetails, 180 * DAY_IN_SECONDS);

        return $userDetails;
    }

    return false;
}

/**
 * Returns true if the current logged‑in user (via ecc_user_session_token cookie)
 * has purchased ad‑free access.
 */
// Modified to accept optional userDetails parameter
function is_user_ad_free($userDetails = null) {
    if ( empty($_COOKIE['ecc_user_session_token']) ) {
        return false;
    }

    // Use provided userDetails or fetch directly if not provided
    $userDetails = $userDetails ?: get_user_details_directly( sanitize_text_field($_COOKIE['ecc_user_session_token']) );
    if ( empty($userDetails['username']) ) {
        return false;
    }

    global $wpdb;
    $username = sanitize_text_field( $userDetails['username'] );
    $table    = $wpdb->prefix . 'extrachill_ad_free';

    return (bool) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE username = %s", $username)
    );
}
