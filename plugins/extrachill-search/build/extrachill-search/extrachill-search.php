<?php
/**
 * Plugin Name: ExtraChill Search
 * Plugin URI: https://extrachill.com
 * Description: Centralized multisite search functionality for the ExtraChill Platform
 * Version: 1.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Network: true
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * Text Domain: extrachill-search
 * Domain Path: /languages
 *
 * @package ExtraChill\Search
 * @version 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin initialization
 */
class ExtraChill_Search_Plugin {

    const VERSION = '1.0.0';

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $this->includes();
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
        add_filter( 'extrachill_template_search', array( $this, 'override_search_template' ), 10 );
    }

    private function includes() {
        $includes_dir = plugin_dir_path( __FILE__ ) . 'inc/';

        if ( file_exists( $includes_dir . 'core/search-functions.php' ) ) {
            require_once $includes_dir . 'core/search-functions.php';
        }

        if ( file_exists( $includes_dir . 'core/taxonomy-functions.php' ) ) {
            require_once $includes_dir . 'core/taxonomy-functions.php';
        }

        if ( file_exists( $includes_dir . 'templates/template-functions.php' ) ) {
            require_once $includes_dir . 'templates/template-functions.php';
        }

        if ( file_exists( $includes_dir . 'templates/site-badge.php' ) ) {
            require_once $includes_dir . 'templates/site-badge.php';
        }
    }

    public function activate() {
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'extrachill-search', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
    }

    /**
     * Override search template with multisite search template
     *
     * Hooks into ExtraChill theme's template_search filter to provide
     * network-wide search functionality across all sites.
     *
     * @param string $template Default template path from theme
     * @return string Plugin template path or original template
     */
    public function override_search_template( $template ) {
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/search.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        return $template;
    }
}

// Initialize the plugin
ExtraChill_Search_Plugin::get_instance();