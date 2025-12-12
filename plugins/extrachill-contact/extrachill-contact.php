<?php
/**
 * Plugin Name: Extra Chill Contact
 * Plugin URI: https://extrachill.com
 * Description: Contact form block with Sendy newsletter integration and HTML email templates. Provides Gutenberg block with Cloudflare Turnstile protection and automatic newsletter subscription.
 * Version: 2.0.0
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-contact
 * Domain Path: /languages
 * Requires at least: 6.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_CONTACT_VERSION', '2.0.0' );
define( 'EXTRACHILL_CONTACT_PLUGIN_FILE', __FILE__ );
define( 'EXTRACHILL_CONTACT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'EXTRACHILL_CONTACT_INCLUDES_DIR', EXTRACHILL_CONTACT_PLUGIN_DIR . 'includes/' );

require_once EXTRACHILL_CONTACT_INCLUDES_DIR . 'email-functions.php';
require_once EXTRACHILL_CONTACT_INCLUDES_DIR . 'rest-api.php';

add_action( 'init', 'extrachill_contact_register_block' );

function extrachill_contact_register_block() {
	register_block_type( EXTRACHILL_CONTACT_PLUGIN_DIR . 'blocks/contact-form' );
}

add_filter( 'newsletter_form_integrations', 'extrachill_contact_register_newsletter_integration' );

function extrachill_contact_register_newsletter_integration( $integrations ) {
	$integrations['contact'] = array(
		'label'       => __( 'Contact Form', 'extrachill-contact' ),
		'description' => __( 'Newsletter subscription via contact forms', 'extrachill-contact' ),
		'list_id_key' => 'contact_list_id',
		'enable_key'  => 'enable_contact',
		'plugin'      => 'extrachill-contact',
	);
	return $integrations;
}
