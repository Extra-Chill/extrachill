<?php 
// validates the token set by logging in to the community.extrachill.com site via the form on extrachill.com

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/validate_token', array(
        'methods' => 'POST',
        'callback' => 'validate_session_token',
        'permission_callback' => '__return_true', // Adjust based on your security requirements
    ));
});

function validate_session_token(WP_REST_Request $request) {
    global $wpdb;

    // Retrieve the Authorization header
    $headers = $request->get_headers();
    $auth_header = $headers['authorization'][0] ?? '';
    preg_match('/Bearer\s(\S+)/', $auth_header, $matches);
    $token = $matches[1] ?? '';

    if (!$token) {
        // If no token found in Authorization header, check JSON body as fallback
        $params = $request->get_json_params();
        $token = $params['ecc_user_session_token'] ?? '';
    }

    if (!$token) {
        // No token provided
        return new WP_REST_Response(['success' => false, 'message' => 'No token provided'], 403);
    }

    // Define the table name where session tokens are stored
    $table_name = $wpdb->prefix . 'user_session_tokens';

    // Prepare the SQL query to check for the token's existence and that it hasn't expired
    $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table_name} WHERE token = %s AND expiration > NOW()", $token);

    // Execute the query and get the result
    $count = $wpdb->get_var($sql);

    // Check if the token was found and is still valid
    if ($count > 0) {
        // Token is valid and not expired
        return new WP_REST_Response(['success' => true, 'message' => 'Token is valid'], 200);
    } else {
        // Token is invalid or expired
        return new WP_REST_Response(['success' => false, 'message' => 'Token is invalid'], 403);
    }
}




