<?php
/**
 * ExtraChill Artist Platform Core Class
 * 
 * Handles core plugin functionality including CPTs, user linking, 
 * artist forums, and all artist platform features.
 *
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

class ExtraChillArtistPlatform_Core {

    /**
     * Single instance of the class
     * 
     * @var ExtraChillArtistPlatform_Core|null
     */
    private static $instance = null;

    /**
     * Get single instance
     * 
     * @return ExtraChillArtistPlatform_Core The core instance
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
     * Initializes hooks and loads platform includes.
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_artist_platform_includes();
    }

    /**
     * Initialize hooks
     * 
     * Sets up WordPress action and filter hooks for core functionality.
     */
    private function init_hooks() {
        // Post types are now registered in individual CPT files
        // add_action( 'init', array( $this, 'register_post_types' ), 5 );
        add_action( 'init', array( $this, 'add_rewrite_rules' ), 10 );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'wp_ajax_nopriv_link_page_click_tracking', array( $this, 'handle_link_click_tracking' ) );
        add_action( 'wp_ajax_link_page_click_tracking', array( $this, 'handle_link_click_tracking' ) );
        
        // Cross-domain session management is handled by theme functions
    }

    /**
     * Load all artist platform includes
     * 
     * Loads platform files and initializes session token functionality.
     */
    private function load_artist_platform_includes() {
        error_log('[DEBUG] ExtraChill Artist Platform Core: Loading platform includes');
        $plugin_dir = EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR;
        
        // Load artist platform files (will be moved here from theme)
        $includes = array(
            'artist-platform/cpt-artist-profile.php',
            'artist-platform/user-linking.php',
            'artist-platform/artist-forums.php',
            'artist-platform/artist-permissions.php',
            'artist-platform/frontend-forms.php',
            'artist-platform/artist-directory.php',
            'artist-platform/extrch.co-link-page/link-page-includes.php',
            'artist-platform/artist-forum-section-overrides.php',
            'artist-platform/data-sync.php',
            'artist-platform/default-artist-page-link-profiles.php',
            'artist-platform/subscribe/subscriber-db.php',
            'artist-platform/subscribe/subscribe-data-functions.php',
        );

        foreach ( $includes as $file ) {
            $filepath = $plugin_dir . $file;
            if ( file_exists( $filepath ) ) {
                error_log('[DEBUG] Including file: ' . $file);
                require_once $filepath;
            } else {
                error_log('[ERROR] File not found: ' . $filepath);
            }
        }

        // Cross-domain authentication is handled by theme functions in extrachill-integration/session-tokens.php
        error_log('[DEBUG] Session token functionality delegated to theme implementation');
    }

    /**
     * Register custom post types
     * 
     * Registers artist_profile and artist_link_page custom post types.
     */
    public function register_post_types() {
        
        // Register artist profile CPT
        $artist_profile_args = array(
            'labels' => array(
                'name' => __( 'Artist Profiles', 'extrachill-artist-platform' ),
                'singular_name' => __( 'Artist Profile', 'extrachill-artist-platform' ),
                'menu_name' => __( 'Artist Profiles', 'extrachill-artist-platform' ),
                'add_new' => __( 'Add New', 'extrachill-artist-platform' ),
                'add_new_item' => __( 'Add New Artist Profile', 'extrachill-artist-platform' ),
                'edit_item' => __( 'Edit Artist Profile', 'extrachill-artist-platform' ),
                'new_item' => __( 'New Artist Profile', 'extrachill-artist-platform' ),
                'view_item' => __( 'View Artist Profile', 'extrachill-artist-platform' ),
                'search_items' => __( 'Search Artist Profiles', 'extrachill-artist-platform' ),
                'not_found' => __( 'No artist profiles found', 'extrachill-artist-platform' ),
                'not_found_in_trash' => __( 'No artist profiles found in trash', 'extrachill-artist-platform' )
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array( 'slug' => 'artist' ),
            'capability_type' => 'post',
            'has_archive' => true,
            'hierarchical' => false,
            'menu_position' => 26,
            'menu_icon' => 'dashicons-groups',
            'supports' => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'author' ),
            'show_in_rest' => true,
        );
        register_post_type( 'artist_profile', $artist_profile_args );

        // Register artist link page CPT
        $link_page_args = array(
            'labels' => array(
                'name' => __( 'Link Pages', 'extrachill-artist-platform' ),
                'singular_name' => __( 'Link Page', 'extrachill-artist-platform' ),
                'menu_name' => __( 'Link Pages', 'extrachill-artist-platform' ),
                'add_new' => __( 'Add New', 'extrachill-artist-platform' ),
                'add_new_item' => __( 'Add New Link Page', 'extrachill-artist-platform' ),
                'edit_item' => __( 'Edit Link Page', 'extrachill-artist-platform' ),
                'new_item' => __( 'New Link Page', 'extrachill-artist-platform' ),
                'view_item' => __( 'View Link Page', 'extrachill-artist-platform' ),
                'search_items' => __( 'Search Link Pages', 'extrachill-artist-platform' ),
                'not_found' => __( 'No link pages found', 'extrachill-artist-platform' ),
                'not_found_in_trash' => __( 'No link pages found in trash', 'extrachill-artist-platform' )
            ),
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array( 'slug' => 'link-page' ),
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 27,
            'menu_icon' => 'dashicons-admin-links',
            'supports' => array( 'title', 'custom-fields', 'author' ),
            'show_in_rest' => true,
        );
        register_post_type( 'artist_link_page', $link_page_args );
    }

    /**
     * Add rewrite rules for link pages
     * 
     * Creates URL structure for artist link pages.
     */
    public function add_rewrite_rules() {
        // Link page rewrite rules
        add_rewrite_rule( '^([^/]+)/?$', 'index.php?artist_link_page=$matches[1]', 'top' );
        add_rewrite_tag( '%artist_link_page%', '([^&]+)' );
    }

    /**
     * Add custom query variables
     * 
     * @param array $vars Existing query variables
     * @return array Modified query variables
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'artist_link_page';
        $vars[] = 'dev_view_link_page';
        $vars[] = 'artist_id';
        return $vars;
    }

    /**
     * Handle link click tracking
     * 
     * AJAX handler for tracking clicks on artist link pages.
     */
    public function handle_link_click_tracking() {
        if ( ! isset( $_POST['link_page_id'] ) || ! isset( $_POST['link_url'] ) ) {
            wp_die( 'Invalid request', 'Error', array( 'response' => 400 ) );
        }

        global $wpdb;

        $link_page_id = absint( $_POST['link_page_id'] );
        $link_url = esc_url_raw( $_POST['link_url'] );
        $user_ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        $table_name = $wpdb->prefix . 'link_page_analytics';

        $wpdb->insert(
            $table_name,
            array(
                'link_page_id' => $link_page_id,
                'link_url' => $link_url,
                'user_ip' => $user_ip,
                'user_agent' => $user_agent,
                'referer' => $referer,
                'clicked_at' => current_time( 'mysql' )
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        wp_die( 'success' );
    }


}