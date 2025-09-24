<?php
/**
 * Plugin Name: ExtraChill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 1.0
 * Author: Chris Huber
 * Text Domain: extrachill-newsletter
 * Domain Path: /languages
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define plugin constants
 */
define( 'EXTRACHILL_NEWSLETTER_VERSION', '1.0' );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_INCLUDES_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'includes/' );
define( 'EXTRACHILL_NEWSLETTER_TEMPLATES_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'templates/' );
define( 'EXTRACHILL_NEWSLETTER_ASSETS_URL', EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/' );

// Include modularized files
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-post-type.php';
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-sendy-integration.php';
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-ajax-handlers.php';
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-shortcodes.php';

/**
 * Enqueue Newsletter assets conditionally
 *
 * Loads CSS and JavaScript only on newsletter archive, single pages, and pages
 * that contain newsletter subscription forms (homepage, all pages with popup).
 *
 * @since 1.0.0
 */
function enqueue_newsletter_assets() {
	// Newsletter archive and single pages: Load all assets
	if ( is_post_type_archive( 'newsletter' ) || is_singular( 'newsletter' ) ) {

		// Main Newsletter CSS
		$css_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-newsletter',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.css',
				array(),
				filemtime( $css_file_path )
			);
		}

		// Newsletter JavaScript with AJAX support
		$js_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-newsletter',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.js',
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			// AJAX localization for all newsletter forms
			wp_localize_script(
				'extrachill-newsletter',
				'newsletterParams',
				array(
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
					'newsletter_nonce' => wp_create_nonce( 'newsletter_nonce' ),
					'newsletter_popup_nonce' => wp_create_nonce( 'newsletter_popup_nonce' ),
					'subscribe_to_sendy_home_nonce' => wp_create_nonce( 'subscribe_to_sendy_home_nonce' ),
				)
			);
		}
	}

	// Homepage: Load specific homepage assets
	if ( is_front_page() ) {
		$js_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.js';
		if ( file_exists( $js_file_path ) ) {
			wp_enqueue_script(
				'extrachill-newsletter-home',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.js',
				array( 'jquery' ),
				filemtime( $js_file_path ),
				true
			);

			wp_localize_script(
				'extrachill-newsletter-home',
				'extrachill_ajax_object',
				array(
					'ajax_url' => admin_url( 'admin-ajax.php' ),
					'subscribe_to_sendy_home_nonce' => wp_create_nonce( 'subscribe_to_sendy_home_nonce' ),
				)
			);
		}
	}

	// Newsletter popup script (conditionally loaded based on newsletter.php logic)
	enqueue_newsletter_popup_scripts();
}
add_action( 'wp_enqueue_scripts', 'enqueue_newsletter_assets' );

/**
 * Display Newsletter section on homepage
 *
 * Hooks into theme's front page to display newsletter signup section.
 * Uses plugin template for consistent rendering and data management.
 *
 * @since 1.0.0
 */
function display_newsletter_homepage_section() {
	// Load newsletter homepage section template from plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'homepage-section.php';
}
add_action( 'extrachill_newsletter_homepage_section', 'display_newsletter_homepage_section' );

/**
 * Template loader for Newsletter post type
 *
 * Overrides WordPress template hierarchy to use plugin templates
 * for Newsletter archive and single pages. Ensures consistent
 * display regardless of active theme.
 *
 * @since 1.0.0
 * @param string $template Current template path
 * @return string Modified template path
 */
function newsletter_template_loader( $template ) {
	// Override templates for Newsletter post type
	if ( is_post_type_archive( 'newsletter' ) ) {
		$plugin_template = locate_newsletter_template( 'archive-newsletter.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	} elseif ( is_singular( 'newsletter' ) ) {
		$plugin_template = locate_newsletter_template( 'single-newsletter.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'newsletter_template_loader' );

/**
 * Locate Newsletter template file
 *
 * Searches for template files in the plugin's templates directory.
 * Used by template loader to override theme templates.
 *
 * @since 1.0.0
 * @param string $template_name Template filename to locate
 * @return string|false Path to template file, or false if not found
 */
function locate_newsletter_template( $template_name ) {
	$plugin_template_path = EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . $template_name;

	if ( file_exists( $plugin_template_path ) ) {
		return $plugin_template_path;
	}

	return false;
}

/**
 * Add navigation menu newsletter form integration
 *
 * Hooks into navigation system to add newsletter subscription form.
 * Uses filter to integrate with existing theme navigation structure.
 *
 * @since 1.0.0
 * @param string $items Existing menu items
 * @param object $args Menu arguments
 * @return string Modified menu items with newsletter form
 */
function add_newsletter_to_navigation( $items, $args ) {
	// Only add to primary menu
	if ( $args->theme_location == 'primary' ) {
		$newsletter_form = '
			<li class="menu-newsletter">
				<form class="newsletter-form">
					<label for="newsletter-email-nav" class="sr-only">Get our Newsletter</label>
					<input type="email" id="newsletter-email-nav" name="email" placeholder="Enter your email" required>
					<button type="submit">Subscribe</button>
					<p><a href="/newsletters">See past newsletters</a></p>
				</form>
			</li>';
		$items .= $newsletter_form;
	}
	return $items;
}
add_filter( 'wp_nav_menu_items', 'add_newsletter_to_navigation', 10, 2 );

/**
 * Plugin activation hook
 *
 * Handles plugin activation tasks including flushing rewrite rules
 * for the custom post type URL structure.
 *
 * @since 1.0.0
 */
function extrachill_newsletter_activate() {
	// Create newsletter custom post type
	create_newsletter_post_type();

	// Flush rewrite rules to ensure URLs work
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'extrachill_newsletter_activate' );

/**
 * Plugin deactivation hook
 *
 * Cleans up plugin-specific settings and flushes rewrite rules.
 *
 * @since 1.0.0
 */
function extrachill_newsletter_deactivate() {
	// Flush rewrite rules to clean up URLs
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'extrachill_newsletter_deactivate' );

/**
 * Plugin Architecture
 *
 * Core functionality is modularized across separate include files:
 *
 * - newsletter-post-type.php: Custom post type registration and meta boxes
 * - newsletter-sendy-integration.php: Sendy API integration for campaigns and subscriptions
 * - newsletter-ajax-handlers.php: AJAX handlers for all subscription forms
 * - newsletter-shortcodes.php: Shortcodes for recent newsletters and widgets
 *
 * Templates are located in /templates/ directory and override theme templates.
 * Assets are enqueued conditionally based on page context and requirements.
 * All functionality maintains complete backward compatibility with existing theme integration.
 */