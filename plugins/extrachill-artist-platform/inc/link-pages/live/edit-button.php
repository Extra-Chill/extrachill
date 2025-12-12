<?php
/**
 * Public Link Page Edit Button Script
 *
 * Loads the client-side edit button checker on extrachill.link link pages.
 * The script performs a credentialed REST request to artist.extrachill.com to
 * determine whether the current user can edit the current artist.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueue the edit button script for public link pages.
 *
 * Hooked into extrch_link_page_minimal_head because these templates bypass wp_head(),
 * but still print footer scripts via wp_print_footer_scripts().
 *
 * @param int $link_page_id Link page post ID.
 * @param int $artist_id    Artist profile post ID.
 * @return void
 */
function extrch_enqueue_link_page_edit_button_script( $link_page_id, $artist_id ) {
    $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $plugin_uri = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

    $script_rel_path = 'inc/link-pages/live/assets/js/link-page-edit-button.js';
    $script_abs_path = $plugin_dir . $script_rel_path;

    if ( ! file_exists( $script_abs_path ) ) {
        return;
    }

    wp_enqueue_script(
        'extrch-link-page-edit-button',
        $plugin_uri . $script_rel_path,
        array(),
        filemtime( $script_abs_path ),
        true
    );
}
add_action( 'extrch_link_page_minimal_head', 'extrch_enqueue_link_page_edit_button_script', 10, 2 );
