<?php
/**
 * Links AJAX Handlers
 * 
 * Single responsibility: Handle all AJAX requests related to link section and item management.
 * Provides template rendering endpoints for management interface and live preview system.
 */

// Register links AJAX actions using WordPress native patterns
add_action( 'wp_ajax_render_link_item_editor', 'ec_ajax_render_link_item_editor' );
add_action( 'wp_ajax_render_link_section_editor', 'ec_ajax_render_link_section_editor' );
add_action( 'wp_ajax_render_link_template', 'ec_ajax_render_link_template' );
add_action( 'wp_ajax_render_links_section_template', 'ec_ajax_render_links_section_template' );
add_action( 'wp_ajax_render_links_preview_template', 'ec_ajax_render_links_preview_template' );

/**
 * AJAX handler for rendering link item editor template
 * 
 * Returns HTML for a single editable link item in management interface.
 * Used by JavaScript to dynamically create and update link editing forms.
 * 
 * Expected POST parameters:
 * - sidx: Section index (int)
 * - lidx: Link index within section (int) 
 * - link_data: Array containing link_text, link_url, expires_at, id
 * - expiration_enabled: Whether expiration functionality is enabled (bool)
 * 
 * @return void Sends JSON response with rendered HTML template
 * @since 1.0.0
 */
function ec_ajax_render_link_item_editor() {
    try {
        // Verify standardized nonce
        check_ajax_referer('ec_ajax_nonce', 'nonce');
        
        // Check permissions
        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        // Get and sanitize parameters
        $sidx = isset( $_POST['sidx'] ) ? (int) $_POST['sidx'] : 0;
        $lidx = isset( $_POST['lidx'] ) ? (int) $_POST['lidx'] : 0;
        $link_data = isset( $_POST['link_data'] ) ? (array) $_POST['link_data'] : array();
        $expiration_enabled = isset( $_POST['expiration_enabled'] ) ? (bool) $_POST['expiration_enabled'] : false;
        
        // Sanitize link data
        $sanitized_link_data = array();
        if ( isset( $link_data['link_text'] ) ) {
            $sanitized_link_data['link_text'] = wp_unslash( sanitize_text_field( $link_data['link_text'] ) );
        }
        if ( isset( $link_data['link_url'] ) ) {
            $sanitized_link_data['link_url'] = wp_unslash( sanitize_url( $link_data['link_url'] ) );
        }
        if ( isset( $link_data['expires_at'] ) ) {
            $sanitized_link_data['expires_at'] = wp_unslash( sanitize_text_field( $link_data['expires_at'] ) );
        }
        if ( isset( $link_data['id'] ) ) {
            $sanitized_link_data['id'] = wp_unslash( sanitize_text_field( $link_data['id'] ) );
        }
        
        // Render editor template
        $editor_html = ec_render_template( 'link-item-editor', array(
            'sidx' => $sidx,
            'lidx' => $lidx,
            'link_data' => $sanitized_link_data,
            'expiration_enabled' => $expiration_enabled
        ) );
        
        // Render preview template - allow completely empty links for WYSIWYG
        $preview_html = ec_render_template( 'single-link', array(
            'link_url' => $sanitized_link_data['link_url'] ?: '',
            'link_text' => $sanitized_link_data['link_text'] ?: '',
            'link_classes' => 'extrch-link-page-link',
            'youtube_embed' => false
        ) );
        
        // Return complete DOM update instructions - server does ALL the thinking
        wp_send_json_success( array(
            'action' => 'add_link',
            'editor_html' => $editor_html,
            'preview_html' => $preview_html,
            'section_index' => $sidx,
            'link_index' => $lidx,
            'editor_target_selector' => '.bp-link-section[data-sidx="' . $sidx . '"] .bp-link-list',
            'preview_target_selector' => '.extrch-link-page-section[data-section-index="' . $sidx . '"] .extrch-link-page-links'
        ) );
        
    } catch ( Exception $e ) {
        error_log( 'Link item editor template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Template rendering failed' ) );
    }
}

/**
 * AJAX handler for rendering link section editor template
 * 
 * Returns HTML for a complete editable link section in management interface.
 * Handles section title and multiple links with full sanitization.
 * 
 * Expected POST parameters:
 * - sidx: Section index (int)
 * - section_data: Array containing section_title and links array
 * - expiration_enabled: Whether expiration functionality is enabled (bool)
 * 
 * @return void Sends JSON response with rendered HTML template
 * @since 1.0.0
 */
