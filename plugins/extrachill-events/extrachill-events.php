<?php
/**
 * Plugin Name: Extra Chill Events
 * Plugin URI: https://extrachill.com
 * Description: Calendar integration with template overrides, datamachine-events badge/button styling, breadcrumb system, and related events for events.extrachill.com.
 * Version: 0.4.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: data-machine, datamachine-events
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: false
 *
 * @package ExtraChillEvents
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_EVENTS_VERSION', '0.4.0' );
define( 'EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_EVENTS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * ExtraChillEvents
 *
 * Singleton class managing datamachine-events integration with homepage/archive template
 * overrides, badge/button styling, breadcrumb system, and SEO redirects for
 * events.extrachill.com (blog ID 7).
 *
 * @since 0.1.0
 */
class ExtraChillEvents {

	private static $instance = null;
	private $integrations    = array();

	/**
	 * Get singleton instance
	 *
	 * @return ExtraChillEvents
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
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
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( $this, 'init_datamachine_handlers' ), 20 );
		add_action( 'init', array( $this, 'init_abilities' ), 25 );
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'extrachill-events',
			false,
			dirname( EXTRACHILL_EVENTS_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Load plugin dependencies via direct includes
	 *
	 * Composer autoloader exists for development dependencies only.
	 * All plugin code uses direct require_once includes.
	 */
	private function load_dependencies() {
		$autoload_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'vendor/autoload.php';
		if ( file_exists( $autoload_file ) ) {
			require_once $autoload_file;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-events-integration.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/nav.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/priority-venue-ordering.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/admin/priority-venues.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/breadcrumbs.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/related-events.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/single-event/share-button.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/home/actions.php';
	}

	public function init_datamachine_handlers() {
		if ( ! class_exists( 'DataMachine\Core\Steps\Fetch\Handlers\FetchHandler' ) ) {
			return;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/SlideGenerator.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/WeeklyRoundupSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/WeeklyRoundupHandler.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/RoundupPublishSettings.php';
		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/handlers/weekly-roundup/RoundupPublishHandler.php';

		new \ExtraChillEvents\Handlers\WeeklyRoundup\WeeklyRoundupHandler();
		new \ExtraChillEvents\Handlers\WeeklyRoundup\RoundupPublishHandler();
	}

	public function init_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/WeeklyRoundupAbilities.php';
		new \ExtraChillEvents\Abilities\WeeklyRoundupAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/LocationEventAbilities.php';
		new \ExtraChillEvents\Abilities\LocationEventAbilities();

		require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Abilities/PriorityVenueAbilities.php';
		new \ExtraChillEvents\Abilities\PriorityVenueAbilities();
	}

	/**
	 * Initialize event plugin integrations
	 *
	 * Conditionally loads DataMachineEventsIntegration if datamachine-events plugin is active.
	 */
	private function init_integrations() {
		if ( class_exists( 'DataMachineEvents\Core\Event_Post_Type' ) ) {
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
 * Register event-submission block from build directory
 *
 * @hook init
 * @return void
 * @since 0.1.5
 */
function extrachill_events_register_blocks() {
	register_block_type( EXTRACHILL_EVENTS_PLUGIN_DIR . 'build/event-submission' );
}
add_action( 'init', 'extrachill_events_register_blocks' );

/**
 * Render homepage content for events.extrachill.com
 *
 * Hooked via extrachill_homepage_content action.
 *
 * @since 0.1.0
 */
function ec_events_render_homepage() {
	include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
add_action( 'extrachill_homepage_content', 'ec_events_render_homepage' );

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
 * @since 0.1.0
 */
function ec_events_override_archive_template( $template ) {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( $events_blog_id && get_current_blog_id() === $events_blog_id ) {
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
 * @since 0.1.0
 */
function ec_events_redirect_post_type_archive() {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'events' ) : null;
	if ( ! $events_blog_id || get_current_blog_id() !== $events_blog_id ) {
		return;
	}

	if ( is_post_type_archive( 'datamachine_events' ) ) {
		wp_redirect( home_url(), 301 );
		exit;
	}
}
add_action( 'template_redirect', 'ec_events_redirect_post_type_archive' );
