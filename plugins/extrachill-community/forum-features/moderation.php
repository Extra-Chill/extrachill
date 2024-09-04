<?php

/**
 * Disable wp_embed in bbPress forums
 */
function disable_wp_embed_in_bbpress() {
    // Check if bbPress is active
    if ( class_exists( 'bbPress' ) ) {
        // Remove the oEmbed discovery links
        remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );

        // Remove oEmbed-specific JavaScript from the front-end and back-end
        remove_action( 'wp_head', 'wp_oembed_add_host_js' );

        // Filter function to remove the TinyMCE plugin hook
        add_filter( 'tiny_mce_plugins', 'disable_wp_embed_tinymce_plugin' );
    }
}
add_action( 'init', 'disable_wp_embed_in_bbpress' );

/**
 * Filter function to remove the TinyMCE plugin hook
 */
function disable_wp_embed_tinymce_plugin( $plugins ) {
    if ( is_array( $plugins ) ) {
        return array_diff( $plugins, array( 'wpembed' ) );
    } else {
        return array();
    }
}
add_filter( 'bbp_bypass_check_for_moderation', '__return_true' );
/* function wrap_embed_with_div($html, $url, $attr) {
    // Array of domains to check against
    $domains_to_check = array(
        'spotify.com',
        'reddit.com'
        // Add more domains here as needed
    );

    // Check if the embed URL belongs to any of the specified domains
    foreach ($domains_to_check as $domain) {
        if (strpos($url, $domain) !== false) {
            // Return the HTML without the aspect-ratio wrapper for certain embeds
            return '<div class="' . str_replace('.', '-', $domain) . '-embed">' . $html . '</div>';
        }
    }

    // If the embed URL does not belong to any of the specified domains, wrap with aspect-ratio div
    return '<div class="aspect-ratio">' . $html . '</div>';
}

add_filter('embed_oembed_html', 'wrap_embed_with_div', 10, 3); */

