<?php
/**
 * Plugin Name: ExtraChill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 1.0.0
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
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-hooks.php';
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-admin.php';
require_once EXTRACHILL_NEWSLETTER_INCLUDES_DIR . 'newsletter-popup.php';

/**
 * Enqueue Newsletter assets globally
 *
 * Loads CSS and JavaScript on all pages since newsletter forms appear site-wide
 * via navigation menu and other action hooks.
 *
 * @since 1.0.0
 */
function enqueue_newsletter_assets() {
	// Universal Newsletter Forms CSS (loaded globally for consistency)
	$forms_css_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter-forms.css';
	if ( file_exists( $forms_css_file_path ) ) {
		wp_enqueue_style(
			'extrachill-newsletter-forms',
			EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter-forms.css',
			array(),
			filemtime( $forms_css_file_path )
		);
	}

	// Main Newsletter CSS (archive/single pages)
	$load_newsletter_css = is_post_type_archive( 'newsletter' ) || is_singular( 'newsletter' ) || is_front_page() ||
	                      is_post_type_archive( 'festival_wire' ) || is_singular( 'festival_wire' );

	if ( $load_newsletter_css ) {
		$css_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.css';
		if ( file_exists( $css_file_path ) ) {
			wp_enqueue_style(
				'extrachill-newsletter',
				EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.css',
				array( 'extrachill-newsletter-forms' ),
				filemtime( $css_file_path )
			);
		}
	}

	// Newsletter JavaScript with AJAX support (loaded globally)
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
				'newsletter_content_nonce' => wp_create_nonce( 'newsletter_content_nonce' ),
				'newsletter_footer_nonce' => wp_create_nonce( 'newsletter_footer_nonce' ),
			)
		);
	}
add_action( 'wp_enqueue_scripts', 'enqueue_newsletter_assets' );

/**
 * Display Newsletter section on homepage
 *
 * Hooks into theme's homepage layout via location-based action hook.
 * Uses plugin template for consistent rendering and data management.
 *
 * @since 1.0.0
 */
function display_newsletter_homepage_section() {
	// Check if homepage newsletter is enabled
	$settings = get_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_homepage'])) {
		return;
	}

	// Load newsletter homepage section template from plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'homepage-section.php';
}
add_action( 'extrachill_home_final_right', 'display_newsletter_homepage_section' );

/**
 * Display newsletter section in 3x3 grid bottom-right position
 *
 * Provides newsletter list for the homepage 3x3 grid with plugin-managed data.
 * Handles its own query and styling to match grid layout.
 *
 * @since 1.0.0
 */
function display_newsletter_grid_section() {
    $newsletter_posts = get_posts(array('numberposts' => 3, 'post_type' => 'newsletter'));
    ?>
    <div class="home-3x3-stacked-section">
        <div class="home-3x3-header">
            <span class="home-3x3-label">Latest Newsletters</span>
            <a class="home-3x3-archive-link" href="<?php echo esc_url( get_post_type_archive_link('newsletter') ); ?>">View All</a>
        </div>
        <div class="home-3x3-list">
            <?php if (!empty($newsletter_posts)) : ?>
                <?php foreach ($newsletter_posts as $post) : setup_postdata($post); ?>
                    <a href="<?php the_permalink(); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php the_title_attribute(); ?>">
                        <span class="home-3x3-title"><?php the_title(); ?></span>
                        <span class="home-3x3-meta">Sent <?php echo get_the_date(); ?></span>
                    </a>
                <?php endforeach; wp_reset_postdata(); ?>
            <?php else: ?>
                <div class="home-3x3-card home-3x3-empty">No newsletters yet.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
add_action( 'extrachill_home_grid_bottom_right', 'display_newsletter_grid_section' );

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
 * Display Newsletter form in navigation menu
 *
 * Hooks into theme's navigation layout via location-based action hook.
 * Uses plugin template for consistent rendering and form handling.
 *
 * @since 1.0.0
 */
function display_newsletter_navigation_form() {
	// Check if navigation form is enabled
	$settings = get_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_navigation'])) {
		return;
	}

	// Load newsletter navigation form template from plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'navigation-form.php';
}
add_action( 'extrachill_navigation_before_social_links', 'display_newsletter_navigation_form' );

