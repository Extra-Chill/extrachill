<?php
/**
 * Asset Management
 *
 * Conditional asset loading with filemtime() versioning per WordPress convention.
 * File existence checks before enqueuing, context-aware loading throughout.
 *
 * @package ExtraChill
 * @since 1.0.0
 */

function extrachill_enqueue_navigation_assets() {
	$nav_js_path = get_template_directory() . '/assets/js/nav-menu.js';
	if ( file_exists( $nav_js_path ) ) {
		wp_enqueue_script(
			'extrachill-nav-menu',
			get_template_directory_uri() . '/assets/js/nav-menu.js',
			array(),
			filemtime( $nav_js_path ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_navigation_assets' );

/**
 * Defer non-critical CSS to unblock rendering.
 *
 * Converts render-blocking <link rel="stylesheet"> tags to non-blocking by
 * using the media="print" swap technique. The browser loads the CSS in the
 * background and applies it once loaded, allowing the page to paint immediately
 * with only the critical theme CSS.
 *
 * @since 2.3.1
 *
 * @param string $html   The <link> tag HTML.
 * @param string $handle The stylesheet handle.
 * @return string Modified HTML with deferred loading.
 */
function extrachill_defer_non_critical_css( string $html, string $handle ): string {
	// Stylesheets that are safe to defer (below-fold or interaction-dependent).
	$deferred_handles = array(
		'extrachill-taxonomy-badges',   // Sidebar/below-fold badge colors (35 KB).
		'wp-block-library',             // Gutenberg block styles for all blocks (132 KB).
		'extrachill-newsletter-forms',  // Newsletter form in sidebar (6 KB).
		'chubes-gallery-lightbox',      // Only needed on lightbox click (1 KB).
		'extrachill-multisite-community-activity', // Sidebar widget (1.4 KB).
	);

	if ( ! in_array( $handle, $deferred_handles, true ) ) {
		return $html;
	}

	// media="print" prevents render-blocking. onload swaps to "all" once loaded.
	// <noscript> fallback ensures CSS loads when JS is disabled.
	$html = str_replace(
		"media='all'",
		"media='print' onload=\"this.media='all'\"",
		$html
	);
	$html = str_replace(
		'media="all"',
		'media="print" onload="this.media=\'all\'"',
		$html
	);

	// Add noscript fallback.
	$noscript = str_replace( array( " media='print'", ' media="print"' ), '', $html );
	$noscript = str_replace( array( " onload=\"this.media='all'\"", " onload=\"this.media='all'\"" ), '', $noscript );
	$html    .= '<noscript>' . $noscript . '</noscript>';

	return $html;
}
add_filter( 'style_loader_tag', 'extrachill_defer_non_critical_css', 10, 2 );







function extrachill_enqueue_wp_embed_for_bbpress() {
	if ( is_admin() ) {
		return;
	}

	if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
		wp_enqueue_script( 'wp-embed' );
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_wp_embed_for_bbpress', 20 );

/**
 * Enqueue root design tokens on the frontend.
 *
 * For the wp-admin block editor, root.css is delivered via add_editor_style()
 * in functions.php — that pipeline injects CSS into the editor iframe without
 * leaking onto the outer admin page.
 */
function extrachill_enqueue_root_styles() {
	$css_path = get_stylesheet_directory() . '/assets/css/root.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-root',
			get_stylesheet_directory_uri() . '/assets/css/root.css',
			array(),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_root_styles', 5 );

/*
 * Editor iframe styles (root.css, editor-style.css, single-post.css,
 * block-editor.css) are delivered via add_editor_style() in functions.php.
 * That pipeline injects CSS into the iframe via EditorStyles React component
 * without leaking onto the outer wp-admin page.
 *
 * Previously extrachill_enqueue_editor_iframe_styles() used enqueue_block_assets
 * which fires on BOTH the outer admin page and the iframe, causing style.css
 * and block-editor.css to leak onto wp-admin chrome.
 */

function extrachill_enqueue_embed_iframe_styles() {
	$root_css_path = get_stylesheet_directory() . '/assets/css/root.css';
	if ( file_exists( $root_css_path ) ) {
		if ( ! wp_style_is( 'extrachill-root', 'registered' ) ) {
			wp_register_style(
				'extrachill-root',
				get_stylesheet_directory_uri() . '/assets/css/root.css',
				array(),
				filemtime( $root_css_path )
			);
		}

		wp_enqueue_style( 'extrachill-root' );
	}

	$embed_css_path = get_stylesheet_directory() . '/assets/css/embed.css';
	if ( file_exists( $embed_css_path ) ) {
		wp_enqueue_style(
			'extrachill-embed',
			get_stylesheet_directory_uri() . '/assets/css/embed.css',
			array( 'extrachill-root' ),
			filemtime( $embed_css_path )
		);
	}
}
add_action( 'embed_head', 'extrachill_enqueue_embed_iframe_styles', 11 );

function extrachill_enqueue_taxonomy_badges() {
	$taxonomy_badges_path = get_stylesheet_directory() . '/assets/css/taxonomy-badges.css';
	if ( file_exists( $taxonomy_badges_path ) ) {
		wp_enqueue_style(
			'extrachill-taxonomy-badges',
			get_stylesheet_directory_uri() . '/assets/css/taxonomy-badges.css',
			array( 'extrachill-root' ),
			filemtime( $taxonomy_badges_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_taxonomy_badges', 10 );

/**
 * Enqueue EC block editor branding on host page when Blocks Everywhere is active.
 *
 * Sources from @extrachill/tokens css/block-editor.css. Styles scoped under
 * .gutenberg-support apply to the host page (toolbar, popovers, sidebar).
 * Iframe styles from the same file are injected via
 * blocks_everywhere_enqueue_iframe_assets.
 */
function extrachill_enqueue_block_editor_branding() {
	if ( ! class_exists( 'Automattic\\Blocks_Everywhere\\Blocks_Everywhere' ) ) {
		return;
	}

	$block_editor_css_path = get_stylesheet_directory() . '/assets/css/block-editor.css';
	if ( file_exists( $block_editor_css_path ) ) {
		wp_enqueue_style(
			'extrachill-block-editor',
			get_stylesheet_directory_uri() . '/assets/css/block-editor.css',
			array( 'extrachill-root' ),
			filemtime( $block_editor_css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_block_editor_branding', 15 );

function extrachill_modify_default_style() {
	wp_dequeue_style( 'extrachill-style' );
	wp_deregister_style( 'extrachill-style' );

	wp_enqueue_style(
		'extrachill-style',
		get_stylesheet_uri(),
		array( 'extrachill-root' ),
		filemtime( get_template_directory() . '/style.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_modify_default_style', 20 );

function extrachill_enqueue_blocks_everywhere_iframe_assets() {
	$root_css_path = get_stylesheet_directory() . '/assets/css/root.css';
	if ( file_exists( $root_css_path ) ) {
		if ( ! wp_style_is( 'extrachill-root', 'registered' ) ) {
			wp_register_style(
				'extrachill-root',
				get_stylesheet_directory_uri() . '/assets/css/root.css',
				array(),
				filemtime( $root_css_path )
			);
		}

		wp_enqueue_style( 'extrachill-root' );
	}

	$theme_style_path = get_template_directory() . '/style.css';
	if ( file_exists( $theme_style_path ) ) {
		if ( ! wp_style_is( 'extrachill-style', 'registered' ) ) {
			wp_register_style(
				'extrachill-style',
				get_stylesheet_uri(),
				array( 'extrachill-root' ),
				filemtime( $theme_style_path )
			);
		}

		wp_enqueue_style( 'extrachill-style' );
	}

	// EC block editor branding (from @extrachill/tokens).
	$block_editor_css_path = get_stylesheet_directory() . '/assets/css/block-editor.css';
	if ( file_exists( $block_editor_css_path ) ) {
		if ( ! wp_style_is( 'extrachill-block-editor', 'registered' ) ) {
			wp_register_style(
				'extrachill-block-editor',
				get_stylesheet_directory_uri() . '/assets/css/block-editor.css',
				array( 'extrachill-root' ),
				filemtime( $block_editor_css_path )
			);
		}

		wp_enqueue_style( 'extrachill-block-editor' );
	}
}
add_action( 'blocks_everywhere_enqueue_iframe_assets', 'extrachill_enqueue_blocks_everywhere_iframe_assets' );

/**
 * Strip wp-admin-only editor stylesheets from non-admin block editor contexts.
 *
 * editor-style-admin.css and single-post.css both assume the wp-admin post
 * editor shape — single centered content column at --content-width with outer
 * canvas padding and a post title wrapper. They look wrong inside the compact
 * iframe editors used by Blocks Everywhere (bbPress) and Studio.
 *
 * Theme registers them via add_editor_style() so they always end up in
 * settings['styles'] from get_block_editor_theme_styles(). This filter walks
 * the array on the way out and drops the admin-only entries when the request
 * is not wp-admin.
 *
 * editor-style.css (typography, lists, blockquotes) and root.css and
 * block-editor.css (EC inserter branding) all stay — they're universal.
 *
 * @param array $settings Block editor settings.
 * @return array
 */
function extrachill_filter_admin_only_editor_styles( $settings ) {
	if ( is_admin() ) {
		return $settings;
	}

	if ( ! isset( $settings['styles'] ) || ! is_array( $settings['styles'] ) ) {
		return $settings;
	}

	$admin_only_files = array(
		'/assets/css/editor-style-admin.css',
		'/assets/css/single-post.css',
	);

	$settings['styles'] = array_values(
		array_filter(
			$settings['styles'],
			static function ( $style ) use ( $admin_only_files ) {
				if ( empty( $style['baseURL'] ) ) {
					return true;
				}

				foreach ( $admin_only_files as $needle ) {
					if ( false !== strpos( $style['baseURL'], $needle ) ) {
						return false;
					}
				}

				return true;
			}
		)
	);

	return $settings;
}
add_filter( 'block_editor_settings_all', 'extrachill_filter_admin_only_editor_styles', 20 );

function extrachill_enqueue_single_post_styles() {
	$single_post_types = apply_filters( 'extrachill_single_post_style_post_types', array( 'post' ) );
	if ( is_singular( $single_post_types ) || is_page() ) {
		$css_path = get_stylesheet_directory() . '/assets/css/single-post.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'extrachill-single-post',
				get_stylesheet_directory_uri() . '/assets/css/single-post.css',
				array( 'extrachill-root', 'extrachill-style' ),
				filemtime( $css_path )
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_single_post_styles', 20 );

function extrachill_hide_settings_page_title( $show, $post_id ) {
	if ( is_page( 'settings' ) ) {
		return false;
	}

	return $show;
}
add_filter( 'extrachill_show_page_title', 'extrachill_hide_settings_page_title', 10, 2 );

function extrachill_enqueue_archive_styles() {
	if ( is_archive() || is_search() || get_query_var( 'extrachill_blog_archive' ) ) {
		$css_path = get_stylesheet_directory() . '/assets/css/archive.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'extrachill-archive',
				get_stylesheet_directory_uri() . '/assets/css/archive.css',
				array( 'extrachill-root', 'extrachill-style' ),
				filemtime( $css_path )
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_archive_styles', 20 );

function extrachill_enqueue_search_styles() {
	if ( is_search() ) {
		$css_path = get_stylesheet_directory() . '/assets/css/search.css';
		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'extrachill-search',
				get_stylesheet_directory_uri() . '/assets/css/search.css',
				array( 'extrachill-root', 'extrachill-style' ),
				filemtime( $css_path )
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_search_styles', 20 );

function extrachill_enqueue_sidebar_styles() {
	$sidebar_post_types = apply_filters( 'extrachill_sidebar_style_post_types', array( 'post' ) );
	if ( is_singular( $sidebar_post_types ) || is_404() ) {
		$sidebar_override = apply_filters( 'extrachill_sidebar_content', false );

		if ( false === $sidebar_override ) {
			$css_path = get_stylesheet_directory() . '/assets/css/sidebar.css';
			if ( file_exists( $css_path ) ) {
				wp_enqueue_style(
					'extrachill-sidebar',
					get_stylesheet_directory_uri() . '/assets/css/sidebar.css',
					array( 'extrachill-root', 'extrachill-style' ),
					filemtime( $css_path )
				);
			}
		}
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_sidebar_styles', 20 );

function extrachill_register_shared_tabs() {
	wp_register_style(
		'extrachill-shared-tabs',
		get_template_directory_uri() . '/assets/css/shared-tabs.css',
		array(),
		filemtime( get_template_directory() . '/assets/css/shared-tabs.css' )
	);

	wp_register_script(
		'extrachill-shared-tabs',
		get_template_directory_uri() . '/assets/js/shared-tabs.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/shared-tabs.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_shared_tabs', 5 );

function extrachill_register_mini_dropdown() {
	wp_register_script(
		'extrachill-mini-dropdown',
		get_template_directory_uri() . '/assets/js/mini-dropdown.js',
		array(),
		filemtime( get_template_directory() . '/assets/js/mini-dropdown.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_mini_dropdown', 5 );

function extrachill_register_share_assets() {
	wp_register_script(
		'extrachill-share',
		get_template_directory_uri() . '/assets/js/share.js',
		array( 'extrachill-mini-dropdown' ),
		filemtime( get_template_directory() . '/assets/js/share.js' ),
		array(
			'strategy'  => 'defer',
			'in_footer' => true,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_share_assets', 5 );

function extrachill_enqueue_network_dropdown_assets() {
	// Only load where network dropdown is rendered (subsite homepages, main blog archive)
	if ( ! is_front_page() && ! get_query_var( 'extrachill_blog_archive' ) ) {
		return;
	}

	$css_path = get_template_directory() . '/assets/css/network-dropdown.css';
	if ( file_exists( $css_path ) ) {
		wp_enqueue_style(
			'extrachill-network-dropdown',
			get_template_directory_uri() . '/assets/css/network-dropdown.css',
			array( 'extrachill-root' ),
			filemtime( $css_path )
		);
	}
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_network_dropdown_assets', 10 );

/**
 * Output custom CSS variables via filter.
 *
 * Allows plugins and child themes to override theme CSS variables (colors, fonts, etc.)
 * without modifying theme files. Filter returns associative array of variable => value pairs.
 *
 * @since 2.0.0
 */
function extrachill_output_custom_css_variables() {
	$custom_vars = apply_filters( 'extrachill_css_variables', array() );

	if ( empty( $custom_vars ) || ! is_array( $custom_vars ) ) {
		return;
	}

	$css_rules = array();
	foreach ( $custom_vars as $property => $value ) {
		if ( strpos( $property, '--' ) === 0 ) {
			$css_rules[] = esc_attr( $property ) . ': ' . esc_attr( $value );
		}
	}

	if ( empty( $css_rules ) ) {
		return;
	}

	echo '<style id="extrachill-custom-css-variables">:root { ' . implode( '; ', $css_rules ) . '; }</style>' . "\n";
}
add_action( 'wp_head', 'extrachill_output_custom_css_variables', 20 );
