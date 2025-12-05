<?php
/**
 * Links AJAX Handlers
 *
 * WordPress native AJAX patterns with nonce verification and permission checks.
 * All handlers use ec_ajax_can_manage_link_page() for centralized permission validation.
 */
add_action( 'wp_ajax_render_link_item_editor', 'ec_ajax_render_link_item_editor' );
add_action( 'wp_ajax_render_link_section_editor', 'ec_ajax_render_link_section_editor' );

/**
 * Render link item editor template with full sanitization and preview HTML.
 */
function ec_ajax_render_link_item_editor() {
    try {
        check_ajax_referer('ec_ajax_nonce', 'nonce');

        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $sidx = isset( $_POST['sidx'] ) ? (int) $_POST['sidx'] : 0;
        $lidx = isset( $_POST['lidx'] ) ? (int) $_POST['lidx'] : 0;
        $link_data = isset( $_POST['link_data'] ) ? (array) $_POST['link_data'] : array();
        $expiration_enabled = isset( $_POST['expiration_enabled'] ) ? (bool) $_POST['expiration_enabled'] : false;

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

        $editor_html = ec_render_template( 'link-item-editor', array(
            'sidx' => $sidx,
            'lidx' => $lidx,
            'link_data' => $sanitized_link_data,
            'expiration_enabled' => $expiration_enabled
        ) );

        $preview_html = ec_render_template( 'single-link', array(
            'link_url' => $sanitized_link_data['link_url'] ?? '',
            'link_text' => $sanitized_link_data['link_text'] ?? '',
            'link_classes' => 'extrch-link-page-link',
            'youtube_embed' => false
        ) );

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
 * Render complete link section editor with titles and multiple links.
 */
function ec_ajax_render_link_section_editor() {
    try {
        check_ajax_referer('ec_ajax_nonce', 'nonce');

        if (!ec_ajax_can_manage_link_page($_POST)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }

        $sidx = isset( $_POST['sidx'] ) ? (int) $_POST['sidx'] : 0;
        $section_data = isset( $_POST['section_data'] ) ? (array) $_POST['section_data'] : array();
        $expiration_enabled = isset( $_POST['expiration_enabled'] ) ? (bool) $_POST['expiration_enabled'] : false;

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