function ec_ajax_render_link_section_editor() {
    try {
        // Verify standardized nonce
        check_ajax_referer('ec_ajax_nonce', 'nonce');
        
        // Check permissions
        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        // Get and sanitize parameters
        $sidx = isset( $_POST['sidx'] ) ? (int) $_POST['sidx'] : 0;
        $section_data = isset( $_POST['section_data'] ) ? (array) $_POST['section_data'] : array();
        $expiration_enabled = isset( $_POST['expiration_enabled'] ) ? (bool) $_POST['expiration_enabled'] : false;
        
        // Sanitize section data
        $sanitized_section_data = array();
        if ( isset( $section_data['section_title'] ) ) {
            $sanitized_section_data['section_title'] = wp_unslash( sanitize_text_field( $section_data['section_title'] ) );
        }
        if ( isset( $section_data['links'] ) && is_array( $section_data['links'] ) ) {
            $sanitized_links = array();
            foreach ( $section_data['links'] as $link ) {
                if ( is_array( $link ) ) {
                    $sanitized_link = array();
                    if ( isset( $link['link_text'] ) ) {
                        $sanitized_link['link_text'] = wp_unslash( sanitize_text_field( $link['link_text'] ) );
                    }
                    if ( isset( $link['link_url'] ) ) {
                        $sanitized_link['link_url'] = wp_unslash( sanitize_url( $link['link_url'] ) );
                    }
                    if ( isset( $link['expires_at'] ) ) {
                        $sanitized_link['expires_at'] = wp_unslash( sanitize_text_field( $link['expires_at'] ) );
                    }
                    if ( isset( $link['id'] ) ) {
                        $sanitized_link['id'] = wp_unslash( sanitize_text_field( $link['id'] ) );
                    }
                    $sanitized_links[] = $sanitized_link;
                }
            }
            $sanitized_section_data['links'] = $sanitized_links;
        }
        
        // Render template
        $html = ec_render_template( 'link-section-editor', array(
            'sidx' => $sidx,
            'section_data' => $sanitized_section_data,
            'expiration_enabled' => $expiration_enabled
        ) );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Link section editor template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Template rendering failed' ) );
    }
}

/**
 * AJAX handler for rendering single link template
 * 
 * Returns HTML for a single link element using the unified template system.
 * Handles YouTube embed detection and applies appropriate CSS classes.
 * 
 * Expected POST parameters:
 * - link_url: Link URL (optional, defaults to '#')
 * - link_text: Link text (optional)
 * - youtube_embed: Whether this link should use YouTube embed (bool)
 * 
 * @return void Sends JSON response with rendered HTML
 * @since 1.0.0
 */
function ec_ajax_render_link_template() {
    try {
        // Get and validate parameters (allow empty data for new links)
        $link_url = wp_unslash( sanitize_url( $_POST['link_url'] ?? '' ) );
        $link_text = wp_unslash( sanitize_text_field( $_POST['link_text'] ?? '' ) );
        $youtube_embed = isset( $_POST['youtube_embed'] ) ? (bool) $_POST['youtube_embed'] : false;
        
        // Allow empty text/URL for new link templates
        if ( empty( $link_url ) ) {
            $link_url = '#'; // Default placeholder URL
        }
        
        // Build template arguments
        $template_args = array(
            'link_url' => $link_url,
            'link_text' => $link_text,
            'link_classes' => 'extrch-link-page-link',
            'youtube_embed' => $youtube_embed
        );
        
        // Add YouTube embed class if needed
        if ( $youtube_embed ) {
            $template_args['link_classes'] .= ' extrch-youtube-embed-link';
        }
        
        // Use unified template rendering
        $html = ec_render_template( 'single-link', $template_args );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Template rendering failed' ) );
    }
}

/**
 * AJAX handler for rendering complete links section template
 * 
 * Returns HTML for the entire links section using unified template system.
 * Processes multiple sections with titles and sanitizes all input data.
 * 
 * Expected POST parameters:
 * - sections_data: Array of section objects with section_title and links
 * 
 * @return void Sends JSON response with complete sections HTML
 * @since 1.0.0
 */
