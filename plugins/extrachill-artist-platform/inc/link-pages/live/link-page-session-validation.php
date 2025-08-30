<?php
/**
 * Link Page Session and Permission Validation via REST API.
 *
 * Provides a REST API endpoint to check user login status and artist management permissions.
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
 * REST API callback to check if the current user can manage the artist associated with a link page.
 *
 * This endpoint relies on standard WordPress authentication cookies being sent with the request.
 *
 * @param WP_REST_Request $request Full details about the request.
 * @return WP_REST_Response|WP_Error Response object or Error object.
 */
function extrch_check_link_page_manage_access( $request ) {
    // No need for $wpdb here as we rely on standard WP auth functions

    $link_page_id = (int) $request['link_page_id'];

    // Get the associated artist ID
    $artist_id = ec_get_artist_for_link_page( $link_page_id );

    $can_manage = false;

    // Check if user can manage this artist using centralized permission function
    if ( is_user_logged_in() && ! empty( $artist_id ) ) {
        $can_manage = ec_can_manage_artist( get_current_user_id(), $artist_id );
    }

    // Log debug info (optional, can be removed later)
    error_log('[DEBUG] extrch_check_link_page_manage_access API: link_page_id=' . $link_page_id . ', artist_id=' . $artist_id . ', is_user_logged_in()=' . (is_user_logged_in() ? 'true' : 'false') . ', can_manage=' . ($can_manage ? 'true' : 'false'));


    return new WP_REST_Response( array( 'canManage' => $can_manage ), 200 );
} 

function extrch_enqueue_public_session_script($link_page_id, $artist_id) {
    $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $plugin_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $session_js_path = 'inc/link-pages/live/assets/js/link-page-session.js';

    if (file_exists($plugin_dir . $session_js_path)) {
        $script_handle = 'extrch-public-session';
        wp_enqueue_script(
            $script_handle,
            $plugin_uri . $session_js_path,
            array(), // No dependencies
            filemtime($plugin_dir . $session_js_path),
            true // Load in footer
        );

        // Localize data for the script
        wp_localize_script($script_handle, 'extrchSessionData', array(
            'rest_url' => 'https://community.extrachill.com/wp-json/', // Get the REST API base URL of the main site (community.extrachill.com)
            'link_page_id' => $link_page_id, // Keep for potential future use, though not needed for the new endpoint
            'artist_id' => $artist_id, // Add artist_id for the new endpoint
            'artist_id' => $artist_id, // Backward compatibility alias
        ));
    } else {
        // Optionally log an error if the script file is missing
        error_log('Error: link-page-session.js not found.');
    }
}
// Hook into the custom action defined in extrch_link_page_custom_head
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_session_script', 10, 2);
