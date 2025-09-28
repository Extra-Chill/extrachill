<?php
/**
 * ExtraChill Artist Platform Fonts Manager
 * 
 * Centralized management system for all font functionality across the artist platform.
 * Provides single source of truth for fonts, font stack resolution, Google Font handling,
 * and JavaScript integration.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.1.0
 */

defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Fonts {

    /**
     * Single instance of the class
     * 
     * @var ExtraChillArtistPlatform_Fonts|null
     * @since 1.1.0
     */
    private static $instance = null;

    /**
     * Supported fonts configuration
     * 
     * @var array|null
     * @since 1.1.0
     */
    private $supported_fonts = null;

    /**
     * Default font values
     * 
     * @since 1.1.0
     */
    const DEFAULT_TITLE_FONT = 'WilcoLoftSans';
    const DEFAULT_BODY_FONT = 'Helvetica';
    const DEFAULT_FONT_STACK = "'Helvetica', Arial, sans-serif";

    /**
     * Get single instance
     * 
     * @since 1.1.0
     * @return ExtraChillArtistPlatform_Fonts The fonts manager instance
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     * 
     * @since 1.1.0
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     * 
     * @since 1.1.0
     */
    private function init_hooks() {
        // Register the font filter to provide fonts to the system
        add_filter( 'ec_artist_platform_fonts', array( $this, 'get_supported_fonts' ) );
        
        // Localize font data for JavaScript in admin
        add_action( 'admin_enqueue_scripts', array( $this, 'localize_font_data' ) );
    }

    /**
     * Get supported fonts configuration
     * 
     * @since 1.1.0
     * @return array Supported fonts with metadata
     */
    public function get_supported_fonts() {
        if ( null === $this->supported_fonts ) {
            // Define the default fonts - no filter recursion
            $this->supported_fonts = array(
                array(
                    'value' => 'Helvetica',
                    'label' => 'Helvetica',
                    'stack' => "'Helvetica', Arial, sans-serif",
                    'google_font_param' => 'local_default',
                ),
                array(
                    'value' => 'WilcoLoftSans',
                    'label' => 'Wilco Loft Sans',
                    'stack' => "'WilcoLoftSans', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'local_default',
                ),
                array(
                    'value' => 'Roboto',
                    'label' => 'Roboto',
                    'stack' => "'Roboto', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Roboto:wght@400;600;700',
                ),
                array(
                    'value' => 'Open Sans',
                    'label' => 'Open Sans',
                    'stack' => "'Open Sans', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Open+Sans:wght@400;600;700',
                ),
                array(
                    'value' => 'Lato',
                    'label' => 'Lato',
                    'stack' => "'Lato', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Lato:wght@400;600;700',
                ),
                array(
                    'value' => 'Montserrat',
                    'label' => 'Montserrat',
                    'stack' => "'Montserrat', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Montserrat:wght@400;600;700',
                ),
                array(
                    'value' => 'Oswald',
                    'label' => 'Oswald',
                    'stack' => "'Oswald', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Oswald:wght@400;600;700',
                ),
                array(
                    'value' => 'Raleway',
                    'label' => 'Raleway',
                    'stack' => "'Raleway', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Raleway:wght@400;600;700',
                ),
                array(
                    'value' => 'Poppins',
                    'label' => 'Poppins',
                    'stack' => "'Poppins', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Poppins:wght@400;600;700',
                ),
                array(
                    'value' => 'Nunito',
                    'label' => 'Nunito',
                    'stack' => "'Nunito', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Nunito:wght@400;600;700',
                ),
                array(
                    'value' => 'Caveat',
                    'label' => 'Caveat',
                    'stack' => "'Caveat', Helvetica, Arial, cursive",
                    'google_font_param' => 'Caveat:wght@400;600;700',
                ),
                array(
                    'value' => 'Space Grotesk',
                    'label' => 'Space Grotesk',
                    'stack' => "'Space Grotesk', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Space+Grotesk:wght@400;600;700',
                ),
                array(
                    'value' => 'Bebas Neue',
                    'label' => 'Bebas Neue',
                    'stack' => "'Bebas Neue', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Bebas+Neue:wght@400',
                ),
                array(
                    'value' => 'Inter',
                    'label' => 'Inter',
                    'stack' => "'Inter', Helvetica, Arial, sans-serif",
                    'google_font_param' => 'Inter:wght@400;600;700',
                ),
            );
        }

        return $this->supported_fonts;
    }

    /**
     * Get font stack by font value
     * 
     * @since 1.1.0
     * @param string $font_value Font value to lookup
     * @return string Font stack CSS value
     */
    public function get_font_stack( $font_value ) {
        $fonts = $this->get_supported_fonts();
        
        foreach ( $fonts as $font ) {
            if ( $font['value'] === $font_value ) {
                return $font['stack'];
            }
        }
        
        // Fallback: if not found and looks like a simple font name, wrap it
        if ( strpos( $font_value, ',' ) === false && 
             strpos( $font_value, "'" ) === false && 
             strpos( $font_value, '"' ) === false ) {
            return "'" . $font_value . "', " . self::DEFAULT_FONT_STACK;
        }
        
        return $font_value ?: self::DEFAULT_FONT_STACK;
    }

    /**
     * Get Google Font parameter by font value
     * 
     * @since 1.1.0
     * @param string $font_value Font value to lookup
     * @return string|null Google Font parameter or null if local font
     */
    public function get_google_font_param( $font_value ) {
        $fonts = $this->get_supported_fonts();
        
        foreach ( $fonts as $font ) {
            if ( $font['value'] === $font_value ) {
                return ( $font['google_font_param'] !== 'local_default' ) ? $font['google_font_param'] : null;
            }
        }
        
        return null;
    }

    /**
     * Get Google Fonts URL for multiple fonts
     * 
     * @since 1.1.0
     * @param array $font_values Array of font values
     * @return string Google Fonts URL or empty string
     */
    public function get_google_fonts_url( $font_values = array() ) {
        $google_fonts = array();
        
        foreach ( $font_values as $font_value ) {
            $param = $this->get_google_font_param( $font_value );
            if ( $param ) {
                $google_fonts[] = $param;
            }
        }
        
        if ( empty( $google_fonts ) ) {
            return '';
        }
        
        $unique_fonts = array_unique( $google_fonts );
        return 'https://fonts.googleapis.com/css2?family=' . implode( '&family=', $unique_fonts ) . '&display=swap';
    }

    /**
     * Process CSS variables for fonts
     * 
     * Resolves font values to proper CSS font stacks
     * 
     * @since 1.1.0
     * @param array $css_vars CSS variables array
     * @return array Processed CSS variables
     */
    public function process_font_css_vars( $css_vars ) {
        // Process title font
        if ( isset( $css_vars['--link-page-title-font-family'] ) ) {
            $css_vars['--link-page-title-font-family'] = $this->get_font_stack( 
                $css_vars['--link-page-title-font-family'] 
            );
        }
        
        // Process body font
        if ( isset( $css_vars['--link-page-body-font-family'] ) ) {
            $css_vars['--link-page-body-font-family'] = $this->get_font_stack( 
                $css_vars['--link-page-body-font-family'] 
            );
        }
        
        return $css_vars;
    }

    /**
     * Generate @font-face CSS for local fonts
     * 
     * Only generates @font-face definitions for selected local fonts (not Google Fonts).
     * Follows same dynamic pattern as Google Font loading.
     * 
     * @since 1.1.0
     * @param array $font_values Array of selected font values 
     * @return string CSS @font-face definitions for local fonts
     */
    public function get_local_fonts_css( $font_values ) {
        if ( empty( $font_values ) || ! is_array( $font_values ) ) {
            return '';
        }
        
        $local_fonts_css = '';
        $fonts = $this->get_supported_fonts();
        
        foreach ( $font_values as $font_value ) {
            // Skip empty values
            if ( empty( $font_value ) ) {
                continue;
            }
            
            // Find font in supported fonts list
            $font_config = null;
            foreach ( $fonts as $font ) {
                if ( $font['value'] === $font_value ) {
                    $font_config = $font;
                    break;
                }
            }
            
            // Only process local fonts (not Google Fonts)
            if ( $font_config && 
                 isset( $font_config['google_font_param'] ) && 
                 $font_config['google_font_param'] === 'local_default' ) {
                
                // Generate @font-face for specific local fonts
                if ( $font_value === 'WilcoLoftSans' ) {
                    $local_fonts_css .= $this->get_wilco_loft_sans_font_face();
                }
                // Add other local fonts here as needed
            }
        }
        
        return $local_fonts_css;
    }

    /**
     * Get @font-face definition for WilcoLoftSans
     * 
     * @since 1.1.0
     * @return string CSS @font-face definition
     */
    private function get_wilco_loft_sans_font_face() {
        $theme_url = get_template_directory_uri();
        
        return "@font-face {
    font-family: 'WilcoLoftSans';
    src: url('{$theme_url}/fonts/WilcoLoftSans/WilcoLoftSans-Treble.woff2') format('woff2'),
         url('{$theme_url}/fonts/WilcoLoftSans/WilcoLoftSans-Treble.woff') format('woff');
    font-weight: normal;
    font-style: normal;
    font-display: swap;
}\n";
    }

    /**
     * Localize font data for JavaScript
     * 
     * @since 1.1.0
     */
    public function localize_font_data() {
        global $pagenow;
        
        // Only localize on manage link page
        if ( 'post.php' === $pagenow || 'page.php' === $pagenow || 
             ( isset( $_GET['page'] ) && strpos( $_GET['page'], 'manage-link-page' ) !== false ) ||
             ( isset( $_GET['artist_id'] ) ) ) {
            
            // Localize to the fonts script handle
            wp_localize_script( 'extrachill-manage-link-page-fonts', 'extrchFontData', array(
                'fonts' => $this->get_supported_fonts(),
                'ajaxurl' => admin_url( 'admin-ajax.php' )
            ) );
            
        }
    }

    /**
     * Get default font configuration
     * 
     * @since 1.1.0
     * @return array Default CSS variables for fonts
     */
    public function get_default_font_css_vars() {
        return array(
            '--link-page-title-font-family' => $this->get_font_stack( self::DEFAULT_TITLE_FONT ),
            '--link-page-title-font-size' => '2.1em',
            '--link-page-body-font-family' => $this->get_font_stack( self::DEFAULT_BODY_FONT ),
            '--link-page-body-font-size' => '1em',
        );
    }
}