<?php
/**
 * Plugin Name: ExtraChill API
 * Plugin URI: https://extrachill.com
 * Description: Central REST API infrastructure for the Extra Chill multisite network.
 * Version: 0.1.1
 * Author: Extra Chill
 * Author URI: https://extrachill.com
 * Network: true
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'EXTRACHILL_API_PATH' ) ) {
    define( 'EXTRACHILL_API_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'EXTRACHILL_API_URL' ) ) {
    define( 'EXTRACHILL_API_URL', plugin_dir_url( __FILE__ ) );
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
}

ExtraChill_API_Plugin::get_instance();
