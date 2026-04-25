<?php
/**
 * ExtraChill Theme Bootstrap
 *
 * WordPress multisite theme serving all 10 sites with direct require_once loading pattern.
 * Uses centralized template routing (template-router.php) and conditional asset loading (assets.php).
 *
 * @package ExtraChill
 * @since 1.0.0
 */

add_theme_support( 'responsive-embeds' );
add_theme_support( 'wp-block-styles' );
add_theme_support( 'align-wide' );

add_filter( 'should_load_separate_core_block_assets', '__return_true' );

if ( ! function_exists( 'extrachill_setup' ) ) :
	function extrachill_setup() {
		load_theme_textdomain( 'extrachill', get_template_directory() . '/languages' );
		add_theme_support( 'automatic-feed-links' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'woocommerce' );
		add_theme_support( 'title-tag' );
		add_post_type_support( 'page', 'excerpt' );

		add_theme_support( 'editor-styles' );
		add_editor_style( 'assets/css/root.css' );
		add_editor_style( 'assets/css/editor-style.css' );
		add_editor_style( 'assets/css/single-post.css' );
		add_editor_style( 'assets/css/block-editor.css' );

		add_theme_support(
			'html5',
			array(
				'search-form',
				'comment-form',
				'comment-list',
				'gallery',
				'caption',
				'script',
			)
		);

		add_theme_support(
			'custom-logo',
			array(
				'flex-width'  => true,
				'flex-height' => true,
			)
		);
	}
endif;
add_action( 'after_setup_theme', 'extrachill_setup' );

/**
 * Register custom image sizes for gallery and lightbox use.
 *
 * - gallery: optimized for gallery column display (cropped to consistent aspect ratio)
 * - 2048x2048 is intentionally NOT removed — it serves as a useful intermediate
 *   for lightbox display, avoiding the jump from 1024px (large) to full-size originals.
 * - thumbnail is removed because it's unused in the theme.
 */
function extrachill_unregister_image_sizes() {
	remove_image_size( 'thumbnail' );
}
add_action( 'init', 'extrachill_unregister_image_sizes', 99 );

function extrachill_register_image_sizes() {
	add_image_size( 'gallery', 800, 600, true );
}
add_action( 'after_setup_theme', 'extrachill_register_image_sizes' );

/**
 * Fix gallery image sizes attribute for responsive loading.
 *
 * WordPress defaults the sizes attribute to "(max-width: {width}px) 100vw, {width}px"
 * which tells the browser that gallery images need full-viewport-width sources.
 * In reality, gallery images display in 2-3 columns at ~300px height, so the browser
 * downloads 1024px images when 400-600px would suffice.
 *
 * Uses the_content filter (late priority) because wp_content_img_tag only receives
 * the <img> tag, not the surrounding <figure class="wp-block-image"> wrapper. We
 * need the wrapper context to detect gallery images.
 *
 * @param string $content Post content HTML.
 * @return string Modified content with gallery-aware sizes attributes.
 */
function extrachill_gallery_image_sizes( string $content ): string {
	if ( strpos( $content, 'wp-block-gallery' ) === false ) {
		return $content;
	}

	// Gallery images display in columns: ~50vw on tablet, ~33vw on desktop.
	// On mobile they go full-width. This tells the browser to pick appropriately
	// sized sources instead of always downloading the largest available.
	$gallery_sizes = '(max-width: 480px) 100vw, (max-width: 768px) 50vw, 33vw';

	// Match <figure class="wp-block-image ...">...<img ... sizes="...">...</figure>
	// and replace the sizes attribute on images inside gallery figures.
	$content = preg_replace_callback(
		'/<figure\s[^>]*class="[^"]*wp-block-image[^"]*"[^>]*>.*?<\/figure>/s',
		function ( $match ) use ( $gallery_sizes ) {
			return preg_replace(
				'/sizes="[^"]*"/',
				'sizes="' . esc_attr( $gallery_sizes ) . '"',
				$match[0]
			);
		},
		$content
	);

	return $content;
}
add_filter( 'the_content', 'extrachill_gallery_image_sizes', 999 );

define( 'EXTRACHILL_PARENT_DIR', get_template_directory() );

define( 'EXTRACHILL_THEME_VERSION', '2.0.14' );

define( 'EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc' );
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/pagination.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/no-results.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/share.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/social-links.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/taxonomy-badges.php';

require_once EXTRACHILL_INCLUDES_DIR . '/core/actions.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/assets.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/og-brand-tokens.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/icons.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/notices.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/rewrite.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/site-title.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/template-router.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/breadcrumbs.php';
require_once EXTRACHILL_INCLUDES_DIR . '/header/header-search.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/custom-taxonomies.php';


require_once EXTRACHILL_INCLUDES_DIR . '/sidebar/recent-posts.php';

require_once EXTRACHILL_INCLUDES_DIR . '/footer/back-to-home-link.php';

require_once EXTRACHILL_INCLUDES_DIR . '/core/editor/bandcamp-embeds.php';
require_once EXTRACHILL_INCLUDES_DIR . '/core/editor/instagram-embeds.php';

require_once EXTRACHILL_INCLUDES_DIR . '/archives/archive-custom-sorting.php';

require_once EXTRACHILL_INCLUDES_DIR . '/components/filter-bar.php';
require_once EXTRACHILL_INCLUDES_DIR . '/components/filter-bar-defaults.php';

require_once EXTRACHILL_INCLUDES_DIR . '/core/templates/searchform.php';

function extrachill_allow_svg_uploads( $file_types ) {
	$file_types['svg'] = 'image/svg+xml';
	return $file_types;
}
add_filter( 'upload_mimes', 'extrachill_allow_svg_uploads' );


function extrachill_prevent_admin_styles_on_frontend() {
	if ( is_admin() ) {
		return;
	}

	if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
		wp_dequeue_style( 'admin-bar' );
	}

	wp_dequeue_style( 'imagify-admin-bar' );

	wp_dequeue_style( 'wp-block-library-theme' );
}
add_action( 'wp_enqueue_scripts', 'extrachill_prevent_admin_styles_on_frontend', 100 );

function extrachill_add_sticky_header_class( $classes ) {
	if ( apply_filters( 'extrachill_enable_sticky_header', true ) ) {
		$classes[] = 'sticky-header';
	}
	return $classes;
}
add_filter( 'body_class', 'extrachill_add_sticky_header_class' );

function extrachill_dequeue_jquery_frontend() {
	if ( is_admin() ) {
		return;
	}

	$should_dequeue = apply_filters( 'extrachill_dequeue_jquery_frontend', true );
	if ( ! $should_dequeue ) {
		return;
	}

	wp_dequeue_script( 'jquery' );
	wp_deregister_script( 'jquery' );
}
add_action( 'wp_enqueue_scripts', 'extrachill_dequeue_jquery_frontend', 100 );
