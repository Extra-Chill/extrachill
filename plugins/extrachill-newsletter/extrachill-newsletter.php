<?php
/**
 * Plugin Name: Extra Chill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 0.2.6
 * Author: Chris Huber
 * Network: true
 * Text Domain: extrachill-newsletter
 * Domain Path: /languages
 *
 * @package ExtraChillNewsletter
 * @since 0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_NEWSLETTER_VERSION', '0.2.6' );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_INC_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/' );
define( 'EXTRACHILL_NEWSLETTER_TEMPLATES_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/core/templates/forms/' );
define( 'EXTRACHILL_NEWSLETTER_ASSETS_URL', EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/' );

/**
 * Check if current site is the newsletter site
 *
 * @since 0.1.2
 * @return bool True if on newsletter.extrachill.com (blog ID 9)
 */
function is_newsletter_site() {
	$newsletter_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'newsletter' ) : null;
	return $newsletter_blog_id && get_current_blog_id() === $newsletter_blog_id;
}

// Core functionality (network-wide)
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/assets.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/sendy-api.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/templates/email-template.php';

// Hook integrations (network-wide)
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/breadcrumbs.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/forms.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/homepage.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/post-meta.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/sidebar.php';

/**
 * Register newsletter post type with theme's single post style system.
 *
 * @param array $post_types Post types that load single-post.css.
 * @return array Modified post types.
 * @since 0.2.7
 */
function newsletter_single_post_style_types( $post_types ) {
	$post_types[] = 'newsletter';
	return $post_types;
}
add_filter( 'extrachill_single_post_style_post_types', 'newsletter_single_post_style_types' );

/**
 * Load newsletter-site-only components after multisite dependencies are available.
 * Fires on plugins_loaded priority 20 (after extrachill-multisite loads at priority 10).
 */
add_action( 'plugins_loaded', function() {
	if ( is_newsletter_site() ) {
		require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/newsletter-post-type.php';

		if ( is_admin() ) {
			require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/newsletter-settings.php';
		}
	}
}, 20 );

/**
 * Get newsletter form context presets
 *
 * Returns array of preset configurations for each known form context.
 * Presets define heading, description, layout, and other display options.
 *
 * @since 0.1.2
 * @return array Context presets keyed by context slug
 */
function extrachill_get_newsletter_context_presets() {
	return array(
		'homepage' => array(
			'wrapper_class'     => 'home-newsletter-signup newsletter-grid-section',
			'heading'           => __( 'Subscribe', 'extrachill-newsletter' ),
			'heading_level'     => 'h3',
			'description'       => __( 'Stories and insights from the underground.', 'extrachill-newsletter' ),
			'layout'            => 'section',
			'placeholder'       => __( 'Your email address', 'extrachill-newsletter' ),
			'button_text'       => __( 'Subscribe', 'extrachill-newsletter' ),
			'show_archive_link' => false,
		),
		'navigation' => array(
			'wrapper_class'     => 'menu-newsletter',
			'heading'           => null,
			'heading_level'     => 'h3',
			'description'       => null,
			'layout'            => 'inline',
			'placeholder'       => __( 'Enter your email', 'extrachill-newsletter' ),
			'button_text'       => __( 'Subscribe', 'extrachill-newsletter' ),
			'show_archive_link' => true,
			'archive_link_text' => __( 'See past newsletters', 'extrachill-newsletter' ),
		),
		'content' => array(
			'wrapper_class'     => 'newsletter-content-section',
			'heading'           => __( 'Stay Connected with Extra Chill', 'extrachill-newsletter' ),
			'heading_level'     => 'h3',
			'description'       => __( 'Get stories, reflections, and music industry insights delivered to your inbox.', 'extrachill-newsletter' ),
			'layout'            => 'section',
			'placeholder'       => __( 'Enter your email address', 'extrachill-newsletter' ),
			'button_text'       => __( 'Subscribe', 'extrachill-newsletter' ),
			'show_archive_link' => true,
			'archive_link_text' => __( 'Browse past newsletters', 'extrachill-newsletter' ),
		),
		'archive' => array(
			'wrapper_class'     => 'newsletter-subscription-form',
			'heading'           => __( 'Subscribe to Our Newsletter', 'extrachill-newsletter' ),
			'heading_level'     => 'h2',
			'description'       => __( 'Get independent music journalism with personality delivered to your inbox.', 'extrachill-newsletter' ),
			'layout'            => 'section',
			'placeholder'       => __( 'Enter your email', 'extrachill-newsletter' ),
			'button_text'       => __( 'Subscribe', 'extrachill-newsletter' ),
			'show_archive_link' => false,
		),
	);
}

