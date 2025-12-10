<?php
/**
 * WordPress template routing for artist platform post types with plugin override support
 */


defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_PageTemplates {

    /** @var ExtraChillArtistPlatform_PageTemplates|null */
    private static $instance = null;

    /** @return ExtraChillArtistPlatform_PageTemplates */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Sets up template filtering.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize template-related WordPress hooks
     *
     * Sets up filters for custom template loading for post types.
     */
    private function init_hooks() {
        add_filter( 'template_include', array( $this, 'load_artist_link_page_template' ), 10 );
        add_filter( 'extrachill_template_archive', array( $this, 'load_artist_profile_archive_template' ), 10 );
    }

    /**
     * Load artist link page and artist profile templates
     * 
     * Overrides single and archive templates for custom post types.
     * Handles both artist_link_page and artist_profile post type templates.
     * 
     * @param string $template Current template path
     * @return string Modified template path
     */
    public function load_artist_link_page_template( $template ) {
        if ( is_singular( 'artist_link_page' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/templates/single-artist_link_page.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        if ( is_singular( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/single-artist_profile.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Load artist profile archive template
     *
     * Hooks into theme's extrachill_template_archive filter to provide
     * custom archive template for artist_profile post type.
     *
     * @param string $template Current template path from theme router
     * @return string Modified template path
     */
    public function load_artist_profile_archive_template( $template ) {
        if ( is_post_type_archive( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/archive-artist_profile.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }
        return $template;
    }
}