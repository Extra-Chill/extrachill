<?php
/**
 * Social Icons AJAX Handlers
 *
 * Template rendering for social icon management and live preview.
 */

// Register social icon AJAX actions using WordPress native patterns
add_action( 'wp_ajax_render_social_item_editor', 'ec_ajax_render_social_item_editor' );
add_action( 'wp_ajax_render_social_template', 'ec_ajax_render_social_template' );

/**
 * Render social media item editor with available platform options.
 */
function ec_ajax_render_social_item_editor() {
    try {
        check_ajax_referer('ec_ajax_nonce', 'nonce');

        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : 0;
        
        // Decode JSON strings from JavaScript - these are sent via JSON.stringify()
        $social_data = isset( $_POST['social_data'] ) ? json_decode( wp_unslash( $_POST['social_data'] ), true ) : array();
        $available_options = isset( $_POST['available_options'] ) ? json_decode( wp_unslash( $_POST['available_options'] ), true ) : array();
        $current_socials = isset( $_POST['current_socials'] ) ? json_decode( wp_unslash( $_POST['current_socials'] ), true ) : array();
        
        // Ensure arrays on decode failure
        $social_data = is_array( $social_data ) ? $social_data : array();
        $available_options = is_array( $available_options ) ? $available_options : array();
        $current_socials = is_array( $current_socials ) ? $current_socials : array();

        $sanitized_social_data = array();
        if ( isset( $social_data['type'] ) ) {
            $sanitized_social_data['type'] = wp_unslash( sanitize_text_field( $social_data['type'] ) );
        }
        if ( isset( $social_data['url'] ) ) {
            $sanitized_social_data['url'] = wp_unslash( sanitize_url( $social_data['url'] ) );
        }

        $sanitized_options = array();
        foreach ( $available_options as $option ) {
            if ( is_array( $option ) && isset( $option['value'] ) && isset( $option['label'] ) ) {
                $sanitized_options[] = array(
                    'value' => sanitize_key( $option['value'] ),
                    'label' => wp_unslash( sanitize_text_field( $option['label'] ) )
                );
            }
        }

        $sanitized_current_socials = array();
        foreach ( $current_socials as $current ) {
            if ( is_array( $current ) && isset( $current['type'] ) ) {
                $sanitized_current_socials[] = array(
                    'type' => sanitize_key( $current['type'] )
                );
            }
        }

        $html = ec_render_template( 'social-item-editor', array(
            'index' => $index,
            'social_data' => $sanitized_social_data,
            'available_options' => $sanitized_options,
            'current_socials' => $sanitized_current_socials
        ) );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Social item editor template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Template rendering failed' ) );
    }
}

/**
 * Render single social icon element using the unified template system.
 */
function ec_ajax_render_social_template() {
    try {
        check_ajax_referer('ec_ajax_nonce', 'nonce');

        $social_type = wp_unslash( sanitize_text_field( $_POST['social_type'] ?? '' ) );
        $social_url = wp_unslash( sanitize_url( $_POST['social_url'] ?? '' ) );

        if ( empty( $social_type ) || empty( $social_url ) ) {
            wp_send_json_error( array( 'message' => 'Missing required social data' ) );
            return;
        }

        if ( ! function_exists( 'extrachill_artist_platform_social_links' ) ) {
            wp_send_json_error( array( 'message' => 'Social links manager not available' ) );
            return;
        }

        $social_manager = extrachill_artist_platform_social_links();
        
        // Build social data
        $social_data = array(
            'type' => $social_type,
            'url' => $social_url
        );
        
        // Use unified template rendering
        $template_args = array(
            'social_data' => $social_data,
            'social_manager' => $social_manager
        );
        $html = ec_render_template( 'social-icon', $template_args );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Social template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Social template rendering failed' ) );
    }
}