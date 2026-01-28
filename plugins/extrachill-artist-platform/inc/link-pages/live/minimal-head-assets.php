<?php
/**
 * Link Page Minimal Head Assets
 *
 * Enqueues all public link page assets via the extrachill_artist_link_page_minimal_head hook.
 * These templates bypass wp_head(), so link page templates print styles via wp_print_styles()
 * and footer scripts via wp_print_footer_scripts().
 */

defined( 'ABSPATH' ) || exit;

add_action( 'extrachill_artist_link_page_minimal_head', 'extrachill_artist_enqueue_link_page_minimal_assets', 10, 2 );

/**
 * Enqueue link page assets (CSS + JS) for public pages.
 *
 * @param int $link_page_id Link page post ID.
 * @param int $artist_id    Artist profile post ID.
 * @return void
 */
function extrachill_artist_enqueue_link_page_minimal_assets( $link_page_id, $artist_id ) {
    $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
    $plugin_url = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL;

    $styles = array(
        'extrch-link-page'           => 'assets/css/extrch-links.css',
        'extrch-share-modal'         => 'assets/css/extrch-share-modal.css',
        'extrch-custom-social-icons' => 'assets/css/custom-social-icons.css',
        'extrch-font-awesome'        => 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css',
    );

    foreach ( $styles as $handle => $path ) {
        if ( 0 === strpos( $path, 'http' ) ) {
            wp_enqueue_style( $handle, $path, array(), null );
            continue;
        }

        $abs_path = $plugin_dir . $path;
        if ( ! file_exists( $abs_path ) ) {
            continue;
        }

        wp_enqueue_style( $handle, $plugin_url . $path, array(), filemtime( $abs_path ) );
    }

    $scripts = array(
        'extrch-share-modal'         => 'inc/link-pages/live/assets/js/extrch-share-modal.js',
        'extrch-subscribe'           => 'inc/link-pages/live/assets/js/link-page-subscribe.js',
        'extrch-edit-button'         => 'inc/link-pages/live/assets/js/link-page-edit-button.js',
        'extrch-public-tracking'     => 'inc/link-pages/live/assets/js/link-page-public-tracking.js',
        'extrch-link-page-youtube'   => 'inc/link-pages/live/assets/js/link-page-youtube-embed.js',
    );

    foreach ( $scripts as $handle => $path ) {
        $abs_path = $plugin_dir . $path;
        if ( ! file_exists( $abs_path ) ) {
            continue;
        }

        wp_enqueue_script( $handle, $plugin_url . $path, array(), filemtime( $abs_path ), true );
    }
}
