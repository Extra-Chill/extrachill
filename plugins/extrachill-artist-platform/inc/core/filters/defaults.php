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
            // Body font size removed - uses theme default font size
            
            // Button styling
            '--link-page-button-radius'                 => '8px',
            '--link-page-button-border-width'           => '0px',
            
            // Profile image settings
            '--link-page-profile-img-size'              => '30%',
            '_link_page_profile_img_shape'              => 'circle',
        ),
        
        'settings' => array(
            'subscribe_display_mode'    => 'icon_modal',
            'social_icons_position'     => 'above',
            'background_type'           => 'color',
            'background_color'          => '#1a1a1a',
            'profile_img_slider_value'  => 50,
        ),
        
        
        'subscription' => array(
            'default_mode'        => 'icon_modal',
            'description_template' => __( 'Enter your email address to receive occasional news and updates from %s.', 'extrachill-artist-platform' ),
            'fallback_artist'     => __( 'this artist', 'extrachill-artist-platform' ),
        ),
    );
    
    /**
     * Filters the complete set of link page default values.
     *
     * This filter allows themes and plugins to customize all default values
     * used throughout the link page system. The defaults are organized by
     * category (styles, settings, links, subscription) for easy modification.
     *
     * @since 1.0.0
     *
     * @param array $defaults {
     *     Complete array of link page defaults organized by category.
     *
     *     @type array $styles {
     *         CSS variable defaults and visual styling defaults.
     *
     *         @type string $--link-page-background-color        Default background color.
     *         @type string $--link-page-text-color              Default text color.
     *         @type string $--link-page-button-bg-color         Default button background.
     *         @type string $--link-page-title-font-family       Default title font family.
     *         @type string $--link-page-title-font-size         Default title font size.
     *         @type string $--link-page-profile-img-size        Default profile image size.
     *         ... Additional CSS variables for complete theming control.
     *     }
     *     @type array $settings {
     *         Functional settings and display mode defaults.
     *
     *         @type string $subscribe_display_mode    Default subscription display mode.
     *         @type string $social_icons_position     Default social icons placement.
     *         @type string $background_type           Default background type.
     *         @type string $background_color          Default background color value.
     *         @type int    $profile_img_slider_value  Default profile image size slider value.
     *     }
     *     @type array $subscription {
     *         Email subscription form defaults.
     *
     *         @type string $default_mode        Default subscription collection mode.
     *         @type string $description_template Default description with placeholder support.
     *         @type string $fallback_artist      Fallback text when artist name unavailable.
     *     }
     * }
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