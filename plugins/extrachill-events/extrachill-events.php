<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Event calendar integration with homepage template override for events.extrachill.com.
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: data-machine, dm-events
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

class ExtraChillEvents {

    private static $instance = null;
    private $integrations = array();

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

    private function load_dependencies() {
        $autoload_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
        if (file_exists($autoload_file)) {
            require_once $autoload_file;
        }

        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'includes/class-dm-events-integration.php';
        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/breadcrumb-integration.php';
    }

    private function init_integrations() {
        if (class_exists('DmEvents\Core\Taxonomy_Badges')) {
            $this->integrations['dm_events'] = new ExtraChillEvents\DmEventsIntegration();
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
 * Register homepage template override via theme's universal routing system.
 *
 * The extrachill_template_homepage filter is provided by theme's template-router.php
 * and enables plugins to completely override homepage templates at the routing level.
 *
 * Template Override Pattern (follows extrachill-chat and extrachill-artist-platform):
 * - Uses direct blog ID numbers for optimal performance
 * - WordPress blog-id-cache provides automatic performance optimization
 * - Conditional override only applies to events.extrachill.com (site #7)
 *
 * Homepage Implementation:
 * - Displays content from WordPress static homepage (Settings → Reading)
 * - Supports dm-events calendar block via WordPress editor
 * - Full-width container with header/footer integration
 */
add_filter( 'extrachill_template_homepage', 'ec_events_override_homepage_template' );

/**
 * Override homepage template for events.extrachill.com
 *
 * @param string $template Default template path from theme
 * @return string Template path to use
 */
function ec_events_override_homepage_template( $template ) {
	// Only override on events.extrachill.com using domain-based detection
	if ( get_current_blog_id() === 7 ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
	}
	return $template;
}