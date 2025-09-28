<?php
/**
 * Plugin Name: ExtraChill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar and event plugin integrations for the ExtraChill ecosystem. Provides seamless integration between ExtraChill themes and popular event plugins (DM Events, Tribe Events) with unified styling and functionality.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillEvents
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EXTRACHILL_EVENTS_VERSION', '1.0.0');
define('EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__);

/**
 * Main ExtraChill Events Plugin Class
 *
 * Handles plugin initialization, autoloading, and integration management
 */
class ExtraChillEvents {

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * Array of loaded integrations
     */
    private $integrations = array();

    /**
     * Get single instance of the plugin
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor - Initialize the plugin
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->init_integrations();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('extrachill-events', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        // Composer autoloader
        $autoload_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoload_file)) {
            require_once $autoload_file;
        }

        // Manual includes for classes without autoloader
        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'includes/class-dm-events-integration.php';
    }

    /**
     * Initialize event plugin integrations based on active plugins
     */
    private function init_integrations() {
        // DM Events Integration
        if (class_exists('DmEvents\Core\Taxonomy_Badges')) {
            $this->integrations['dm_events'] = new ExtraChillEvents\DmEventsIntegration();
        }

        // Future integrations can be added here
        // Tribe Events, Event Calendar, etc.
    }

    /**
     * Plugin activation hook
     */
    public function activate() {
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Cleanup if needed
        flush_rewrite_rules();
    }

    /**
     * Get loaded integrations
     */
    public function get_integrations() {
        return $this->integrations;
    }
}

/**
 * Initialize the plugin
 */
function extrachill_events() {
    return ExtraChillEvents::get_instance();
}

// Initialize plugin
extrachill_events();