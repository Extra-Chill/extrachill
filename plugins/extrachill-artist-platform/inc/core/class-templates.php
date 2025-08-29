<?php
/**
 * ExtraChill Artist Platform Templates Class
 * 
 * Handles template loading and overrides for artist platform functionality.
 * Plugin templates take precedence over theme templates.
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Templates {

    /**
     * Single instance of the class
     */
    private static $instance = null;

    /**
     * Get single instance
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize hooks
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_filter( 'template_include', array( $this, 'load_artist_link_page_template' ), 10 );
        add_filter( 'page_template', array( $this, 'load_artist_platform_page_templates' ), 99 );
        add_action( 'template_redirect', array( $this, 'setup_artist_platform_page_context' ) );
        
        // Register plugin templates with WordPress
        add_filter( 'theme_page_templates', array( $this, 'register_artist_platform_templates' ) );
    }

    /**
     * Load artist link page and artist profile templates
     * 
     * Overrides single and archive templates for custom post types.
     */
    public function load_artist_link_page_template( $template ) {
        // Check if this is a artist_link_page post type
        if ( is_singular( 'artist_link_page' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/templates/single-artist_link_page.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        // Check if this is a artist_profile post type (for forum integration)
        if ( is_singular( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/single-artist_profile.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        // Check if this is the artist_profile archive
        if ( is_post_type_archive( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/templates/archive-artist_profile.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Load artist platform page templates
     * 
     * Overrides page templates for artist platform management pages.
     */
    public function load_artist_platform_page_templates( $template ) {
        global $post;

        if ( ! $post || ! is_page() ) {
            return $template;
        }

        // Get the page template
        $page_template = get_page_template_slug( $post );

        // Artist platform page templates to override
        $artist_platform_templates = array(
            'manage-artist-profiles.php',
            'manage-link-page.php',
            'artist-directory.php',
            'artist-platform-home.php'
        );

        // Check if this page uses a artist platform template
        foreach ( $artist_platform_templates as $artist_template ) {
            if ( $page_template === $artist_template ) {
                // Map template files to their new locations
                $template_map = array(
                    'manage-artist-profiles.php' => 'inc/artist-profiles/frontend/templates/manage-artist-profiles.php',
                    'manage-link-page.php' => 'inc/link-pages/management/templates/manage-link-page.php',
                    'artist-directory.php' => 'inc/artist-profiles/frontend/templates/artist-directory.php',
                    'artist-platform-home.php' => 'inc/artist-profiles/frontend/templates/artist-platform-home.php'
                );
                
                if ( isset( $template_map[ $artist_template ] ) ) {
                    $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $template_map[ $artist_template ];
                    if ( file_exists( $plugin_template ) ) {
                        return $plugin_template;
                    }
                }
            }
        }

        return $template;
    }


    /**
     * Get template part with plugin fallback
     * 
     * Searches plugin template directories before falling back to theme.
     */
    public static function get_template_part( $slug, $name = null, $args = array() ) {
        $templates = array();
        
        if ( isset( $name ) ) {
            $templates[] = "{$slug}-{$name}.php";
        }
        $templates[] = "{$slug}.php";

        // Look for template in plugin first - check multiple locations
        $plugin_template = null;
        $template_dirs = array(
            'inc/artist-profiles/frontend/templates/',
            'inc/link-pages/live/templates/',
            'inc/link-pages/management/templates/'
        );
        
        foreach ( $templates as $template ) {
            foreach ( $template_dirs as $dir ) {
                $plugin_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $dir . $template;
                if ( file_exists( $plugin_path ) ) {
                    $plugin_template = $plugin_path;
                    break 2;
                }
            }
        }

        if ( $plugin_template ) {
            // Make args available to template scope
            if ( ! empty( $args ) ) {
                // Create variables directly in local scope
                foreach ( $args as $key => $value ) {
                    ${$key} = $value;
                }
            }
            
            include $plugin_template;
            return;
        }

        // Fallback to theme template
        get_template_part( $slug, $name, $args );
    }

    /**
     * Load template with args
     * 
     * Searches multiple plugin template directories and extracts args.
     */
    public static function load_template( $template_name, $args = array() ) {
        // Check multiple template directories
        $template_dirs = array(
            'inc/artist-profiles/frontend/templates/',
            'inc/link-pages/live/templates/',
            'inc/link-pages/management/templates/'
        );
        
        foreach ( $template_dirs as $dir ) {
            $template_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . $dir . $template_name;
            if ( file_exists( $template_path ) ) {
                if ( ! empty( $args ) ) {
                    // Create variables directly in local scope
                    foreach ( $args as $key => $value ) {
                        ${$key} = $value;
                    }
                }
                include $template_path;
                return true;
            }
        }

        return false;
    }

    /**
     * Load artist profile card component
     * 
     * @param int $artist_id Required. The artist profile post ID
     * @param string $context Optional. Context: 'user-profile', 'dashboard', 'directory'
     */
    public static function load_artist_profile_card( $artist_id, $context = 'default' ) {
        if ( ! $artist_id ) {
            return false;
        }

        return self::load_template( 'artist-profile-card.php', array(
            'artist_id' => $artist_id,
            'context' => $context
        ) );
    }

    /**
     * Register artist platform templates with WordPress
     * 
     * Makes them appear in the page template dropdown in admin.
     */
    public function register_artist_platform_templates( $templates ) {
        $artist_platform_templates = array(
            'manage-artist-profiles.php' => __( 'Manage Artist Profile', 'extrachill-artist-platform' ),
            'manage-link-page.php'    => __( 'Manage Link Page', 'extrachill-artist-platform' ),
            'artist-directory.php'      => __( 'Artist Directory', 'extrachill-artist-platform' ),
            'artist-platform-home.php' => __( 'Artist Platform Home', 'extrachill-artist-platform' ),
        );

        // Only add templates if they exist in the plugin - check new locations
        $template_map = array(
            'manage-artist-profiles.php' => 'inc/artist-profiles/frontend/templates/manage-artist-profiles.php',
            'manage-link-page.php' => 'inc/link-pages/management/templates/manage-link-page.php',
            'artist-directory.php' => 'inc/artist-profiles/frontend/templates/artist-directory.php',
            'artist-platform-home.php' => 'inc/artist-profiles/frontend/templates/artist-platform-home.php'
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
     * and query context for artist platform templates.
     */
    public function setup_artist_platform_page_context() {
        global $post, $wp_query;

        // Only run on frontend page views
        if ( is_admin() || ! is_page() ) {
            return;
        }

        // Check if this page is using a artist platform template
        $page_template = get_page_template_slug( $post );
        
        $artist_platform_templates = array(
            'manage-artist-profiles.php',
            'manage-link-page.php', 
            'artist-directory.php',
            'artist-platform-home.php'
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