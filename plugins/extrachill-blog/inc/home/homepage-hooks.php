<?php
/**
 * Homepage Hook Registration
 *
 * Registers all homepage content sections for extrachill.com (Blog ID 1).
 * Hooks into the theme's extrachill_homepage_content action.
 *
 * @package ExtraChillBlog
 * @since 0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render the complete homepage content for extrachill.com
 */
function extrachill_blog_render_homepage() {
	require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-queries.php';

	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/hero.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-3x3-grid.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-more-recent-posts.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-extrachill-link.php';
	?>
	<div class="home-final">
		<?php
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-about.php';
		do_action( 'extrachill_render_newsletter_form', 'homepage' );
		?>
	</div>
	<?php
}
add_action( 'extrachill_homepage_content', 'extrachill_blog_render_homepage', 10 );

/**
 * Enqueue homepage styles on front page
 */
function extrachill_blog_enqueue_home_styles() {
	if ( ! is_front_page() ) {
		return;
	}

	$css_path = EXTRACHILL_BLOG_PLUGIN_DIR . 'assets/css/home.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-blog-home',
			EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/home.css',
			array( 'extrachill-root' ),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_enqueue_home_styles' );
