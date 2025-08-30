<?php
/**
 * Link Page Defaults Filter
 *
 * Centralizes all link page default values using WordPress filters for extensibility.
 * Replaces scattered hardcoded defaults throughout the codebase.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get link page defaults via WordPress filter
 *
 * @return array Structured array of all link page defaults
 */
function ec_get_link_page_defaults() {
    $defaults = array(
        'styles' => array(
            // Colors - Dark theme inspired
            '--link-page-background-color'              => '#121212',
            '--link-page-card-bg-color'                 => 'rgba(0, 0, 0, 0.4)',
            '--link-page-text-color'                    => '#e5e5e5',
            '--link-page-link-text-color'               => '#ffffff',
            '--link-page-button-bg-color'               => '#0b5394',
            '--link-page-button-border-color'           => '#0b5394',
            '--link-page-button-hover-bg-color'         => '#53940b',
            '--link-page-button-hover-text-color'       => '#ffffff',
            '--link-page-muted-text-color'              => '#aaa',
            '--link-page-overlay-color'                 => 'rgba(0, 0, 0, 0.5)',
            '--link-page-input-bg'                      => '#181818',
            '--link-page-accent'                        => '#888',
            '--link-page-accent-hover'                  => '#222',
            
            // Background settings
            '--link-page-background-type'               => 'color',
            '--link-page-background-gradient-start'     => '#0b5394',
            '--link-page-background-gradient-end'       => '#53940b',
            '--link-page-background-gradient-direction' => 'to right',
            '--link-page-background-image-url'          => '',
            '--link-page-image-size'                    => 'cover',
            '--link-page-image-position'                => 'center center',
            '--link-page-image-repeat'                  => 'no-repeat',
            'overlay'                                   => '1',
            
            // Typography
            '--link-page-title-font-family'             => 'WilcoLoftSans',
            '--link-page-title-font-size'               => '2.1em',
            '--link-page-body-font-family'              => 'Helvetica',
            '--link-page-body-font-size'                => '1em',
            
            // Button styling
            '--link-page-button-radius'                 => '8px',
            '--link-page-button-border-width'           => '0px',
            
            // Profile image settings
            '--link-page-profile-img-size'              => '30%',
            '--link-page-profile-img-border-radius'     => '50%',
            '--link-page-profile-img-aspect-ratio'      => '1/1',
            '_link_page_profile_img_shape'              => 'circle',
        ),
        
        'settings' => array(
            'subscribe_display_mode'    => 'icon_modal',
            'social_icons_position'     => 'above',
            'background_type'           => 'color',
            'background_color'          => '#1a1a1a',
            'profile_img_slider_value'  => 50,
        ),
        
        'links' => array(
            'create_default_section' => true,
            'section_title'         => '',
            'link_text_template'    => '%artist_name% Forum',
            'link_url_template'     => '/artist/%artist_slug%',
            'link_is_active'        => true,
        ),
        
        'subscription' => array(
            'default_mode'        => 'icon_modal',
            'description_template' => __( 'Enter your email address to receive occasional news and updates from %s.', 'extrachill-artist-platform' ),
            'fallback_artist'     => __( 'this band', 'extrachill-artist-platform' ),
        ),
    );
    
    /**
     * Filter link page defaults
     *
     * Allows themes and plugins to customize all link page default values.
     *
     * @param array $defaults All link page defaults structured by category
     */
    return apply_filters( 'ec_link_page_defaults', $defaults );
}

/**
 * Get specific category of defaults
 *
 * @param string $category Category of defaults to retrieve
 * @return array|mixed Defaults for the specified category
 */
function ec_get_link_page_defaults_for( $category ) {
    $defaults = ec_get_link_page_defaults();
    return isset( $defaults[ $category ] ) ? $defaults[ $category ] : array();
}

/**
 * Get a specific default value
 *
 * @param string $category Category of the default
 * @param string $key      Key of the default value
 * @param mixed  $fallback Fallback value if not found
 * @return mixed The default value or fallback
 */
function ec_get_link_page_default( $category, $key, $fallback = null ) {
    $defaults = ec_get_link_page_defaults_for( $category );
    return isset( $defaults[ $key ] ) ? $defaults[ $key ] : $fallback;
}