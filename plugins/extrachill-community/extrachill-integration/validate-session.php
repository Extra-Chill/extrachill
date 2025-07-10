<?php 
// validates the token set by logging in to the community.extrachill.com site via the form on extrachill.com

add_action('rest_api_init', function () {
    register_rest_route('extrachill/v1', '/validate_token', array(
        'methods' => 'POST',
        'callback' => 'validate_session_token',
        'permission_callback' => '__return_true', // Adjust based on your security requirements
    ));

    // New endpoint to check band management access using standard WP auth
    register_rest_route('extrachill/v1', '/check-band-manage-access/(?P<band_id>\d+)', array(
        'methods' => 'GET',
        'callback' => 'extrch_check_band_manage_access_standard_auth',
        'permission_callback' => '__return_true', // Public endpoint, checks auth internally to handle cross-domain cases
        'args' => array(
            'band_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
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

/**
 * REST API callback to check if the current user can manage a specific band
 * using standard WordPress authentication.
 *
 * This endpoint handles cross-domain requests from extrachill.link to community.extrachill.com
 * and provides detailed debugging for authentication issues.
 *
 * @param WP_REST_Request $request Full details about the request.
 * @return WP_REST_Response|WP_Error Response object or Error object.
 */
function extrch_check_band_manage_access_standard_auth( $request ) {
    $band_id = (int) $request['band_id'];
    $can_manage = false;
    $debug_info = array();
    
    // Collect debug information
    $debug_info['band_id'] = $band_id;
    $debug_info['is_user_logged_in'] = is_user_logged_in();
    $debug_info['current_user_id'] = get_current_user_id();
    $debug_info['request_origin'] = $request->get_header('origin') ?: 'unknown';
    $debug_info['request_referer'] = $request->get_header('referer') ?: 'unknown';
    $debug_info['has_auth_cookies'] = !empty($_COOKIE['wordpress_logged_in_' . COOKIEHASH]);
    
    if ( is_user_logged_in() ) {
        $current_user_id = get_current_user_id();
        $debug_info['current_user_id'] = $current_user_id;
        
        if ( ! empty( $band_id ) ) {
            $can_manage = current_user_can( 'manage_band_members', $band_id );
            
            // Additional debug: check if user is linked to this band
            $user_band_ids = get_user_meta( $current_user_id, '_band_profile_ids', true );
            $debug_info['user_band_ids'] = $user_band_ids;
            $debug_info['is_band_member'] = is_array($user_band_ids) && in_array($band_id, $user_band_ids);
            $debug_info['band_exists'] = get_post_status($band_id) === 'publish';
        }
    } else {
        // Additional debug for non-logged-in users
        $debug_info['cookie_names'] = array_keys($_COOKIE);
        $debug_info['wp_cookie_check'] = isset($_COOKIE['wordpress_' . COOKIEHASH]);
    }
    
    $debug_info['can_manage'] = $can_manage;

    // Log comprehensive debug info
    error_log('[Edit Button Debug] Band management check: ' . json_encode($debug_info));

    return new WP_REST_Response( array( 
        'canManage' => $can_manage,
        'debug' => $debug_info  // Include debug info in response for troubleshooting
    ), 200 );
}




