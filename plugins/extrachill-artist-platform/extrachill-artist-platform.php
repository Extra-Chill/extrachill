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

/**
 * Main ExtraChill Artist Platform Class
 * 
 * Primary plugin class that handles initialization, theme compatibility,
 * and loading of all core functionality.
 * 
 * @package ExtraChillArtistPlatform
 * @since 1.0.0
 */
class ExtraChillArtistPlatform {

    /**
     * Single instance of the plugin
     * 
     * @var ExtraChillArtistPlatform|null
     */
    private static $instance = null;

    /**
     * Get single instance
     * 
     * @return ExtraChillArtistPlatform The plugin instance
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
     * Initialize hooks and actions
     */
    private function init_hooks() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        
        // Activation and deactivation hooks
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
    }

    /**
     * Initialize the plugin
     * 
     * Checks theme compatibility and loads core functionality if requirements are met.
     */
    public function init() {
        error_log('[DEBUG] ExtraChill Artist Platform: init() called');
        // Check for required theme
        if ( ! $this->is_compatible_theme_active() ) {
            error_log('[DEBUG] ExtraChill Artist Platform: Theme compatibility failed');
            add_action( 'admin_notices', array( $this, 'theme_compatibility_notice' ) );
            return;
        }

        error_log('[DEBUG] ExtraChill Artist Platform: Theme compatible, loading includes');
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
     * Check if compatible theme is active
     * 
     * @return bool True if a compatible theme is active, false otherwise
     */
    private function is_compatible_theme_active() {
        $theme = wp_get_theme();
        $compatible_themes = array(
            'Extra Chill Community',
            'extrachill-community'
        );
        
        return in_array( $theme->get('Name'), $compatible_themes ) || 
               in_array( $theme->get_stylesheet(), $compatible_themes );
    }

    /**
     * Show theme compatibility notice
     */
    public function theme_compatibility_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <?php esc_html_e( 'ExtraChill Artist Platform requires the Extra Chill Community theme to be active.', 'extrachill-artist-platform' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Load plugin includes and functionality
     * 
     * Loads all core classes and initializes plugin instances.
     */
    private function load_includes() {
        // Load core includes
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'includes/class-core.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'includes/class-templates.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'includes/class-assets.php';
        require_once EXTRACHILL_ARTIST_PLATFORM_PLUGIN_DIR . 'includes/class-migration.php';
        
        // Initialize core functionality
        ExtraChillArtistPlatform_Core::instance();
        ExtraChillArtistPlatform_Templates::instance();
        ExtraChillArtistPlatform_Assets::instance();
        
        // Initialize migration system (will check if migration is needed)
        ExtraChillArtistPlatform_Migration::instance();
    }

    /**
     * Plugin activation
     * 
     * Flushes rewrite rules and sets activation flag.
     */
    public static function activate() {
        // Flush rewrite rules to register custom rewrite rules
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