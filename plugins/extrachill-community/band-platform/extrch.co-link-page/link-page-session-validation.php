<?php
/**
 * Link Page Session and Permission Validation via REST API.
 *
 * Provides a REST API endpoint to check user login status and band management permissions.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
    register_rest_route( 'extrachill/v1', '/link-page-manage-access/(?P<link_page_id>\d+)', array(
        'methods'             => 'GET',
        'callback'            => 'extrch_check_link_page_manage_access',
        'permission_callback' => '__return_true', // Endpoint is public, checks permissions internally
        'args'                => array(
            'link_page_id' => array(
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric( $param );
                }
            ),
        ),
    ));
});

/**
 * REST API callback to check if the current user can manage the band associated with a link page.
 *
 * This endpoint relies on standard WordPress authentication cookies being sent with the request.
 *
 * @param WP_REST_Request $request Full details about the request.
 * @return WP_REST_Response|WP_Error Response object or Error object.
 */
function extrch_check_link_page_manage_access( $request ) {
    // No need for $wpdb here as we rely on standard WP auth functions

    $link_page_id = (int) $request['link_page_id'];

    // Get the associated band ID
    $band_id = get_post_meta( $link_page_id, '_associated_band_profile_id', true );

    $can_manage = false;
    // $user_id is not needed as current_user_can works on the implicitly authenticated user

    // Check if a user is logged in via standard WordPress authentication
    if ( is_user_logged_in() ) {
         // If logged in, check if they have the capability to manage this band
         if ( ! empty( $band_id ) && current_user_can( 'manage_band_members', $band_id ) ) {
            $can_manage = true;
        }
    }

    // Log debug info (optional, can be removed later)
    error_log('[DEBUG] extrch_check_link_page_manage_access API: link_page_id=' . $link_page_id . ', band_id=' . $band_id . ', is_user_logged_in()=' . (is_user_logged_in() ? 'true' : 'false') . ', can_manage=' . ($can_manage ? 'true' : 'false'));


    return new WP_REST_Response( array( 'canManage' => $can_manage ), 200 );
} 

function extrch_enqueue_public_session_script($link_page_id, $band_id) {
    $theme_dir = get_stylesheet_directory();
    $theme_uri = get_stylesheet_directory_uri();
    $session_js_path = '/band-platform/extrch.co-link-page/js/link-page-session.js';

    if (file_exists($theme_dir . $session_js_path)) {
        $script_handle = 'extrch-public-session';
        wp_enqueue_script(
            $script_handle,
            $theme_uri . $session_js_path,
            array(), // No dependencies
            filemtime($theme_dir . $session_js_path),
            true // Load in footer
        );

        // Localize data for the script
        wp_localize_script($script_handle, 'extrchSessionData', array(
            'rest_url' => 'https://community.extrachill.com/wp-json/', // Get the REST API base URL of the main site (community.extrachill.com)
            'link_page_id' => $link_page_id, // Keep for potential future use, though not needed for the new endpoint
            'band_id' => $band_id, // Add band_id for the new endpoint
        ));
    } else {
        // Optionally log an error if the script file is missing
        error_log('Error: link-page-session.js not found.');
    }
}
// Hook into the custom action defined in extrch_link_page_custom_head
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_session_script', 10, 2);
