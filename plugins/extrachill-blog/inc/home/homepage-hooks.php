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
 * Schedule the Recently Shipped warmer without performing a frontend fetch.
 */
function extrachill_blog_schedule_recently_shipped_refresh() {
	if ( wp_next_scheduled( 'extrachill_blog_refresh_recently_shipped' ) ) {
		return;
	}

	wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', 'extrachill_blog_refresh_recently_shipped' );
}
add_action( 'init', 'extrachill_blog_schedule_recently_shipped_refresh' );

/**
 * Refresh the last-good Recently Shipped payload from the scheduled event.
 */
function extrachill_blog_refresh_recently_shipped() {
	require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-queries.php';

	$payload = extrachill_blog_fetch_recently_shipped();
	if ( empty( $payload ) ) {
		return;
	}

	set_transient( 'extrachill_blog_recently_shipped_last_good', $payload, WEEK_IN_SECONDS );
}
add_action( 'extrachill_blog_refresh_recently_shipped', 'extrachill_blog_refresh_recently_shipped' );

/**
 * Render the complete homepage content for extrachill.com
 */
function extrachill_blog_render_homepage() {
	require_once EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/homepage-queries.php';

	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/hero.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-3x3-grid.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-search.php';
	include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-recently-shipped.php';
	?>
	<div class="home-network-grid">
		<?php
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-community.php';
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-artist-platform.php';
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-events.php';
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-docs.php';
		include EXTRACHILL_BLOG_PLUGIN_DIR . 'inc/home/templates/section-about.php';
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
		$css_version = filemtime( $css_path );
		wp_enqueue_style(
			'extrachill-blog-home',
			EXTRACHILL_BLOG_PLUGIN_URL . 'assets/css/home.css',
			array( 'extrachill-root' ),
			false !== $css_version ? (string) $css_version : false
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_blog_enqueue_home_styles' );
