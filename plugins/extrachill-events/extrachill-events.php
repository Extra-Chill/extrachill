<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar integration with template overrides, datamachine-events badge/button styling, breadcrumb system, and related events for events.extrachill.com.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: datamachine, datamachine-events
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

define('EXTRACHILL_EVENTS_VERSION', '1.0.0');
define('EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__);

/**
 * ExtraChillEvents
 *
 * Singleton class managing datamachine-events integration with homepage/archive template
 * overrides, badge/button styling, breadcrumb system, and SEO redirects for
 * events.extrachill.com (blog ID 7).
 *
 * @since 1.0.0
 */
class ExtraChillEvents {

    private static $instance = null;
    private $integrations = array();

    /**
     * Get singleton instance
     *
     * @return ExtraChillEvents
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        $this->init_integrations();
    }

    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    public function load_textdomain() {
        load_plugin_textdomain('extrachill-events', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    /**
     * Load plugin dependencies via direct includes
     *
     * Composer autoloader exists for development dependencies only.
     * All plugin code uses direct require_once includes.
     */
    private function load_dependencies() {
        $autoload_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoload_file)) {
            require_once $autoload_file;
        }

        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-events-integration.php';
        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/breadcrumb-integration.php';
    }

    /**
     * Initialize event plugin integrations
     *
     * Conditionally loads DataMachineEventsIntegration if datamachine-events plugin is active.
     */
    private function init_integrations() {
        if (class_exists('DataMachineEvents\Core\Taxonomy_Badges')) {
            $this->integrations['datamachine_events'] = new ExtraChillEvents\DataMachineEventsIntegration();
        }
    }

    public function activate() {
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function get_integrations() {
        return $this->integrations;
    }
}

function extrachill_events() {
    return ExtraChillEvents::get_instance();
}

extrachill_events();

/**
 * Override homepage template for events.extrachill.com
 *
 * Replaces theme homepage with static page content plus datamachine-events calendar block.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook extrachill_template_homepage
 * @param string $template Default template path from theme
 * @return string Plugin template path for events site, theme template otherwise
 * @since 1.0.0
 */
function ec_events_override_homepage_template( $template ) {
	if ( get_current_blog_id() === 7 ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
	}
	return $template;
}
add_filter( 'extrachill_template_homepage', 'ec_events_override_homepage_template' );

/**
 * Override archive template on events.extrachill.com
 *
 * Unified archive template renders datamachine-events calendar block with automatic
 * taxonomy filtering based on archive context. Applies to all archive types
 * including taxonomy, post type, date, and author archives.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook extrachill_template_archive
 * @param string $template Default template path from theme
 * @return string Plugin template path for events site, theme template otherwise
 * @since 1.0.0
 */
function ec_events_override_archive_template( $template ) {
	if ( get_current_blog_id() === 7 ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/archive.php';
	}
	return $template;
}
add_filter( 'extrachill_template_archive', 'ec_events_override_archive_template' );

/**
 * Redirect /events/ post type archive to homepage for SEO consolidation
 *
 * Homepage serves as canonical events URL on events.extrachill.com.
 * 301 redirect consolidates link equity and prevents duplicate content.
 * Only applies on blog ID 7 (events.extrachill.com).
 *
 * @hook template_redirect
 * @return void
 * @since 1.0.0
 */
function ec_events_redirect_post_type_archive() {
	if ( get_current_blog_id() !== 7 ) {
		return;
	}

	if ( is_post_type_archive( 'datamachine_events' ) ) {
		wp_redirect( home_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'ec_events_redirect_post_type_archive' );