/**
 * Display Newsletter form after post content
 *
 * Hooks into theme's post content layout via extrachill_after_post_content action hook.
 * Uses plugin template for consistent rendering and form handling.
 *
 * @since 1.0.0
 */
function display_newsletter_content_form() {
	// Don't show on homepage - homepage has its own newsletter section
	if (is_front_page()) {
		return;
	}

	// Check if content form is enabled
	$settings = get_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_content'])) {
		return;
	}

	// Load newsletter content form template from plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'content-form.php';
}
add_action( 'extrachill_after_post_content', 'display_newsletter_content_form' );

/**
 * Display Newsletter form above footer
 *
 * Hooks into theme's footer layout via extrachill_above_footer action hook.
 * Uses plugin template for consistent rendering and form handling.
 *
 * @since 1.0.0
 */
function display_newsletter_footer_form() {
	// Check if footer form is enabled
	$settings = get_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_footer'])) {
		return;
	}

	// Special homepage logic: only show footer form if homepage newsletter is not being used
	if (is_front_page()) {
		// Check if homepage newsletter section is active via action hook
		// If any plugins/themes hook into extrachill_home_final_right, don't show footer form
		if (has_action('extrachill_home_final_right')) {
			return;
		}
	}

	// Load newsletter footer form template from plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'footer-form.php';
}
add_action( 'extrachill_above_footer', 'display_newsletter_footer_form' );

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
 * Register Newsletter plugin's own integrations
 *
 * Newsletter plugin dogfoods its own integration system by registering
 * its forms via the same filter system it provides to other plugins.
 *
 * @since 2.0.0
 * @param array $integrations Existing registered integrations
 * @return array Updated integrations with Newsletter plugin's forms
 */
