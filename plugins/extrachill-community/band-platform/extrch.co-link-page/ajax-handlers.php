<?php
/**
 * AJAX Handlers for Extrch.co Link Page Management.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * AJAX handler to fetch the meta title from a given URL.
 *
 * Tied to action: wp_ajax_fetch_link_meta_title
 */
function extrch_fetch_link_meta_title_ajax_handler() {
    // Verify nonce
    check_ajax_referer( 'fetch_link_meta_title_nonce', '_ajax_nonce' );

    if ( ! isset( $_POST['url'] ) || empty( $_POST['url'] ) ) {
        wp_send_json_error( [ 'message' => 'URL not provided.' ] );
        return;
    }

    $url = esc_url_raw( wp_unslash( $_POST['url'] ) );

    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        wp_send_json_error( [ 'message' => 'Invalid URL format.' ] );
        return;
    }

    $response = wp_safe_remote_get( $url, array( 'timeout' => 10, 'user-agent' => 'Mozilla/5.0 (compatible; ExtrchBot/1.0; +https://extrachill.com/)' ) ); // 10 second timeout with custom UA

    if ( is_wp_error( $response ) ) {
        // error_log( '[extrch_fetch_link_meta_title_ajax_handler] wp_safe_remote_get error: ' . $response->get_error_message() . ' for URL: ' . $url );
        wp_send_json_error( [ 'message' => 'Failed to retrieve URL: ' . $response->get_error_message() ] );
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    if ( empty( $body ) ) {
        // error_log( '[extrch_fetch_link_meta_title_ajax_handler] Empty body for URL: ' . $url );
        wp_send_json_error( [ 'message' => 'Retrieved empty content from URL.' ] );
        return;
    }

    $title = '';

    // Use YouTube oEmbed API for YouTube links
    if ( strpos( $url, 'youtube.com' ) !== false || strpos( $url, 'youtu.be' ) !== false ) {
        $oembed_url = 'https://www.youtube.com/oembed?url=' . urlencode($url) . '&format=json';
        $oembed_response = wp_safe_remote_get( $oembed_url, array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $oembed_response ) ) {
            $oembed_body = wp_remote_retrieve_body( $oembed_response );
            $oembed_data = json_decode( $oembed_body, true );
            if ( ! empty( $oembed_data['title'] ) ) {
                wp_send_json_success( [ 'title' => $oembed_data['title'] ] );
                return;
            }
        }
        // fallback to generic title if oEmbed fails
        wp_send_json_success( [ 'title' => 'YouTube Video' ] );
        return;
    }

    // Attempt to find Open Graph title first
    if ( preg_match( '/<meta\s+property=([\'\"])og:title\1\s+content=([\'\"])(.*?)\2\s*\/?\>/is', $body, $og_matches ) ) {
        $title = $og_matches[3];
    } elseif ( preg_match( '/<title>(.*?)<\/title>/is', $body, $matches ) ) {
        // Fallback to standard title tag
        $title = $matches[1];
    }

    if ( ! empty( $title ) ) {
        // Decode HTML entities (like &amp; to &) and then strip any remaining tags for safety.
        $title = wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) );
        $title = trim( $title );
        wp_send_json_success( [ 'title' => $title ] );
    } else {
        wp_send_json_error( [ 'message' => 'Could not find a title in the page content.' ] );
    }
}
add_action( 'wp_ajax_fetch_link_meta_title', 'extrch_fetch_link_meta_title_ajax_handler' ); 