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
    // Set CORS headers for cross-domain requests
    $allowed_origins = ['https://staging.extrachill.com', 'https://extrachill.com', 'https://extrachill.link'];
    $http_origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($http_origin, $allowed_origins)) {
        header('Access-Control-Allow-Origin: ' . $http_origin);
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: X-Requested-With, Content-Type, Accept, Origin, Authorization');
        header('Access-Control-Allow-Credentials: true');
    }

    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']))
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        
        if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']))
            header('Access-Control-Allow-Headers: ' . $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);

        exit(0);
    }

    global $wpdb;
    $headers = $request->get_headers();
    
    // Debug: Log headers to understand structure
    error_log('API Headers: ' . print_r($headers, true));
    
    // Try multiple ways to get the authorization header
    $auth_header = $headers['authorization'][0] ?? $headers['Authorization'][0] ?? '';
    
    // If still empty, try get_header method
    if (empty($auth_header)) {
        $auth_header = $request->get_header('authorization') ?? $request->get_header('Authorization') ?? '';
    }
    
    error_log('Auth header: ' . ($auth_header ? 'Header present' : 'No Authorization header found'));
    
    // Also check if token might be in request body or query params as fallback
    if (empty($auth_header)) {
        $params = $request->get_params();
        if (!empty($params['token'])) {
            $auth_header = 'Bearer ' . $params['token'];
            error_log('Token found in request params as fallback');
        }
    }
    
    preg_match('/Bearer\s(.+)/', $auth_header, $matches);
    $token = $matches[1] ?? '';
    
    error_log('Extracted token: ' . ($token ? 'Token present (' . strlen($token) . ' chars)' : 'No token'));

    if (!$token) {
        // No token provided - return early with proper error
        error_log('No token provided in Authorization header');
        return new WP_REST_Response(['message' => 'No token provided'], 403);
    }

    // Attempt to retrieve cached user details using the token as part of the transient key
    $cached_user_details = get_transient('user_details_' . md5($token)); // Use md5 to ensure a safe key

    if ($cached_user_details) {
        // Return cached user details if available
        return new WP_REST_Response($cached_user_details, 200);
    } else {
        // Proceed with database query if no cached details are found
        $table_name = $wpdb->prefix . 'user_session_tokens';
        
        // Debug: Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        error_log('Table exists: ' . ($table_exists ? 'Yes' : 'No'));
        
        if (!$table_exists) {
            error_log('Session tokens table does not exist, creating it...');
            create_session_tokens_table();
        }
        
        $sql = $wpdb->prepare("SELECT user_id FROM $table_name WHERE token = %s AND expiration > NOW()", $token);
        error_log('SQL Query: ' . $sql);
        
        $user_id = $wpdb->get_var($sql);
        error_log('Query result user_id: ' . ($user_id ? $user_id : 'null'));

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



