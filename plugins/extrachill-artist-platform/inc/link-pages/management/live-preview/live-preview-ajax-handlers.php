<?php
/**
 * Live Preview AJAX Handlers
 * 
 * Handles specific AJAX endpoints for live preview functionality including
 * real-time updates, data synchronization, and preview state management.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handle live preview content updates
 * 
 * @since 1.0.0
 */
function extrch_handle_preview_content_update() {
    // Verify nonce
    check_ajax_referer( 'extrch_link_page_ajax_nonce', 'nonce' );

    $link_page_id = absint( $_POST['link_page_id'] );
    $artist_id = absint( $_POST['artist_id'] );

    if ( ! $link_page_id || ! $artist_id ) {
        wp_send_json_error( 'Invalid parameters provided' );
    }

    // Check user permissions
    if ( ! ec_can_manage_artist( get_current_user_id(), $artist_id ) ) {
        wp_send_json_error( 'Insufficient permissions' );
    }

    try {
        // Prepare the updated data
        $updated_data = array();

        // Handle title updates
        if ( isset( $_POST['display_title'] ) ) {
            $updated_data['display_title'] = sanitize_text_field( wp_unslash( $_POST['display_title'] ) );
        }

        // Handle bio updates
        if ( isset( $_POST['bio'] ) ) {
            $updated_data['bio'] = sanitize_textarea_field( wp_unslash( $_POST['bio'] ) );
        }

        // Handle link updates
        if ( isset( $_POST['links'] ) ) {
            $links_data = json_decode( wp_unslash( $_POST['links'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $updated_data['links'] = $links_data;
            }
        }

        // Handle social links updates
        if ( isset( $_POST['social_links'] ) ) {
            $social_data = json_decode( wp_unslash( $_POST['social_links'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $updated_data['social_links'] = $social_data;
            }
        }

        // Handle customization updates
        if ( isset( $_POST['css_vars'] ) ) {
            $css_vars = json_decode( wp_unslash( $_POST['css_vars'] ), true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $updated_data['css_vars'] = $css_vars;
            }
        }

        // Get complete data using data provider
        if ( class_exists( 'LinkPageDataProvider' ) ) {
            $preview_data = LinkPageDataProvider::get_data( $link_page_id, $artist_id, $updated_data );
        } else {
            $preview_data = $updated_data;
        }

        // Generate updated preview HTML
        ob_start();
        set_query_var( 'preview_template_data', $preview_data );
        set_query_var( 'initial_container_style_for_php_preview', $preview_data['background_style'] ?? '' );
        include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/live-preview/preview.php';
        $preview_html = ob_get_clean();

        wp_send_json_success( array(
            'html' => $preview_html,
            'data' => $preview_data
        ) );

    } catch ( Exception $e ) {
        error_log( 'Live preview update error: ' . $e->getMessage() );
        wp_send_json_error( 'Preview update failed' );
    }
}
add_action( 'wp_ajax_extrch_update_preview', 'extrch_handle_preview_content_update' );
add_action( 'wp_ajax_nopriv_extrch_update_preview', 'extrch_handle_preview_content_update' );

/**
 * Handle preview data validation
 * 
 * @since 1.0.0
 */
function extrch_handle_preview_validation() {
    check_ajax_referer( 'extrch_link_page_ajax_nonce', 'nonce' );

    $data_type = sanitize_key( $_POST['data_type'] );
    $data_value = wp_unslash( $_POST['data_value'] );

    $validation_result = array( 'valid' => true, 'message' => '' );

    switch ( $data_type ) {
        case 'url':
            if ( ! filter_var( $data_value, FILTER_VALIDATE_URL ) ) {
                $validation_result = array( 
                    'valid' => false, 
                    'message' => __( 'Please enter a valid URL', 'extrachill-artist-platform' )
                );
            }
            break;

        case 'email':
            if ( ! is_email( $data_value ) ) {
                $validation_result = array( 
                    'valid' => false, 
                    'message' => __( 'Please enter a valid email address', 'extrachill-artist-platform' )
                );
            }
            break;

        case 'color':
            if ( ! preg_match( '/^#[a-fA-F0-9]{6}$/', $data_value ) ) {
                $validation_result = array( 
                    'valid' => false, 
                    'message' => __( 'Please enter a valid hex color code', 'extrachill-artist-platform' )
                );
            }
            break;
    }

    wp_send_json_success( $validation_result );
}
add_action( 'wp_ajax_extrch_validate_preview_data', 'extrch_handle_preview_validation' );

/**
 * Handle preview reset to saved state
 * 
 * @since 1.0.0
 */
function extrch_handle_preview_reset() {
    check_ajax_referer( 'extrch_link_page_ajax_nonce', 'nonce' );

    $link_page_id = absint( $_POST['link_page_id'] );
    $artist_id = absint( $_POST['artist_id'] );

    if ( ! $link_page_id || ! $artist_id ) {
        wp_send_json_error( 'Invalid parameters' );
    }

    // Get saved data without any overrides
    if ( class_exists( 'LinkPageDataProvider' ) ) {
        $saved_data = LinkPageDataProvider::get_data( $link_page_id, $artist_id );
    } else {
        wp_send_json_error( 'Data provider not available' );
    }

    // Generate reset preview HTML
    ob_start();
    set_query_var( 'preview_template_data', $saved_data );
    set_query_var( 'initial_container_style_for_php_preview', $saved_data['background_style'] ?? '' );
    include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/live-preview/preview.php';
    $preview_html = ob_get_clean();

    wp_send_json_success( array(
        'html' => $preview_html,
        'data' => $saved_data
    ) );
}
add_action( 'wp_ajax_extrch_reset_preview', 'extrch_handle_preview_reset' );