/**
 * Render a newsletter form for the specified context
 *
 * Main action handler for extrachill_render_newsletter_form hook.
 * Retrieves preset for context, applies filter for customization,
 * enqueues assets, and includes generic template.
 *
 * @since 0.1.2
 * @param string $context The form context slug (e.g., 'homepage', 'content')
 */
function extrachill_render_newsletter_form( $context ) {
	$presets = extrachill_get_newsletter_context_presets();

	// Get preset for this context, or empty array for unknown contexts
	$args = isset( $presets[ $context ] ) ? $presets[ $context ] : array();

	// Allow customization via filter
	$args = apply_filters( 'extrachill_newsletter_form_args', $args, $context );

	// Render generic template
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'generic-form.php';
}
add_action( 'extrachill_render_newsletter_form', 'extrachill_render_newsletter_form' );

/**
 * Display navigation newsletter form (network-wide exception)
 *
 * Navigation form is handled directly by newsletter plugin since it
 * appears on every page across the network.
 */
add_action( 'extrachill_navigation_before_social_links', function() {
	do_action( 'extrachill_render_newsletter_form', 'navigation' );
});

/**
 * Set transient to trigger rewrite flush after post type is registered.
 */
function extrachill_newsletter_activate() {
	set_transient( 'extrachill_newsletter_flush_rewrite', true, 60 );
}
register_activation_hook( __FILE__, 'extrachill_newsletter_activate' );

/**
 * Flush rewrite rules after post type registration if activation transient exists.
 */
add_action( 'init', function() {
	if ( get_transient( 'extrachill_newsletter_flush_rewrite' ) ) {
		delete_transient( 'extrachill_newsletter_flush_rewrite' );
		flush_rewrite_rules();
	}
}, 20 );

function extrachill_newsletter_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'extrachill_newsletter_deactivate' );

/**
 * Register newsletter integration contexts for Sendy list mapping
 *
 * Integration contexts registered:
 * - navigation: Site navigation menu subscription form
 * - homepage: Main homepage subscription section
 * - archive: Newsletter archive page subscription
 * - content: After post content subscription
 *
 * Settings configured via Newsletter → Settings on newsletter.extrachill.com:
 * - Sendy list ID per integration
 * - Global Sendy API configuration
 */
function newsletter_register_default_integrations( $integrations ) {
	$integrations['navigation'] = array(
		'label'       => __( 'Navigation Menu Form', 'extrachill-newsletter' ),
		'description' => __( 'Newsletter subscription in site navigation', 'extrachill-newsletter' ),
		'list_id_key' => 'navigation_list_id',
	);

	$integrations['homepage'] = array(
		'label'       => __( 'Homepage Newsletter Form', 'extrachill-newsletter' ),
		'description' => __( 'Main homepage subscription form', 'extrachill-newsletter' ),
		'list_id_key' => 'homepage_list_id',
	);

	$integrations['archive'] = array(
		'label'       => __( 'Archive Page Form', 'extrachill-newsletter' ),
		'description' => __( 'Newsletter archive page subscription', 'extrachill-newsletter' ),
		'list_id_key' => 'archive_list_id',
	);

	$integrations['content'] = array(
		'label'       => __( 'Content Form', 'extrachill-newsletter' ),
		'description' => __( 'Newsletter form after post content', 'extrachill-newsletter' ),
		'list_id_key' => 'content_list_id',
	);

	$integrations['contact'] = array(
		'label'       => __( 'Contact Form', 'extrachill-newsletter' ),
		'description' => __( 'Newsletter subscription via contact forms', 'extrachill-newsletter' ),
		'list_id_key' => 'contact_list_id',
	);

	return $integrations;
}
add_filter( 'newsletter_form_integrations', 'newsletter_register_default_integrations' );

/**
 * Get all registered newsletter integrations
 *
 * @return array Integrations keyed by context slug
 */
function get_newsletter_integrations() {
	return apply_filters( 'newsletter_form_integrations', array() );
}

