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
        'permission_callback' => function() { return is_user_logged_in(); }, // Requires user to be logged in via standard WP auth
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
 * This endpoint should be called from a domain where the user is logged in,
 * like community.extrachill.com.
 *
 * @param WP_REST_Request $request Full details about the request.
 * @return WP_REST_Response|WP_Error Response object or Error object.
 */
function extrch_check_band_manage_access_standard_auth( $request ) {
    $band_id = (int) $request['band_id'];

    $can_manage = false;

    // is_user_logged_in() check is handled by permission_callback, but adding here for clarity and safety
    if ( is_user_logged_in() ) {
         if ( ! empty( $band_id ) && current_user_can( 'manage_band_members', $band_id ) ) {
            $can_manage = true;
        }
    }

    error_log('[DEBUG] extrch_check_band_manage_access_standard_auth API: band_id=' . $band_id . ', is_user_logged_in()=' . (is_user_logged_in() ? 'true' : 'false') . ', can_manage=' . ($can_manage ? 'true' : 'false'));

    return new WP_REST_Response( array( 'canManage' => $can_manage ), 200 );
}




