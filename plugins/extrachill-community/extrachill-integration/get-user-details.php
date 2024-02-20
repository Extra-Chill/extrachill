<?php
/// Endpoint to retrieve user details from community.extrachill.com based on session token 
add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/user_details', array(
        'methods' => 'GET',
        'callback' => 'get_user_details',
        'permission_callback' => '__return_true', // Adjust based on your security requirements
    ));
});

// Function to retrieve user details based on session token
function get_user_details(WP_REST_Request $request) {
    global $wpdb;
    $headers = $request->get_headers();
    $auth_header = $headers['authorization'][0] ?? '';
    preg_match('/Bearer\s(.+)/', $auth_header, $matches);
    $token = $matches[1] ?? '';

    // Attempt to retrieve cached user details using the token as part of the transient key
    $cached_user_details = get_transient('user_details_' . md5($token)); // Use md5 to ensure a safe key

    if ($cached_user_details) {
        // Return cached user details if available
        return new WP_REST_Response($cached_user_details, 200);
    } else {
        // Proceed with database query if no cached details are found
        $table_name = $wpdb->prefix . 'user_session_tokens';
        $sql = $wpdb->prepare("SELECT user_id FROM $table_name WHERE token = %s AND expiration > NOW()", $token);
        $user_id = $wpdb->get_var($sql);

        if ($user_id) {
            $user_info = get_userdata($user_id);
            if ($user_info) {
                // Prepare user details for caching and response
                $user_details = [
                    'username' => $user_info->user_nicename,
                    'email' => $user_info->user_email,
                    'userID' => $user_id,
                ];

                // Cache the user details for a specified period, e.g., 1 hour
                set_transient('user_details_' . md5($token), $user_details, HOUR_IN_SECONDS);

                // Return the newly fetched user details
                return new WP_REST_Response($user_details, 200);
            } else {
                return new WP_REST_Response(['message' => 'User not found'], 404);
            }
        } else {
            return new WP_REST_Response(['message' => 'Invalid token'], 403);
        }
    }
}



