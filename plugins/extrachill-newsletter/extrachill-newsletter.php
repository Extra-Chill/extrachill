<?php
/**
 * Plugin Name: Extra Chill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 1.0.0
 * Author: Chris Huber
 * Text Domain: extrachill-newsletter
 * Domain Path: /languages
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_NEWSLETTER_VERSION', '1.0' );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_INC_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/' );
define( 'EXTRACHILL_NEWSLETTER_TEMPLATES_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'templates/' );
define( 'EXTRACHILL_NEWSLETTER_ASSETS_URL', EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/' );

require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'newsletter-post-type.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'newsletter-sendy-integration.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'newsletter-ajax-handlers.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'newsletter-hooks.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'newsletter-popup.php';

if ( is_admin() ) {
	require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'admin/newsletter-settings.php';
}

function enqueue_newsletter_assets() {
	$forms_css_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter-forms.css';
	if ( file_exists( $forms_css_file_path ) ) {
		wp_enqueue_style(
			'extrachill-newsletter-forms',
			EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter-forms.css',
			array(),
			filemtime( $forms_css_file_path )
		);
	}

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

	$js_file_path = EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'assets/newsletter.js';
	if ( file_exists( $js_file_path ) ) {
		wp_enqueue_script(
			'extrachill-newsletter',
			EXTRACHILL_NEWSLETTER_ASSETS_URL . 'newsletter.js',
			array( 'jquery' ),
			filemtime( $js_file_path ),
			true
		);

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
}
add_action( 'wp_enqueue_scripts', 'enqueue_newsletter_assets' );

function display_newsletter_homepage_section() {
	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_homepage'])) {
		return;
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'homepage-section.php';
}
add_action( 'extrachill_home_final_right', 'display_newsletter_homepage_section' );

function display_newsletter_grid_section() {
    global $post;
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

function newsletter_template_loader( $template ) {
	if ( is_singular( 'newsletter' ) ) {
		$plugin_template = locate_newsletter_template( 'single-newsletter.php' );
		if ( $plugin_template ) {
			return $plugin_template;
		}
	}

	return $template;
}
add_filter( 'template_include', 'newsletter_template_loader' );

function locate_newsletter_template( $template_name ) {
	$plugin_template_path = EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . $template_name;

	if ( file_exists( $plugin_template_path ) ) {
		return $plugin_template_path;
	}

	return false;
}

function display_newsletter_navigation_form() {
	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_navigation'])) {
		return;
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'navigation-form.php';
}
add_action( 'extrachill_navigation_before_social_links', 'display_newsletter_navigation_form' );

function display_newsletter_content_form() {
	if (is_front_page()) {
		return;
	}

	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_content'])) {
		return;
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'content-form.php';
}
add_action( 'extrachill_after_post_content', 'display_newsletter_content_form' );

function display_newsletter_footer_form() {
	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_footer'])) {
		return;
	}

	if (is_front_page()) {
		if (has_action('extrachill_home_final_right')) {
			return;
		}
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'footer-form.php';
}
add_action( 'extrachill_above_footer', 'display_newsletter_footer_form' );

function extrachill_newsletter_activate() {
	create_newsletter_post_type();

	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'extrachill_newsletter_activate' );

function extrachill_newsletter_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'extrachill_newsletter_deactivate' );

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

	return $integrations;
}
add_filter('newsletter_form_integrations', 'newsletter_register_default_integrations');

function newsletter_display_festival_wire_tip_form() {
	if (!newsletter_integration_enabled('enable_festival_wire_tip')) {
		return;
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'festival-wire-tip-form.php';
}
add_action('extrachill_after_news_wire', 'newsletter_display_festival_wire_tip_form');

function newsletter_init_integration_actions() {
	$integrations = get_newsletter_integrations();

	foreach ($integrations as $context => $integration) {
		add_action("newsletter_display_{$context}", function($email = null) use ($context, $integration) {
			if ($email) {
				return extrachill_multisite_subscribe($email, $context);
			} else {
				newsletter_render_integration_form($context, $integration);
			}
		});
	}
}
add_action('init', 'newsletter_init_integration_actions', 20); // Run after integrations are registered

function newsletter_render_integration_form($context, $integration) {
	if (!newsletter_integration_enabled($integration['enable_key'])) {
		return;
	}

	$template_candidates = array(
		"newsletter-form-{$context}.php",
		'newsletter-form-default.php'
	);

	$template_loaded = false;

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

	if (!$template_loaded) {
		newsletter_render_fallback_form($context, $integration);
	}
}

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

