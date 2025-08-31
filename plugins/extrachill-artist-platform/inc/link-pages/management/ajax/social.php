<?php
/**
 * Social Icons AJAX Handlers
 * 
 * Single responsibility: Handle all AJAX requests related to social icon management
 */

// Register social icon AJAX actions using WordPress native patterns
add_action( 'wp_ajax_render_social_item_editor', 'ec_ajax_render_social_item_editor' );
add_action( 'wp_ajax_render_social_template', 'ec_ajax_render_social_template' );

/**
 * AJAX handler for rendering social item editor template
 * Returns HTML for a single editable social media item in management interface  
 */
function ec_ajax_render_social_item_editor() {
    try {
        // Verify standardized nonce
        check_ajax_referer('ec_ajax_nonce', 'nonce');
        
        // Check permissions
        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        // Get and sanitize parameters
        $index = isset( $_POST['index'] ) ? (int) $_POST['index'] : 0;
        $social_data = isset( $_POST['social_data'] ) ? (array) $_POST['social_data'] : array();
        $available_options = isset( $_POST['available_options'] ) ? (array) $_POST['available_options'] : array();
        $current_socials = isset( $_POST['current_socials'] ) ? (array) $_POST['current_socials'] : array();
        
        // Sanitize social data
        $sanitized_social_data = array();
        if ( isset( $social_data['type'] ) ) {
            $sanitized_social_data['type'] = wp_unslash( sanitize_text_field( $social_data['type'] ) );
        }
        if ( isset( $social_data['url'] ) ) {
            $sanitized_social_data['url'] = wp_unslash( sanitize_url( $social_data['url'] ) );
        }
        
        // Sanitize available options (from trusted source but still sanitize)
        $sanitized_options = array();
        foreach ( $available_options as $option ) {
            if ( is_array( $option ) && isset( $option['value'] ) && isset( $option['label'] ) ) {
                $sanitized_options[] = array(
                    'value' => sanitize_key( $option['value'] ),
                    'label' => wp_unslash( sanitize_text_field( $option['label'] ) )
                );
            }
        }
        
        // Sanitize current socials (just the types for duplicate checking)
        $sanitized_current_socials = array();
        foreach ( $current_socials as $current ) {
            if ( is_array( $current ) && isset( $current['type'] ) ) {
                $sanitized_current_socials[] = array(
                    'type' => sanitize_key( $current['type'] )
                );
            }
        }
        
        // Render template
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
 * AJAX handler for rendering social icon template
 * Returns HTML for a single social icon element
 */
function ec_ajax_render_social_template() {
    try {
        // Verify standardized nonce
        check_ajax_referer('ec_ajax_nonce', 'nonce');
        
        // Get and validate parameters
        $social_type = wp_unslash( sanitize_text_field( $_POST['social_type'] ?? '' ) );
        $social_url = wp_unslash( sanitize_url( $_POST['social_url'] ?? '' ) );
        
        if ( empty( $social_type ) || empty( $social_url ) ) {
            wp_send_json_error( array( 'message' => 'Missing required social data' ) );
            return;
        }
        
        // Get social manager
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