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
     * Sets up filters for custom template loading and registration.
     */
    private function init_hooks() {
        add_filter( 'template_include', array( $this, 'load_artist_link_page_template' ), 10 );
        add_filter( 'extrachill_template_page', array( $this, 'load_artist_platform_page_templates' ), 10 );
        add_action( 'template_redirect', array( $this, 'setup_artist_platform_page_context' ) );

        // Register plugin templates with WordPress
        add_filter( 'theme_page_templates', array( $this, 'register_artist_platform_templates' ) );
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
     * Load artist platform page templates
     *
     * Integrates with theme's template router to serve plugin templates.
     * Hooks into extrachill_template_page filter provided by theme's routing system.
     *
     * @param string $template Current template path from theme router
     * @return string Modified template path
     */
    public function load_artist_platform_page_templates( $template ) {
        global $post;

        if ( ! $post || ! is_page() ) {
            return $template;
        }

        $page_template = get_page_template_slug( $post );

        $template_map = array(
            'manage-artist-profiles.php' => 'inc/artist-profiles/frontend/templates/manage-artist-profiles.php',
            'manage-link-page.php'       => 'inc/link-pages/management/templates/manage-link-page.php',
            'artist-directory.php'       => 'inc/artist-profiles/frontend/templates/artist-directory.php'
        );

        if ( isset( $template_map[ $page_template ] ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $template_map[ $page_template ];
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Register artist platform templates with WordPress
     * 
     * Makes them appear in the page template dropdown in admin.
     * Only registers templates that actually exist in the plugin directory.
     * 
     * @param array $templates Existing page templates
     * @return array Modified templates array
     */
    public function register_artist_platform_templates( $templates ) {
        $artist_platform_templates = array(
            'manage-artist-profiles.php' => __( 'Manage Artist Profile', 'extrachill-artist-platform' ),
            'manage-link-page.php'    => __( 'Manage Link Page', 'extrachill-artist-platform' ),
            'artist-directory.php'      => __( 'Artist Directory', 'extrachill-artist-platform' )
        );

        // Only add templates if they exist in the plugin - check new locations
        $template_map = array(
            'manage-artist-profiles.php' => 'inc/artist-profiles/frontend/templates/manage-artist-profiles.php',
            'manage-link-page.php' => 'inc/link-pages/management/templates/manage-link-page.php',
            'artist-directory.php' => 'inc/artist-profiles/frontend/templates/artist-directory.php'
        );
        
        foreach ( $artist_platform_templates as $template_file => $template_name ) {
            if ( isset( $template_map[ $template_file ] ) ) {
                $template_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $template_map[ $template_file ];
                if ( file_exists( $template_path ) ) {
                    $templates[ $template_file ] = $template_name;
                }
            }
        }

        return $templates;
    }

    /**
     * Setup proper query context for artist platform page templates
     * 
     * Prevents "invalid query" errors by establishing proper post data
     * and query context for artist platform templates. Ensures WordPress
     * knows these are valid page requests.
     * 
     * @return void
     */
    public function setup_artist_platform_page_context() {
        global $post, $wp_query;

        // Only run on frontend page views
        if ( is_admin() || ! is_page() || is_archive() || is_post_type_archive() ) {
            return;
        }

        // Check if this page is using a artist platform template
        $page_template = get_page_template_slug( $post );
        
        $artist_platform_templates = array(
            'manage-artist-profiles.php',
            'manage-link-page.php',
            'artist-directory.php'
        );

        // If this page uses a artist platform template, ensure proper context
        if ( in_array( $page_template, $artist_platform_templates ) ) {
            // Ensure the post is properly set up in the global query
            if ( $post && $post->ID ) {
                $wp_query->queried_object = $post;
                $wp_query->queried_object_id = $post->ID;
                $wp_query->is_page = true;
                $wp_query->is_singular = true;
                $wp_query->is_404 = false;
                
                // Set up post data properly
                setup_postdata( $post );
            }
        }
    }
}