function ec_ajax_render_links_section_template() {
    try {
        // Get and validate section data
        $sections_data = isset( $_POST['sections_data'] ) ? $_POST['sections_data'] : array();
        
        if ( empty( $sections_data ) || ! is_array( $sections_data ) ) {
            wp_send_json_success( array( 'html' => '' ) );
            return;
        }
        
        // Sanitize and prepare sections data
        $sanitized_sections = array();
        foreach ( $sections_data as $section ) {
            $section_title = wp_unslash( sanitize_text_field( $section['section_title'] ?? '' ) );
            $sanitized_links = array();
            
            if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
                foreach ( $section['links'] as $link ) {
                    $link_text = wp_unslash( sanitize_text_field( $link['link_text'] ?? '' ) );
                    $link_url = wp_unslash( sanitize_url( $link['link_url'] ?? '' ) );
                    
                    // Only include valid links
                    if ( ! empty( $link_text ) && ! empty( $link_url ) ) {
                        $sanitized_links[] = array(
                            'link_text' => $link_text,
                            'link_url' => $link_url
                        );
                    }
                }
            }
            
            // Only include sections with title or links
            if ( ! empty( $section_title ) || ! empty( $sanitized_links ) ) {
                $sanitized_sections[] = array(
                    'section_title' => $section_title,
                    'links' => $sanitized_links
                );
            }
        }
        
        // Generate HTML for all sections using unified template system
        $complete_html = '';
        foreach ( $sanitized_sections as $section ) {
            if ( ! empty( $section['section_title'] ) ) {
                $complete_html .= '<h3 class="extrch-link-section-title">' . esc_html( $section['section_title'] ) . '</h3>';
            }
            
            if ( ! empty( $section['links'] ) ) {
                $section_args = array(
                    'links' => $section['links']
                );
                $complete_html .= ec_render_template( 'link-section', $section_args );
            }
        }
        
        wp_send_json_success( array( 'html' => $complete_html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Links section template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Links section template rendering failed' ) );
    }
}

/**
 * Helper function to render complete links sections HTML for preview
 * 
 * Renders multiple link sections using the unified template system.
 * Used by live preview to generate HTML without YouTube embeds.
 * 
 * @param array $links_data Array of section data with section_title and links
 * @return string Complete HTML for all link sections
 */
function ec_render_links_sections_html( $links_data ) {
    $html = '';
    
    if ( empty( $links_data ) || ! is_array( $links_data ) ) {
        return $html;
    }
    
    foreach ( $links_data as $section_data ) {
        if ( ! is_array( $section_data ) ) {
            continue;
        }
        
        // Set up section arguments for preview (similar to live page)
        $section_args = array(
            'section_title' => $section_data['section_title'] ?? '',
            'links' => $section_data['links'] ?? array(),
            'link_page_id' => 0 // No YouTube embed for preview
        );
        
        // Use existing ec_render_link_section function
        $html .= ec_render_link_section( $section_data, $section_args );
    }
    
    return $html;
}

/**
 * AJAX handler for rendering links preview template
 * 
 * Used by live preview system to render complete link sections with
 * real-time updates from management interface changes.
 * 
 * Expected POST parameters:
 * - links_data: JSON string containing array of section data
 * - nonce: WordPress AJAX nonce for security
 * 
 * @return void Sends JSON response with rendered HTML
 * @since 1.0.0
 */
function ec_ajax_render_links_preview_template() {
    try {
        // Verify standardized nonce
        check_ajax_referer( 'ec_ajax_nonce', 'nonce' );
        
        // Get and validate links data
        $links_data = isset( $_POST['links_data'] ) ? json_decode( stripslashes( $_POST['links_data'] ), true ) : array();
        
        if ( ! is_array( $links_data ) ) {
            wp_send_json_error( array( 'message' => 'Invalid links data' ) );
            return;
        }
        
        // Render complete links HTML using existing template system
        $html = ec_render_links_sections_html( $links_data );
        
        wp_send_json_success( array( 'html' => $html ) );
        
    } catch ( Exception $e ) {
        error_log( 'Links preview template rendering error: ' . $e->getMessage() );
        wp_send_json_error( array( 'message' => 'Links preview rendering failed: ' . $e->getMessage() ) );
    }
}