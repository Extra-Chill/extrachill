<?php
/**
 * Plugin Name: Extra Chill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 1.0.0
 * Author: Chris Huber
 * Network: true
 * Text Domain: extrachill-newsletter
 * Domain Path: /languages
 *
 * @package ExtraChillNewsletter
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_NEWSLETTER_VERSION', '1.0.0' );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EXTRACHILL_NEWSLETTER_INC_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/' );
define( 'EXTRACHILL_NEWSLETTER_TEMPLATES_DIR', EXTRACHILL_NEWSLETTER_PLUGIN_DIR . 'inc/core/templates/forms/' );
define( 'EXTRACHILL_NEWSLETTER_ASSETS_URL', EXTRACHILL_NEWSLETTER_PLUGIN_URL . 'assets/' );

/**
 * Check if current site is the newsletter site
 *
 * @since 1.0.0
 * @return bool True if on newsletter.extrachill.com (blog ID 9)
 */
function is_newsletter_site() {
	return get_current_blog_id() === 9;
}

// Core functionality (network-wide)
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/assets.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/sendy-api.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/templates/email-template.php';

// AJAX handlers (network-wide)
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'ajax/handlers.php';

// Hook integrations (network-wide)
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/breadcrumbs.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/forms.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/homepage.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/post-meta.php';
require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/hooks/sidebar.php';

// Newsletter site only
if ( is_newsletter_site() ) {
	require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/newsletter-post-type.php';

	if ( is_admin() ) {
		require_once EXTRACHILL_NEWSLETTER_INC_DIR . 'core/newsletter-settings.php';
	}
}

function newsletter_customize_post_meta( $default_meta, $post_id, $post_type ) {
	if ( $post_type !== 'newsletter' ) {
		return $default_meta;
	}

	$date = get_the_date();
	$author_id = get_post_field( 'post_author', $post_id );
	$author_name = get_the_author_meta( 'display_name', $author_id );
	$author_url = function_exists( 'ec_get_user_profile_url' )
		? ec_get_user_profile_url( $author_id )
		: get_author_posts_url( $author_id );

	$meta_html = '<div class="below-entry-meta">';
	$meta_html .= '<div class="below-entry-meta-left">';
	$meta_html .= '<div class="meta-top-row">';
	$meta_html .= sprintf(
		__( 'Sent on <time class="entry-date published newsletter-date" datetime="%s">%s</time> by <a href="%s">%s</a>', 'extrachill-newsletter' ),
		esc_attr( get_the_date( 'c', $post_id ) ),
		esc_html( $date ),
		esc_url( $author_url ),
		esc_html( $author_name )
	);
	$meta_html .= '</div>';
	$meta_html .= '</div>';
	$meta_html .= '</div>';

	return $meta_html;
}
add_filter( 'extrachill_post_meta', 'newsletter_customize_post_meta', 10, 3 );

function display_newsletter_grid_section() {
	// Only show on main blog, not on newsletter site (which has archive as homepage)
	if ( is_newsletter_site() ) {
		return;
	}

	// Query newsletters from newsletter site (blog ID 9)
	switch_to_blog( 9 );
	$newsletter_posts = get_posts( array( 'numberposts' => 3, 'post_type' => 'newsletter' ) );
	$archive_url = home_url(); // newsletter.extrachill.com homepage
	restore_current_blog();
	?>
	<div class="home-3x3-stacked-section">
		<div class="home-3x3-header">
			<span class="home-3x3-label">Latest Newsletters</span>
			<a class="home-3x3-archive-link button-3 button-small" href="<?php echo esc_url( $archive_url ); ?>">View All</a>
		</div>
		<div class="home-3x3-list">
			<?php if ( ! empty( $newsletter_posts ) ) : ?>
				<?php foreach ( $newsletter_posts as $newsletter ) : ?>
					<?php
					// Get permalink from newsletter site
					switch_to_blog( 9 );
					$permalink = get_permalink( $newsletter->ID );
					$title = get_the_title( $newsletter->ID );
					$date = get_the_date( '', $newsletter->ID );
					restore_current_blog();
					?>
					<a href="<?php echo esc_url( $permalink ); ?>" class="home-3x3-card home-3x3-card-link" aria-label="<?php echo esc_attr( $title ); ?>">
						<span class="home-3x3-title"><?php echo esc_html( $title ); ?></span>
						<span class="home-3x3-meta">Sent <?php echo esc_html( $date ); ?></span>
					</a>
				<?php endforeach; ?>
			<?php else : ?>
				<div class="home-3x3-card home-3x3-empty">No newsletters yet.</div>
			<?php endif; ?>
		</div>
	</div>
	<?php
}
add_action( 'extrachill_home_grid_bottom_right', 'display_newsletter_grid_section' );

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

function display_newsletter_homepage_form() {
	// Only show on main blog, not on newsletter site
	if (is_newsletter_site()) {
		return;
	}

	if (!is_front_page()) {
		return;
	}

	$settings = get_site_option('extrachill_newsletter_settings', array());
	if (empty($settings['enable_homepage'])) {
		return;
	}

	include EXTRACHILL_NEWSLETTER_TEMPLATES_DIR . 'homepage-section.php';
}
add_action( 'extrachill_home_final_right', 'display_newsletter_homepage_form' );

function extrachill_newsletter_activate() {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'extrachill_newsletter_activate' );

function extrachill_newsletter_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'extrachill_newsletter_deactivate' );

/**
 * Register default newsletter integration contexts via filter system
 *
 * Integration contexts registered:
 * - navigation: Site navigation menu subscription form
 * - homepage: Main homepage subscription section
 * - popup: Modal popup subscription (backend only, no frontend display)
 * - archive: Newsletter archive page subscription
 * - content: After post content subscription
 * - footer: Above footer subscription
 *
 * Settings configured via Newsletter → Settings on newsletter.extrachill.com:
 * - Enable/disable toggle per integration
 * - Sendy list ID per integration
 * - Global Sendy API configuration
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

