<?php
/**
 * Plugin Name: ExtraChill Artist Platform
 * Plugin URI: https://extrachill.com
 * Description: Comprehensive artist platform for musicians. Features artist profiles, link pages with analytics, subscriber management, cross-domain authentication, forum integration, and live preview management interface.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-artist-platform
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// Define plugin constants
define( 'EXTRACHILL_ARTIST_PLATFORM_VERSION', '1.0.0' );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_ARTIST_PLATFORM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Development mode constant for link page testing
if ( ! defined( 'EXTRCH_LINKPAGE_DEV' ) ) {
    define( 'EXTRCH_LINKPAGE_DEV', false );
}

/**
 * Main ExtraChill Artist Platform Class
 * 
 * Singleton plugin class handling initialization and core functionality loading.
 */
class ExtraChillArtistPlatform {

    /**
     * Single instance of the plugin
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
     * Check plugin dependencies
     * 
     * @return bool True if all dependencies are met, false otherwise
     */
    private function check_dependencies() {
        $errors = array();
        
        // Check for required theme
        if ( get_template() !== 'extrachill-community' ) {
            $errors[] = 'ExtraChill Artist Platform requires the Extra Chill Community theme.';
        }
        
        // Check for bbPress plugin
        if ( ! class_exists( 'bbPress' ) && ! is_plugin_active( 'bbpress/bbpress.php' ) ) {
            $errors[] = 'ExtraChill Artist Platform requires bbPress plugin to be active.';
        }
        
        if ( ! empty( $errors ) ) {
            add_action( 'admin_notices', function() use ( $errors ) {
                foreach ( $errors as $error ) {
                    echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
                }
            } );
            return false;
        }
        
        return true;
    }

    /**
     * Initialize hooks and actions
     */
    private function init_hooks() {
        // Check dependencies before initializing
        if ( ! $this->check_dependencies() ) {
            return;
        }
        
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        
        // Activation and deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Load plugin includes
        $this->load_includes();
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain( 
            'extrachill-artist-platform', 
            false, 
            dirname( EXTRACHILL_ARTIST_PLATFORM_PLUGIN_BASENAME ) . '/languages' 
        );
    }



    /**
     * Load plugin includes and functionality
     * 
     * Loads core classes and initializes plugin instances.
     */
    private function load_includes() {
        // Core System Files
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/class-templates.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/artist-platform-assets.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/artist-platform-migration.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/artist-platform-post-types.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/artist-platform-rewrite-rules.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/defaults.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/social-icons.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/fonts.php';
        // data-sync.php removed - now using unified sync system in inc/core/actions/sync.php
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/default-artist-page-link-profiles.php';

        // Artist Profile System
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/admin/user-linking.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/admin/meta-boxes.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/artist-forums.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/permissions.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/artist-following.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/artist-forum-section-overrides.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/frontend-forms.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/frontend/artist-directory.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/subscribe-data-functions.php';

        // Link Page System
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/create-link-page.php';
        // link-page-custom-vars-and-fonts-head.php consolidated into inc/link-pages/live/link-page-head.php
        // LinkPageDataProvider.php removed - using centralized ec_get_link_page_data filter instead
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/link-page-analytics-tracking.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/link-page-session-validation.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/live/link-page-head.php';
        
        // Link Page Management
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/background-image-ajax.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/link-page-qrcode-ajax.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/ajax-handlers.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/live-preview/class-live-preview-handler.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/live-preview/live-preview-ajax-handlers.php';
        
        // Advanced Features
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/link-page-featured-link-handler.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/link-expiration.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/temporary-redirect.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/youtube-embed-control.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/subscription-settings.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/meta-pixel-tracking.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/google-tag-tracking.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/management/advanced-tab/link-page-weekly-email.php';

        // Subscription System
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/subscribe-functions.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/subscription/subscribe-inline-form.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/link-pages/subscription/subscribe-modal.php';

        // Roster Management System  
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/roster/manage-roster-ui.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/artist-profiles/roster/roster-ajax-handlers.php';

        // Database Setup
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/database/subscriber-db.php';

        // Action System
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/actions/save.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/actions/sync.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/actions/ajax.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/actions/delete.php';
        
        // Data Helper Functions
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'inc/core/filters/data.php';

        // Initialize core instances
        ExtraChillArtistPlatform_Templates::instance();
        ExtraChillArtistPlatform_Assets::instance();
        ExtraChillArtistPlatform_SocialLinks::instance();
        ExtraChillArtistPlatform_Fonts::instance();
        ExtraChillArtistPlatform_Migration::instance();

        // Initialize database tables
        add_action('after_switch_theme', 'extrch_create_subscribers_table');
    }

    /**
     * Plugin activation
     * 
     * Flushes rewrite rules and sets activation flag.
     */
    public static function activate() {
        // Flush rewrite rules to register custom rewrite rules
        // Note: CPTs are registered via init hook, not during activation
        flush_rewrite_rules();
        
        // Set plugin activation flag
        update_option( 'extrachill_artist_platform_activated', true );
    }

    /**
     * Plugin deactivation
     * 
     * Flushes rewrite rules and removes activation flag.
     */
    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Remove activation flag
        delete_option( 'extrachill_artist_platform_activated' );
    }

}

// Initialize the plugin
function extrachill_artist_platform() {
    return ExtraChillArtistPlatform::instance();
}

// Start the plugin
extrachill_artist_platform();

// Register activation and deactivation hooks
register_activation_hook( __FILE__, array( 'ExtraChillArtistPlatform', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ExtraChillArtistPlatform', 'deactivate' ) );