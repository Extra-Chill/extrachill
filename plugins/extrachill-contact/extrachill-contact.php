<?php
/**
 * Plugin Name: Extra Chill Contact
 * Plugin URI: https://extrachill.com
 * Description: Contact form system with Sendy newsletter integration and HTML email templates for ExtraChill platform. Provides shortcode-based contact forms with Cloudflare Turnstile protection and automatic newsletter subscription.
 * Version: 1.0.1
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-contact
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_CONTACT_VERSION', '1.0.1' );
define( 'EXTRACHILL_CONTACT_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_CONTACT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_INCLUDES_DIR', EXTRACHILL_CONTACT_PLUGIN_DIR . 'includes/' );
define( 'EXTRACHILL_CONTACT_ASSETS_URL', EXTRACHILL_CONTACT_PLUGIN_URL . 'assets/' );

class ExtraChillContact {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        require_once EXTRACHILL_CONTACT_INCLUDES_DIR . 'contact-form-core.php';

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function activate() {
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function enqueue_assets() {
        if ( is_page( 'contact-us' ) ) {
            $css_file_path = EXTRACHILL_CONTACT_PLUGIN_DIR . 'assets/contact-form.css';
            if ( file_exists( $css_file_path ) ) {
                wp_enqueue_style(
                    'extrachill-contact-form',
                    EXTRACHILL_CONTACT_ASSETS_URL . 'contact-form.css',
                    array(),
                    filemtime( $css_file_path )
                );
            }
        }
    }
}

function extrachill_contact() {
    return ExtraChillContact::instance();
}

extrachill_contact();

add_filter( 'newsletter_form_integrations', 'extrachill_contact_register_newsletter_integration' );

function extrachill_contact_register_newsletter_integration( $integrations ) {
	$integrations['contact'] = array(
		'label' => __( 'Contact Form', 'extrachill-contact' ),
		'description' => __( 'Newsletter subscription via contact forms', 'extrachill-contact' ),
		'list_id_key' => 'contact_list_id',
		'enable_key' => 'enable_contact',
		'plugin' => 'extrachill-contact',
	);
	return $integrations;
}