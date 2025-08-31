<?php
/**
 * QR Code AJAX Handlers
 *
 * Handles AJAX requests related to QR code generation for link pages.
 * Registered through the centralized AJAX system in inc/core/actions/ajax.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler to generate a QR code for a given URL.
 */
function extrch_generate_qrcode_ajax() {
    // Check nonce for security
    check_ajax_referer('extrch_link_page_ajax_nonce', 'security');

    // Check if the user has permission to manage this link page
    // The link_page_id is passed in the AJAX data (sent by qrcode.js:38)
    $link_page_id = absint($_POST['link_page_id']);

    if (!$link_page_id) {
         wp_send_json_error(['message' => 'Invalid link page ID.'], 400);
         return;
    }
    
    // Retrieve associated artist ID from link page meta
    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);

    // Verify user has permission to manage the associated artist
    if (!$artist_id || !ec_can_manage_artist(get_current_user_id(), $artist_id)) {
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
        wp_send_json_error(['message' => 'Failed to generate QR code: ' . $e->getMessage()], 500);
    }
}