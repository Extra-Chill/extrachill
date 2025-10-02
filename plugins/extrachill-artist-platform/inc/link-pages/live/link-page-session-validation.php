<?php
/**
 * Link page session enqueuing for cross-domain authentication
 * WordPress multisite provides native cross-domain authentication across .extrachill.com subdomains
 */

defined( 'ABSPATH' ) || exit;

function extrch_enqueue_public_session_script($link_page_id, $artist_id) {
    $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $plugin_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;
    $session_js_path = 'inc/link-pages/live/assets/js/link-page-session.js';

    if (file_exists($plugin_dir . $session_js_path)) {
        $script_handle = 'extrch-public-session';
        wp_enqueue_script(
            $script_handle,
            $plugin_uri . $session_js_path,
            array(),
            filemtime($plugin_dir . $session_js_path),
            true
        );
        wp_localize_script($script_handle, 'extrchSessionData', array(
            'rest_url' => 'https://community.extrachill.com/wp-json/',
            'artist_id' => $artist_id,
        ));
    }
}

add_action('extrch_link_page_minimal_head', 'extrch_enqueue_public_session_script', 10, 2);
