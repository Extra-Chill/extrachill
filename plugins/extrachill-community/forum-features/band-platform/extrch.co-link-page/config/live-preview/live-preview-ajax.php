<?php
/**
 * AJAX handler for generating the extrch.co Link Page live preview.
 */

defined( 'ABSPATH' ) || exit;

// Ensure LivePreviewManager is available
// Note: link-page-data.php is deprecated and its functionality is in LivePreviewManager.
require_once dirname( __FILE__ ) . '/LivePreviewManager.php';

// Endroid QR Code specific uses for v6.x API
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;

add_action( 'wp_ajax_extrch_render_link_page_preview', 'extrch_handle_render_link_page_preview' );

/**
 * Handles the AJAX request to render the link page preview.
 */
function extrch_handle_render_link_page_preview() {
    // Verify nonce for security
    check_ajax_referer( 'bp_save_link_page_action', 'security_nonce' );

    // Get essential IDs from the AJAX request
    $link_page_id = isset( $_POST['link_page_id'] ) ? absint( $_POST['link_page_id'] ) : 0;
    $band_id      = isset( $_POST['band_id'] ) ? absint( $_POST['band_id'] ) : 0;

    if ( ! $link_page_id || ! $band_id ) {
        wp_send_json_error( [ 'message' => __( 'Missing required page or band ID for preview.', 'generatepress_child' ) ] );
        return;
    }

    if ( ! current_user_can( 'manage_band_members', $band_id ) ) {
        wp_send_json_error( [ 'message' => __( 'You do not have permission to preview this link page.', 'generatepress_child' ) ] );
        return;
    }

    // --- Prepare Preview Overrides from _POST data ---
    $preview_overrides = [];
    // Define all keys that JavaScript might send as overrides from the "Customize" tab.
    $allowed_override_keys = [
        'band_profile_title',
        'link_page_bio_text',
        'profile_img_url', // Data URI for profile image
        'band_profile_social_links_json',
        'link_page_links_json',
        'link_page_background_type',
        'link_page_background_color', // From color picker
        'link_page_background_gradient_start',
        'link_page_background_gradient_end',
        'link_page_background_gradient_direction',
        'background_image_url', // Data URI for background image
        'link_page_custom_css_vars_json' // JSON string from JS, includes all CSS vars like text color, button color, font etc.
    ];

    foreach ($allowed_override_keys as $key) {
        if (isset($_POST[$key])) {
            if ($key === 'profile_img_url' || $key === 'background_image_url') {
                // For data URIs, just unslash and trim. esc_url() in the template will sanitize for display.
                // For empty string (clearing image), it's also fine.
                $trimmed_unslashed_value = trim(wp_unslash($_POST[$key]));
                // Use stripos for case-insensitive matching of "data:image"
                if (stripos($trimmed_unslashed_value, 'data:image') === 0 || $trimmed_unslashed_value === '') {
                    $preview_overrides[$key] = $trimmed_unslashed_value;
                } else {
                    // If it's not a data URI (even case-insensitively) and not an empty string, it might be a regular URL.
                    // In this specific live preview context, we typically expect data URIs for new uploads
                    // or empty strings to clear. If actual URLs were to be passed as overrides here,
                    // they would need esc_url_raw. For now, only data URIs or empty are processed.
                }
            } elseif (strpos($key, '_json') !== false) {
                $preview_overrides[$key] = wp_unslash($_POST[$key]); // For JSON strings
            } elseif ($key === 'link_page_bio_text') {
                 $preview_overrides[$key] = wp_kses_post(wp_unslash($_POST[$key]));
            } else {
                // For other fields like colors, type, direction - sanitize as text.
                // Empty strings are valid (e.g. clearing a color to use default).
                $preview_overrides[$key] = sanitize_text_field(wp_unslash($_POST[$key]));
            }
        }
    }
    
    // --- Get data using the LivePreviewManager ---
    // LivePreviewManager now handles all logic of using overrides and meta to produce final values.
    $preview_render_data = LivePreviewManager::get_preview_data( $link_page_id, $band_id, $preview_overrides );

    // --- Render the Template ---
    ob_start();
    // Pass the processed data to the preview template part
    set_query_var('preview_template_data', $preview_render_data);
    // The 'initial_container_style_for_php_preview' will be derived from $preview_render_data['container_style_for_preview'] inside preview.php
    // Or, we can set it explicitly here too for clarity if preview.php expects it directly.
    set_query_var('initial_container_style_for_php_preview', $preview_render_data['container_style_for_preview']);

    require dirname( __FILE__ ) . '/preview.php';
    $html_output = ob_get_clean();

    // The JavaScript will receive the full HTML of the preview content,
    // plus the specific CSS variables and container style it needs to update the iframe.
    wp_send_json_success( [
        'html' => $html_output, // The rendered content of preview.php
        'css_vars' => $preview_render_data['css_vars_for_preview_style_tag'], // e.g. { "--link-page-text-color": "#123456", ... }
        'container_style' => $preview_render_data['container_style_for_preview'] // e.g. "background-color: #abcdef;" or ""
    ] );
}