function newsletter_register_default_integrations($integrations) {
	$integrations['navigation'] = array(
		'label' => __('Navigation Menu Form', 'extrachill-newsletter'),
		'description' => __('Newsletter subscription in site navigation', 'extrachill-newsletter'),
		'list_id_key' => 'navigation_list_id',
		'enable_key' => 'enable_navigation',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['homepage'] = array(
		'label' => __('Homepage Newsletter Form', 'extrachill-newsletter'),
		'description' => __('Main homepage subscription form', 'extrachill-newsletter'),
		'list_id_key' => 'homepage_list_id',
		'enable_key' => 'enable_homepage',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['popup'] = array(
		'label' => __('Newsletter Popup Form', 'extrachill-newsletter'),
		'description' => __('Modal popup newsletter subscription', 'extrachill-newsletter'),
		'list_id_key' => 'popup_list_id',
		'enable_key' => 'enable_popup',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['archive'] = array(
		'label' => __('Archive Page Form', 'extrachill-newsletter'),
		'description' => __('Newsletter archive page subscription', 'extrachill-newsletter'),
		'list_id_key' => 'archive_list_id',
		'enable_key' => 'enable_archive',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['content'] = array(
		'label' => __('Content Form', 'extrachill-newsletter'),
		'description' => __('Newsletter form after post content', 'extrachill-newsletter'),
		'list_id_key' => 'content_list_id',
		'enable_key' => 'enable_content',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['footer'] = array(
		'label' => __('Footer Form', 'extrachill-newsletter'),
		'description' => __('Newsletter form above site footer', 'extrachill-newsletter'),
		'list_id_key' => 'footer_list_id',
		'enable_key' => 'enable_footer',
		'plugin' => 'extrachill-newsletter'
	);

	$integrations['contact'] = array(
		'label' => __('Contact Form', 'extrachill-newsletter'),
		'description' => __('Newsletter subscription via contact forms', 'extrachill-newsletter'),
		'list_id_key' => 'contact_list_id',
		'enable_key' => 'enable_contact',
		'plugin' => 'extrachill-newsletter'
	);

	return $integrations;
}
add_filter('newsletter_form_integrations', 'newsletter_register_default_integrations');

/**
 * Display Festival Wire tip form
 *
 * Hooks into Festival Wire's extrachill_after_news_wire action to display
 * the tip form. Newsletter plugin owns the entire tip form functionality.
 *
 * @since 2.0.0
 */
function newsletter_display_festival_wire_tip_form() {
	// Only display if Festival Wire tip integration is enabled
	if (!newsletter_integration_enabled('enable_festival_wire_tip')) {
		return;
	}

	// Load tip form template from Newsletter plugin
	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'festival-wire-tip-form.php';
}
add_action('extrachill_after_news_wire', 'newsletter_display_festival_wire_tip_form');

/**
 * Initialize newsletter integration action hooks
 *
 * Creates action hooks for all registered integrations to enable
 * plugins to display newsletter forms via do_action() calls.
 *
 * @since 2.0.0
 */
function newsletter_init_integration_actions() {
	$integrations = get_newsletter_integrations();

	foreach ($integrations as $context => $integration) {
		add_action("newsletter_display_{$context}", function($email = null) use ($context, $integration) {
			if ($email) {
				// Direct subscription (API usage)
				return subscribe_via_integration($email, $context);
			} else {
				// Display form (template usage)
				newsletter_render_integration_form($context, $integration);
			}
		});
	}
}
add_action('init', 'newsletter_init_integration_actions', 20); // Run after integrations are registered

/**
 * Render integration form
 *
 * Renders a newsletter form for a specific integration context.
 * Uses template hierarchy to allow customization.
 *
 * @since 2.0.0
 * @param string $context Integration context key
 * @param array $integration Integration configuration
 */
function newsletter_render_integration_form($context, $integration) {
	// Check if integration is enabled
	if (!newsletter_integration_enabled($integration['enable_key'])) {
		return;
	}

	// Template hierarchy for integration forms
	$template_candidates = array(
		"newsletter-form-{$context}.php",
		'newsletter-form-default.php'
	);

	$template_loaded = false;

	// Try to load template from theme first, then plugin
	foreach ($template_candidates as $template_name) {
		$theme_template = locate_template($template_name);
		if ($theme_template) {
			include $theme_template;
			$template_loaded = true;
			break;
		}

		$plugin_template = EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . $template_name;
		if (file_exists($plugin_template)) {
			include $plugin_template;
			$template_loaded = true;
			break;
		}
	}

	// Fallback if no template found
	if (!$template_loaded) {
		newsletter_render_fallback_form($context, $integration);
	}
}

/**
 * Render fallback newsletter form
 *
 * Simple fallback form when no template is found.
 *
 * @since 2.0.0
 * @param string $context Integration context key
 * @param array $integration Integration configuration
 */
function newsletter_render_fallback_form($context, $integration) {
	?>
	<div class="newsletter-form newsletter-form-<?php echo esc_attr($context); ?>">
		<h3><?php echo esc_html($integration['label']); ?></h3>
		<p><?php echo esc_html($integration['description']); ?></p>
		<form class="newsletter-subscription-form" data-context="<?php echo esc_attr($context); ?>">
			<input type="email" name="email" placeholder="<?php _e('Enter your email', 'extrachill-newsletter'); ?>" required />
			<button type="submit"><?php _e('Subscribe', 'extrachill-newsletter'); ?></button>
		</form>
	</div>
	<?php
}

/**
 * Plugin Architecture
 *
 * Core functionality is modularized across separate include files:
 *
 * - newsletter-post-type.php: Custom post type registration and meta boxes
 * - newsletter-sendy-integration.php: Sendy API integration for campaigns and subscriptions
 * - newsletter-ajax-handlers.php: AJAX handlers for all subscription forms
 * - newsletter-hooks.php: Hook functions for sidebar newsletter integration
 *
 * Templates are located in /templates/ directory and override theme templates.
 * Assets are enqueued conditionally based on page context and requirements.
 * All functionality maintains complete backward compatibility with existing theme integration.
 */