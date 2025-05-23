<?php
/**
 * Extrch.co Link Page - QR Code AJAX Handlers
 *
 * Handles AJAX requests related to QR code generation for link pages.
 *
 * @package ExtrchCo
 */

defined( 'ABSPATH' ) || exit;

// Include the Composer autoloader to ensure library classes are available
// require_once get_stylesheet_directory() . '/vendor/autoload.php'; // MOVED TO functions.php

// --- AJAX Handler for QR Code Generation ---
/**
 * AJAX handler to generate a QR code for a given URL.
 * Hooked to wp_ajax_extrch_generate_qrcode.
 */
function extrch_generate_qrcode_ajax() {
    // Check nonce for security
    check_ajax_referer('extrch_link_page_ajax_nonce', 'security');

    // Check if the user has permission to manage this link page
    // The link_page_id is passed in the AJAX data
    $link_page_id = isset($_POST['link_page_id']) ? absint($_POST['link_page_id']) : 0;

    if (!$link_page_id) {
         wp_send_json_error(['message' => 'Invalid link page ID.'], 400);
         return;
    }
    
    // Retrieve associated band ID from link page meta
    $band_id = get_post_meta($link_page_id, '_associated_band_profile_id', true);

    // Verify user has permission to manage the associated band
    if (!$band_id || !current_user_can('manage_band_members', $band_id)) {
        wp_send_json_error(['message' => 'You do not have permission to generate a QR code for this link page.'], 403);
        return;
    }

    // Get the URL from the AJAX request
    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

    if (empty($url)) {
        wp_send_json_error(['message' => 'URL is missing.'], 400);
        return;
    }

    // Use the Endroid/Bacon QR Code library to generate the QR code
    try {
        // Instantiate QrCode directly as per README example
        $qrCode = new Endroid\QrCode\QrCode(
            data: $url,
            encoding: new Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: Endroid\QrCode\ErrorCorrectionLevel::Low,
            size: 300,
            margin: 10
        );

        // Create a writer
        $writer = new Endroid\QrCode\Writer\PngWriter();

        // Write the QR code to a data URI
        $result = $writer->write($qrCode);
        $dataUri = $result->getDataUri();

        // Return the data URI in a JSON response
        wp_send_json_success(['image_url' => $dataUri]);

    } catch (Exception $e) {
        // Log the error and return a JSON error response
        error_log('QR Code Generation Error: ' . $e->getMessage());
        wp_send_json_error(['message' => 'Failed to generate QR code: ' . $e->getMessage()], 500);
    }
}

// Hook the AJAX handler to WordPress (for logged-in users)
add_action( 'wp_ajax_extrch_generate_qrcode', 'extrch_generate_qrcode_ajax' );

// Note: We are not hooking for non-logged-in users (wp_ajax_nopriv_) 
// because this functionality is only accessible from the authenticated manage page.

// --- End AJAX Handler for QR Code Generation --- 