<?php
/**
 * Link Page Session and Permission Validation via REST API.
 *
 * Provides a REST API endpoint to check user login status and artist management permissions.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue session checking JavaScript for link pages
 */
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
            'rest_url' => 'https://community.extrachill.com/wp-json/',
            'artist_id' => $artist_id,
        ));
    }
}

// Hook into the link page head action
add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_session_script', 10, 2);