// Helper sanitization functions (can be expanded)
if (!function_exists('sanitize_social_link_array')) { // Prevent re-declaration if included elsewhere
    function sanitize_social_link_array( $social_link ) {
        $sanitized = [];
        $sanitized['type'] = isset( $social_link['type'] ) ? sanitize_text_field( $social_link['type'] ) : 'website';
        $sanitized['url']  = isset( $social_link['url'] ) ? esc_url_raw( $social_link['url'] ) : '';
        $sanitized['icon'] = isset( $social_link['icon'] ) ? sanitize_text_field( $social_link['icon'] ) : ''; // Optional explicit icon
        return array_filter($sanitized); // array_filter to remove empty values if needed, e.g. empty icon string
    }
}

if (!function_exists('sanitize_link_section_array')) { // Prevent re-declaration
    function sanitize_link_section_array( $section ) {
        $sanitized_section = [];
        $sanitized_section['section_title'] = isset( $section['section_title'] ) ? sanitize_text_field( $section['section_title'] ) : '';
        $sanitized_section['links'] = [];
        if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
            foreach ( $section['links'] as $link ) {
                $s_link = [];
                $s_link['link_text'] = isset( $link['link_text'] ) ? sanitize_text_field( $link['link_text'] ) : '';
                $s_link['link_url']  = isset( $link['link_url'] ) ? esc_url_raw( $link['link_url'] ) : '';
                $s_link['link_is_active'] = isset( $link['link_is_active'] ) ? (bool) $link['link_is_active'] : true;
                if($s_link['link_text'] && $s_link['link_url']) { // Only add if both text and URL exist
                     $sanitized_section['links'][] = $s_link;
                }
            }
        }
        return $sanitized_section;
    }
}

add_action( 'wp_ajax_extrch_generate_qr_code', 'extrch_handle_generate_qr_code' );

/**
 * Handles the AJAX request to generate a QR code for the link page URL.
 * If a QR code already exists for this link page, it returns the existing one.
 * Otherwise, it generates a new QR code, saves it to the Media Library,
 * and stores its attachment ID as post meta.
 */
