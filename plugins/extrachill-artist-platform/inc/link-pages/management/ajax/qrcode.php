<?php
/**
 * QR Code AJAX Handlers
 *
 * Handles AJAX requests related to QR code generation for link pages.
 * Registered via wp_ajax hooks in this file.
 */

defined( 'ABSPATH' ) || exit;

/**
 * AJAX handler to generate a QR code for a given URL.
 */
function extrch_generate_qrcode_ajax() {
    check_ajax_referer('ec_ajax_nonce', 'nonce');

    $link_page_id = absint($_POST['link_page_id']);

    if (!$link_page_id) {
         wp_send_json_error(['message' => 'Invalid link page ID.'], 400);
         return;
    }

    $artist_id = apply_filters('ec_get_artist_id', $link_page_id);

    if (!$artist_id || !ec_can_manage_artist(get_current_user_id(), $artist_id)) {
        wp_send_json_error(['message' => 'You do not have permission to generate a QR code for this link page.'], 403);
        return;
    }

    $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';

    if (empty($url)) {
        wp_send_json_error(['message' => 'URL is missing.'], 400);
        return;
    }

    try {
        $qrCode = new Endroid\QrCode\QrCode(
            data: $url,
            encoding: new Endroid\QrCode\Encoding\Encoding('UTF-8'),
            errorCorrectionLevel: Endroid\QrCode\ErrorCorrectionLevel::Low,
            size: 300,
            margin: 10
        );

        $writer = new Endroid\QrCode\Writer\PngWriter();
        $result = $writer->write($qrCode);
        $dataUri = $result->getDataUri();

        wp_send_json_success(['image_url' => $dataUri]);

    } catch (Exception $e) {
        wp_send_json_error(['message' => 'Failed to generate QR code: ' . $e->getMessage()], 500);
    }
}

add_action( 'wp_ajax_extrch_generate_qrcode', 'extrch_generate_qrcode_ajax' );