<?php
/**
 * Plugin Name: Extra Chill Newsletter
 * Description: Complete newsletter system with Sendy integration for email campaigns and subscriptions. Provides custom newsletter post type, multiple subscription forms, email template generation, and admin management tools.
 * Version: 0.1.5
 * Author: Chris Huber
 * Network: true
 * Text Domain: extrachill-newsletter
 * Domain Path: /languages
 *
 * @package ExtraChillNewsletter
 * @since 0.1.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EXTRACHILL_NEWSLETTER_VERSION', '0.1.5' );
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
	return get_current_blog_id() === 9;
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
			'wrapper_class'     => 'home-newsletter-signup newsletter-homepage-section',
			'heading'           => __( 'A Note from the Editor', 'extrachill-newsletter' ),
			'heading_level'     => 'h2',
			'description'       => __( 'Stories, reflections, and music industry insights from the underground.', 'extrachill-newsletter' ),
			'layout'            => 'horizontal',
			'placeholder'       => __( 'Your email for the inside scoop...', 'extrachill-newsletter' ),
			'button_text'       => __( 'Get the Letter', 'extrachill-newsletter' ),
			'show_archive_link' => true,
			'archive_link_text' => __( 'Browse past newsletters', 'extrachill-newsletter' ),
			'use_form_group'    => false,
		),
		'navigation' => array(
			'wrapper_element'   => 'li',
			'wrapper_class'     => 'menu-newsletter',
			'heading'           => null,
			'description'       => null,
			'layout'            => 'inline',
			'placeholder'       => __( 'Enter your email', 'extrachill-newsletter' ),
			'button_text'       => __( 'Subscribe', 'extrachill-newsletter' ),
			'show_archive_link' => true,
			'archive_link_text' => __( 'See past newsletters', 'extrachill-newsletter' ),
			'use_form_group'    => false,
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
			'use_form_group'    => true,
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
			'use_form_group'    => false,
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

function extrachill_newsletter_activate() {
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'extrachill_newsletter_activate' );

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