function extrch_handle_generate_qr_code() {
    check_ajax_referer( 'bp_save_link_page_action', 'security_nonce' );

    $link_page_id = isset( $_POST['link_page_id'] ) ? absint( $_POST['link_page_id'] ) : 0;

    if ( ! $link_page_id ) {
        wp_send_json_error( [ 'message' => __( 'Link Page ID is missing.', 'generatepress_child' ) ] );
        return;
    }

    $band_id = get_post_meta( $link_page_id, '_associated_band_profile_id', true );
    if ( ! $band_id || ! current_user_can( 'manage_band_members', $band_id ) ) {
        wp_send_json_error( [ 'message' => __( 'You do not have permission to generate a QR code for this link page.', 'generatepress_child' ) ] );
        return;
    }

    // --- Determine the Current Correct Public URL for the Link Page ---
    $band_post = get_post( $band_id );
    if ( ! $band_post || $band_post->post_type !== 'band_profile' ) {
        wp_send_json_error( [ 'message' => __( 'Associated band profile not found for link page.', 'generatepress_child' ) ] );
        return;
    }
    $band_slug = $band_post->post_name;
    $current_public_url = '';
    if ( defined('EXTRCH_LINKPAGE_DEV') && EXTRCH_LINKPAGE_DEV ) {
        $current_public_url = get_permalink( $link_page_id );
    } else {
        $current_public_url = 'https://extrch.co/' . $band_slug;
    }

    if ( empty( $current_public_url ) ) {
        wp_send_json_error( [ 'message' => __( 'Could not determine current public URL for the link page.', 'generatepress_child' ) ] );
        return;
    }

    // --- Check for Existing QR Code and its Validity ---
    $existing_qr_attachment_id = get_post_meta( $link_page_id, '_band_link_page_qr_code_attachment_id', true );
    $stored_qr_source_url = get_post_meta( $link_page_id, '_band_link_page_qr_code_source_url', true );
    $regenerate_qr = false;

    if ( $existing_qr_attachment_id ) {
        $qr_image_url = wp_get_attachment_url( $existing_qr_attachment_id );
        if ( $qr_image_url && $stored_qr_source_url === $current_public_url ) {
            // QR exists, is valid, and matches the current URL
            wp_send_json_success( [ 'qr_image_url' => $qr_image_url ] );
            return;
        } else {
            // QR is invalid (image missing) or source URL mismatch - mark for regeneration
            $regenerate_qr = true;
            // Clean up old meta and attachment before regenerating
            delete_post_meta( $link_page_id, '_band_link_page_qr_code_attachment_id' );
            delete_post_meta( $link_page_id, '_band_link_page_qr_code_source_url' );
            wp_delete_attachment( $existing_qr_attachment_id, true );
        }
    } else {
        // No existing QR code attachment ID found
        $regenerate_qr = true;
    }
    
    // If $regenerate_qr is false at this point, it means something went wrong with the logic above or no QR existed initially.
    // It should be true if we need to generate one.
    if (!$regenerate_qr) {
        // This case should ideally not be hit if logic is correct, but as a fallback:
        $regenerate_qr = true; 
    }

    // --- Generate New QR Code ---
    try {
        // Use named arguments for Builder constructor as per endroid/qr-code v5+/v6+ docs
        $builder = new Builder(
            data: $current_public_url,
            writer: new PngWriter(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High, // Assumes ErrorCorrectionLevel::High is an Enum case or constant
            size: 300,
            margin: 10,
            validateResult: false // Writer option, not builder method
        );

        // Writer options are typically passed to the writer, not the builder directly, or to Builder constructor.
        // For PngWriter, options like compression level can be set on the PngWriter instance if needed:
        // $writer = new PngWriter();
        // $writer->setCompressionLevel(9); // Example
        // $builder = new Builder(writer: $writer, ...);
        // Or if writerOptions are part of Builder constructor:
        // $builder = new Builder(writer: new PngWriter(), writerOptions: [PngWriter::WRITER_OPTION_COMPRESSION_LEVEL => 9], ...);
        // For simplicity, we'll omit writerOptions from Builder if not directly supported in constructor like this.

        $result = $builder->build();

        $png_data = $result->getString();

        if ( ! function_exists( 'wp_handle_upload' ) ) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/image.php'; }
        if ( ! function_exists( 'wp_read_image_metadata' ) ) { require_once ABSPATH . 'wp-admin/includes/media.php'; }

        $upload_dir = wp_upload_dir();
        $filename = sanitize_title( $band_post->post_title ) . '-link-page-qr.png';
        $filename = wp_unique_filename( $upload_dir['path'], $filename );
        $upload = wp_upload_bits( $filename, null, $png_data );

        if ( ! empty( $upload['error'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Error uploading QR code: ', 'generatepress_child' ) . $upload['error'] ] );
            return;
        }

        $attachment = array(
            'guid'           => $upload['url'],
            'post_mime_type' => 'image/png',
            'post_title'     => preg_replace( '/\\.[^.]+$/', '', basename( $filename ) ),
            'post_content'   => '',
            'post_status'    => 'inherit',
        );
        $attachment_id = wp_insert_attachment( $attachment, $upload['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Error saving QR code to media library: ', 'generatepress_child' ) . $attachment_id->get_error_message() ] );
            return;
        }

        $attachment_data = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
        wp_update_attachment_metadata( $attachment_id, $attachment_data );

        // Store new attachment ID and the source URL used for this QR code
        update_post_meta( $link_page_id, '_band_link_page_qr_code_attachment_id', $attachment_id );
        update_post_meta( $link_page_id, '_band_link_page_qr_code_source_url', $current_public_url );

        wp_send_json_success( [ 'qr_image_url' => $upload['url'] ] );

    } catch ( \Exception $e ) { // Catch generic Exception
        wp_send_json_error( [ 'message' => __( 'Error generating QR code: ', 'generatepress_child' ) . $e->getMessage() ] );
    }
}
