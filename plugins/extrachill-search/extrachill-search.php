<?php
/**
 * Plugin Name: ExtraChill Search
 * Plugin URI: https://extrachill.com
 * Description: Network-wide search across all nine sites using domain-based resolution
 * Version: 0.1.1
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
 * @version 0.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ExtraChill_Search_Plugin {

    const VERSION = '0.1.0';

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
        add_action( 'template_redirect', array( $this, 'fix_search_404' ), 1 );
    }

    private function includes() {
        $includes_dir = plugin_dir_path( __FILE__ ) . 'inc/';

        if ( file_exists( $includes_dir . 'core/search-functions.php' ) ) {
            require_once $includes_dir . 'core/search-functions.php';
        }

        if ( file_exists( $includes_dir . 'core/search-algorithm.php' ) ) {
            require_once $includes_dir . 'core/search-algorithm.php';
        }

        if ( file_exists( $includes_dir . 'core/taxonomy-functions.php' ) ) {
            require_once $includes_dir . 'core/taxonomy-functions.php';
        }

        if ( file_exists( $includes_dir . 'core/filter-pagination.php' ) ) {
            require_once $includes_dir . 'core/filter-pagination.php';
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

    public function override_search_template( $template ) {
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/search.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
        return $template;
    }

    /**
     * Prevent 404 errors on paginated multisite search when current site has no results
     */
    public function fix_search_404() {
        if ( ! is_404() ) {
            return;
        }

        $search_term = get_query_var( 's' );
        if ( empty( $search_term ) || ! function_exists( 'extrachill_multisite_search' ) ) {
            return;
        }

        $paged = max( 1, get_query_var( 'paged', 1 ) );
        $posts_per_page = (int) get_option( 'posts_per_page', 10 );
        $offset = ( $paged - 1 ) * $posts_per_page;

        $search_data = extrachill_multisite_search(
            $search_term,
            array(),
            array(
                'limit'        => $posts_per_page,
                'offset'       => $offset,
                'return_count' => true,
            )
        );

        if ( ! empty( $search_data['results'] ) ) {
            global $wp_query;
            $wp_query->is_404 = false;
            $wp_query->is_search = true;
            status_header( 200 );
        }
    }
}

ExtraChill_Search_Plugin::get_instance();