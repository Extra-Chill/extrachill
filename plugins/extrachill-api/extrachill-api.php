<?php
/**
 * Plugin Name: Extra Chill API
 * Plugin URI: https://extrachill.com
 * Description: Central REST API infrastructure for the Extra Chill multisite network.
 * Version: 0.10.8
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * Network: true
 * Text Domain: extrachill-api
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load Composer autoloader for dependencies (Endroid QR Code)
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

if ( ! defined( 'EXTRACHILL_API_PATH' ) ) {
    define( 'EXTRACHILL_API_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'EXTRACHILL_API_URL' ) ) {
    define( 'EXTRACHILL_API_URL', plugin_dir_url( __FILE__ ) );
}

register_activation_hook( __FILE__, 'extrachill_api_activate' );

function extrachill_api_activate() {
    require_once EXTRACHILL_API_PATH . 'inc/activity/db.php';

    if ( function_exists( 'extrachill_api_activity_install_table' ) ) {
        extrachill_api_activity_install_table();
    }
}

final class ExtraChill_API_Plugin {
    /**
     * Singleton instance storage.
     *
     * @var ExtraChill_API_Plugin|null
     */
    private static $instance = null;

    /**
     * Bootstraps plugin hooks.
     */
    private function __construct() {
        $this->load_route_files();
        add_action( 'plugins_loaded', array( $this, 'boot' ) );
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Returns shared instance.
     *
     * @return ExtraChill_API_Plugin
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Placeholder bootstrap for future setup.
     */
    public function boot() {
        require_once EXTRACHILL_API_PATH . 'inc/auth/extrachill-link-auth.php';
        require_once EXTRACHILL_API_PATH . 'inc/utils/id-generator.php';
        require_once EXTRACHILL_API_PATH . 'inc/utils/bbpress-drafts.php';

		if ( file_exists( EXTRACHILL_API_PATH . 'inc/activity/db.php' ) ) {
			require_once EXTRACHILL_API_PATH . 'inc/activity/db.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/schema.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/storage.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/taxonomies.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/throttle.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/emitter.php';
			require_once EXTRACHILL_API_PATH . 'inc/activity/emitters.php';

			// Ensure activity table exists (network activation doesn't trigger activation hook)
			$this->maybe_create_activity_table();
		}


        do_action( 'extrachill_api_bootstrap' );
    }

    /**
     * Loads all route files so each endpoint can self-register.
     */
    private function load_route_files() {
        $routes_dir = EXTRACHILL_API_PATH . 'inc/routes/';

        if ( ! is_dir( $routes_dir ) ) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $routes_dir, RecursiveDirectoryIterator::SKIP_DOTS )
        );

        foreach ( $iterator as $file ) {
            if ( 'php' !== $file->getExtension() ) {
                continue;
            }

            require_once $file->getRealPath();
        }
    }

    /**
     * Registers REST routes. Will be populated as endpoints migrate into this plugin.
     */
    public function register_routes() {
        do_action( 'extrachill_api_register_routes' );
    }

    /**
     * Creates activity table if it doesn't exist.
     * Failsafe for network activation which doesn't trigger activation hooks.
     */
    private function maybe_create_activity_table() {
        global $wpdb;

        $table_name = extrachill_api_activity_get_table_name();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $table_exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name )
        );

        if ( $table_exists !== $table_name ) {
            extrachill_api_activity_install_table();
        }
    }
}

ExtraChill_API_Plugin::get_instance();
