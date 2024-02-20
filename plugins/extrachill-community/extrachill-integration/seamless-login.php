<?php
 
// seamless-login.php on community.extrachill.com
// lives on community.extrachill.com and handles external login requests from extrachill.com

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/handle_external_login', array(
        'methods' => 'POST',
        'callback' => 'custom_ajax_login_handler',
        'permission_callback' => '__return_true', // Consider implementing permission checks as necessary
    ));
});


function custom_ajax_login_handler(WP_REST_Request $request) {
    global $wpdb;
    $credentials = [
        'user_login' => $request['username'],
        'user_password' => $request['password'],
        'remember' => !empty($request['rememberme']),
    ];

    $user = wp_signon($credentials, is_ssl());
    if (is_wp_error($user)) {
        return new WP_REST_Response(['success' => false, 'message' => $user->get_error_message()], 401);
    }

    $existingToken = check_for_existing_valid_token($user->ID);
    $table_name = $wpdb->prefix . 'user_session_tokens';
    
    if (!$existingToken) {
        $token = generate_community_session_token();
        // Adjusted for 6 months expiration
        $expiration = date('Y-m-d H:i:s', time() + (6 * 30 * 24 * 60 * 60));
        $wpdb->insert(
            $table_name,
            ['user_id' => $user->ID, 'token' => $token, 'expiration' => $expiration],
            ['%d', '%s', '%s']
        );
    } else {
        $token = $existingToken;
        // Update the expiration of the existing token
        $newExpiration = date('Y-m-d H:i:s', time() + (6 * 30 * 24 * 60 * 60));
        $wpdb->update(
            $table_name,
            ['expiration' => $newExpiration],
            ['user_id' => $user->ID, 'token' => $token],
            ['%s'],
            ['%d', '%s']
        );
    }
    
    $user_nicename = $user->user_nicename;

    return new WP_REST_Response([
        'success' => true,
        'message' => 'Login successful.',
        'ecc_user_session_token' => $token,
        'user_nicename' => $user_nicename
    ], 200);
}



// Implement this function based on your application logic
function check_for_existing_valid_token($userId) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_session_tokens';
    // Query for a valid, non-expired token for the user
    // This is a simplified query example. Adjust according to your actual data structure and security needs
    $query = $wpdb->prepare("SELECT token FROM $table_name WHERE user_id = %d AND expiration > NOW()", $userId);
    $token = $wpdb->get_var($query);

    return $token ?: false; // Return the token if found and valid, otherwise false
}
