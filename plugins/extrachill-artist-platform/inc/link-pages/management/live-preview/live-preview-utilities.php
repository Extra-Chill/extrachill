<?php
/**
 * Live Preview Utility Functions
 * 
 * Provides utility functions for live preview functionality including
 * data sanitization, validation, and formatting helpers.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize and validate preview data
 * 
 * @since 1.0.0
 * @param array $data Raw preview data
 * @return array Sanitized data
 */
function extrch_sanitize_preview_data( $data ) {
    $sanitized = array();

    // Sanitize text fields
    $text_fields = array( 'display_title', 'bio' );
    foreach ( $text_fields as $field ) {
        if ( isset( $data[ $field ] ) ) {
            $sanitized[ $field ] = sanitize_text_field( wp_unslash( $data[ $field ] ) );
        }
    }

    // Sanitize URLs
    $url_fields = array( 'profile_img_url', 'background_image_url' );
    foreach ( $url_fields as $field ) {
        if ( isset( $data[ $field ] ) ) {
            $sanitized[ $field ] = esc_url_raw( $data[ $field ] );
        }
    }

    // Sanitize color values
    $color_fields = array( 'background_color', 'text_color', 'button_color' );
    foreach ( $color_fields as $field ) {
        if ( isset( $data[ $field ] ) ) {
            $color = sanitize_hex_color( $data[ $field ] );
            if ( $color ) {
                $sanitized[ $field ] = $color;
            }
        }
    }

    // Sanitize arrays (links, social links)
    if ( isset( $data['links'] ) && is_array( $data['links'] ) ) {
        $sanitized['links'] = extrch_sanitize_links_array( $data['links'] );
    }

    if ( isset( $data['social_links'] ) && is_array( $data['social_links'] ) ) {
        $sanitized['social_links'] = extrch_sanitize_social_links_array( $data['social_links'] );
    }

    return $sanitized;
}

/**
 * Sanitize links array
 * 
 * @since 1.0.0
 * @param array $links Links array
 * @return array Sanitized links
 */
function extrch_sanitize_links_array( $links ) {
    $sanitized = array();

    foreach ( $links as $section ) {
        $sanitized_section = array();
        
        if ( isset( $section['section_title'] ) ) {
            $sanitized_section['section_title'] = sanitize_text_field( $section['section_title'] );
        }

        if ( isset( $section['links'] ) && is_array( $section['links'] ) ) {
            $sanitized_section['links'] = array();
            
            foreach ( $section['links'] as $link ) {
                if ( isset( $link['link_url'] ) && isset( $link['link_text'] ) ) {
                    $sanitized_section['links'][] = array(
                        'link_url' => esc_url_raw( $link['link_url'] ),
                        'link_text' => sanitize_text_field( $link['link_text'] ),
                        'link_is_active' => isset( $link['link_is_active'] ) ? (bool) $link['link_is_active'] : true
                    );
                }
            }
        }

        $sanitized[] = $sanitized_section;
    }

    return $sanitized;
}

/**
 * Sanitize social links array
 * 
 * @since 1.0.0
 * @param array $social_links Social links array
 * @return array Sanitized social links
 */
function extrch_sanitize_social_links_array( $social_links ) {
    $sanitized = array();

    foreach ( $social_links as $social_link ) {
        if ( isset( $social_link['url'] ) && isset( $social_link['type'] ) ) {
            $sanitized[] = array(
                'url' => esc_url_raw( $social_link['url'] ),
                'type' => sanitize_key( $social_link['type'] ),
                'icon' => isset( $social_link['icon'] ) ? sanitize_text_field( $social_link['icon'] ) : ''
            );
        }
    }

    return $sanitized;
}

/**
 * Generate CSS variables from preview data
 * 
 * @since 1.0.0
 * @param array $data Preview data
 * @return string CSS variables string
 */
function extrch_generate_css_variables( $data ) {
    $css_vars = array();

    // Default variables
    $defaults = array(
        '--link-page-background-color' => '#121212',
        '--link-page-text-color' => '#e5e5e5',
        '--link-page-button-bg-color' => '#0b5394',
        '--link-page-button-hover-bg-color' => '#53940b',
    );

    // Merge with custom variables
    if ( isset( $data['css_vars'] ) && is_array( $data['css_vars'] ) ) {
        $css_vars = array_merge( $defaults, $data['css_vars'] );
    } else {
        $css_vars = $defaults;
    }

    // Generate CSS string
    $css_string = ':root {';
    foreach ( $css_vars as $property => $value ) {
        if ( ! empty( $value ) ) {
            $css_string .= $property . ':' . esc_html( $value ) . ';';
        }
    }
    $css_string .= '}';

    return $css_string;
}

/**
 * Validate preview data fields
 * 
 * @since 1.0.0
 * @param array $data Data to validate
 * @return array Validation results
 */
function extrch_validate_preview_data( $data ) {
    $errors = array();

    // Validate required fields
    if ( empty( $data['display_title'] ) ) {
        $errors[] = __( 'Display title is required', 'extrachill-artist-platform' );
    }

    // Validate URLs
    $url_fields = array( 'profile_img_url', 'background_image_url' );
    foreach ( $url_fields as $field ) {
        if ( ! empty( $data[ $field ] ) && ! filter_var( $data[ $field ], FILTER_VALIDATE_URL ) ) {
            $errors[] = sprintf( __( 'Invalid URL for %s', 'extrachill-artist-platform' ), $field );
        }
    }

    // Validate colors
    if ( isset( $data['css_vars'] ) && is_array( $data['css_vars'] ) ) {
        foreach ( $data['css_vars'] as $property => $value ) {
            if ( strpos( $property, 'color' ) !== false && ! empty( $value ) ) {
                if ( ! preg_match( '/^#[a-fA-F0-9]{6}$/', $value ) ) {
                    $errors[] = sprintf( __( 'Invalid color value for %s', 'extrachill-artist-platform' ), $property );
                }
            }
        }
    }

    return array(
        'valid' => empty( $errors ),
        'errors' => $errors
    );
}

/**
 * Check if preview is ready
 * 
 * @since 1.0.0
 * @param int $link_page_id Link page ID
 * @return bool True if preview is ready
 */
function extrch_is_preview_ready( $link_page_id ) {
    if ( ! $link_page_id ) {
        return false;
    }

    // Check if required data exists
    $post = get_post( $link_page_id );
    if ( ! $post || $post->post_type !== 'artist_link_page' ) {
        return false;
    }

    // Check for associated artist profile
    $artist_id = get_post_meta( $link_page_id, '_associated_artist_profile_id', true );
    if ( ! $artist_id ) {
        return false;
    }

    return true;
}

/**
 * Get preview iframe HTML
 * 
 * @since 1.0.0
 * @param array $data Preview data
 * @return string Iframe HTML
 */
function extrch_get_preview_iframe_html( $data ) {
    ob_start();
    
    // Set query vars for preview template
    set_query_var( 'preview_template_data', $data );
    set_query_var( 'initial_container_style_for_php_preview', $data['background_style'] ?? '' );
    
    // Include preview template
    include EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/live-preview/preview.php';
    
    return ob_get_clean();
}