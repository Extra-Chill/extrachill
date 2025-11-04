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

        require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/dm-events-integration.php';
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
 * Override homepage template for events.extrachill.com (blog ID 7).
 * Displays static homepage content with dm-events calendar block support.
 *
 * @param string $template Default template path from theme
 * @return string Template path to use
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
 * Replaces the theme's default archive template with plugin's archive template
 * that displays the dm-events calendar block. This applies to all archive types:
 * taxonomy archives, post type archives, date archives, and author archives.
 *
 * The theme's template router applies this filter when is_archive() returns true,
 * which includes:
 * - is_category() - Category archives
 * - is_tag() - Tag archives
 * - is_tax() - Custom taxonomy archives (festival, venue, location, etc.)
 * - is_post_type_archive() - Post type archives (/events/)
 * - is_author() - Author archives
 * - is_date() - Date-based archives
 *
 * @hook extrachill_template_archive
 * @param string $template Path to the default template file from theme
 * @return string Path to archive template (plugin or theme)
 */
function ec_events_override_archive_template( $template ) {
	// Only override on events.extrachill.com (blog ID 7)
	$events_blog_id = 7; // events.extrachill.com
	if ( get_current_blog_id() === $events_blog_id ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/archive.php';
	}
	return $template;
}
add_filter( 'extrachill_template_archive', 'ec_events_override_archive_template' );

/**
 * Redirect /events/ post type archive to homepage
 *
 * On events.extrachill.com, the homepage IS the main events page.
 * The /events/ URL is redundant, so redirect to homepage for SEO consolidation.
 *
 * Uses 301 (permanent) redirect to tell search engines:
 * - Homepage is the canonical URL for all events
 * - /events/ URL permanently moved
 * - Consolidates link equity to single URL
 *
 * @hook template_redirect
 * @return void
 */
function ec_events_redirect_post_type_archive() {
	// Only on events.extrachill.com (blog ID 7)
	$events_blog_id = 7; // events.extrachill.com
	if ( get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	// Redirect dm_events post type archive to homepage
	if ( is_post_type_archive( 'dm_events' ) ) {
		wp_redirect( home_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'ec_events_redirect_post_type_archive' );