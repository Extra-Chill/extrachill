<?php
/**
 * ExtraChill Artist Platform Templates Class
 * 
 * Handles template loading and overrides for artist platform functionality.
 * This ensures plugin templates take precedence over theme templates.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
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
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_filter( 'template_include', array( $this, 'load_artist_link_page_template' ), 99 );
        add_filter( 'page_template', array( $this, 'load_artist_platform_page_templates' ), 99 );
        add_action( 'template_redirect', array( $this, 'handle_artist_link_page_routing' ) );
        add_action( 'template_redirect', array( $this, 'setup_artist_platform_page_context' ) );
        
        // Register plugin templates with WordPress
        add_filter( 'theme_page_templates', array( $this, 'register_artist_platform_templates' ) );
    }

    /**
     * Load artist link page and artist profile templates
     */
    public function load_artist_link_page_template( $template ) {
        // Check if this is a artist_link_page post type
        if ( is_singular( 'artist_link_page' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/single-artist_link_page.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        // Check if this is a artist_profile post type (for forum integration)
        if ( is_singular( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/single-artist_profile.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        // Check if this is the artist_profile archive (use artist directory template)
        if ( is_post_type_archive( 'artist_profile' ) ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/page-templates/artist-directory.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        // Check for custom artist link page routing (short URLs)
        if ( $this->is_artist_link_page_request() ) {
            $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/single-artist_link_page.php';
            if ( file_exists( $plugin_template ) ) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Load artist platform page templates
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
            'manage-artist-profile.php',
            'manage-link-page.php',
            'artist-directory.php',
            'artist-platform-home.php'
        );

        // Check if this page uses a artist platform template
        foreach ( $artist_platform_templates as $artist_template ) {
            if ( $page_template === $artist_template ) {
                $plugin_template = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/page-templates/' . $artist_template;
                if ( file_exists( $plugin_template ) ) {
                    return $plugin_template;
                }
            }
        }

        return $template;
    }

    /**
     * Handle artist link page routing for short URLs
     */
    public function handle_artist_link_page_routing() {
        if ( ! $this->is_artist_link_page_request() ) {
            return;
        }

        global $wp_query;
        
        // Get the slug from the URL
        $slug = get_query_var( 'artist_link_page' );
        if ( ! $slug ) {
            // Fallback: get slug from request URI
            $request_uri = trim( $_SERVER['REQUEST_URI'], '/' );
            $slug = $request_uri;
        }

        if ( empty( $slug ) ) {
            return;
        }

        // Look for a artist_link_page with this slug
        $link_page = get_posts( array(
            'post_type' => 'artist_link_page',
            'name' => $slug,
            'post_status' => 'publish',
            'numberposts' => 1
        ) );

        if ( ! empty( $link_page ) ) {
            // Set up the query for this link page
            $wp_query->queried_object = $link_page[0];
            $wp_query->queried_object_id = $link_page[0]->ID;
            $wp_query->is_single = true;
            $wp_query->is_singular = true;
            $wp_query->is_404 = false;
            
            // Set global $post
            global $post;
            $post = $link_page[0];
            setup_postdata( $post );
        } else {
            // No link page found, let WordPress handle 404
            $wp_query->set_404();
        }
    }

    /**
     * Check if this is a artist link page request
     */
    private function is_artist_link_page_request() {
        // Check if we have the artist_link_page query var
        $artist_link_page = get_query_var( 'artist_link_page' );
        if ( ! empty( $artist_link_page ) ) {
            return true;
        }

        // Check if this looks like a short URL for a link page
        $request_uri = trim( $_SERVER['REQUEST_URI'], '/' );
        
        // Remove query string for clean comparison
        $request_path = strtok( $request_uri, '?' );
        
        // Skip if this is clearly not a link page (has multiple path segments, file extensions, etc.)
        if ( 
            strpos( $request_path, '/' ) !== false ||
            strpos( $request_path, '.' ) !== false ||
            strpos( $request_path, 'wp-' ) === 0 ||
            $request_path === 'wp-admin' ||
            empty( $request_path )
        ) {
            return false;
        }

        // Exclude known WordPress page templates and admin pages
        $excluded_pages = array(
            'manage-artist-profiles',
            'manage-link-page', 
            'artist-directory',
            'artists',
            'settings',
            'notifications',
            'login',
            'register',
            'wp-login',
            'wp-admin',
            'admin',
            'dashboard'
        );

        if ( in_array( $request_path, $excluded_pages ) ) {
            return false;
        }

        // Only return true if this could be a legitimate artist link page slug
        // Additional safety check: only if we're not on a WordPress page
        if ( is_page() || is_admin() ) {
            return false;
        }

        return true;
    }

    /**
     * Get template part with plugin fallback
     */
    public static function get_template_part( $slug, $name = null, $args = array() ) {
        $templates = array();
        
        if ( isset( $name ) ) {
            $templates[] = "{$slug}-{$name}.php";
        }
        $templates[] = "{$slug}.php";

        // Look for template in plugin first
        $plugin_template = null;
        foreach ( $templates as $template ) {
            $plugin_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/' . $template;
            if ( file_exists( $plugin_path ) ) {
                $plugin_template = $plugin_path;
                break;
            }
        }

        if ( $plugin_template ) {
            // Extract args if provided
            if ( ! empty( $args ) ) {
                extract( $args );
            }
            
            include $plugin_template;
            return;
        }

        // Fallback to theme template
        get_template_part( $slug, $name, $args );
    }

    /**
     * Load template with args
     */
    public static function load_template( $template_name, $args = array() ) {
        $template_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/' . $template_name;
        
        if ( file_exists( $template_path ) ) {
            if ( ! empty( $args ) ) {
                extract( $args );
            }
            include $template_path;
            return true;
        }

        return false;
    }

    /**
     * Register artist platform templates with WordPress
     * This makes them appear in the page template dropdown in admin
     */
    public function register_artist_platform_templates( $templates ) {
        $artist_platform_templates = array(
            'manage-artist-profile.php' => __( 'Manage Artist Profile', 'extrachill-artist-platform' ),
            'manage-link-page.php'    => __( 'Manage Link Page', 'extrachill-artist-platform' ),
            'artist-directory.php'      => __( 'Artist Directory', 'extrachill-artist-platform' ),
            'artist-platform-home.php' => __( 'Artist Platform Home', 'extrachill-artist-platform' ),
        );

        // Only add templates if they exist in the plugin
        foreach ( $artist_platform_templates as $template_file => $template_name ) {
            $template_path = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'templates/page-templates/' . $template_file;
            if ( file_exists( $template_path ) ) {
                $templates[ $template_file ] = $template_name;
            }
        }

        return $templates;
    }

    /**
     * Setup proper query context for artist platform page templates
     * 
     * Ensures pages assigned to artist platform templates have proper post data
     * and query context established to prevent "invalid query" errors.
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
            'manage-artist-profile.php',
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
                
                // Log for debugging
                error_log('[DEBUG] Artist Platform: Set up query context for page ID ' . $post->ID . ' with template ' . $page_template);
            }
        }
    }
}