<?php
/**
 * ExtraChill Theme Asset Management
 *
 * Centralized conditional asset loading with performance optimizations:
 * - Page-specific CSS loading (home, single-post, archive)
 * - Root CSS variables loaded first for dependency management
 * - JavaScript enqueuing with filemtime() cache busting
 * - Admin editor style integration
 *
 * @package ExtraChill
 * @since 69.57
 */

/**
 * Enqueue archive page scripts and navigation styles
 */
function extrachill_enqueue_archive_scripts() {
    if (is_archive()) {
        $js_path = get_template_directory() . '/assets/js/chill-custom.js';
        $js_url = get_template_directory_uri() . '/assets/js/chill-custom.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('wp-innovator-custom-script', $js_url, array(), $js_version, true);
    }

    $nav_css_path = get_theme_file_path('/assets/css/nav.css');
    if ( file_exists( $nav_css_path ) ) {
        wp_enqueue_style(
            'extrachill-nav-styles',
            get_theme_file_uri('/assets/css/nav.css'),
            array(),
            filemtime( $nav_css_path ),
            'all'
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_archive_scripts');

function extrachill_enqueue_reading_progress() {
    wp_enqueue_script(
        'reading-progress-script',
        get_template_directory_uri() . '/assets/js/reading-progress.js',
        array(),
        filemtime(get_template_directory() . '/assets/js/reading-progress.js'),
        true
    );
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_reading_progress');

function extrachill_enqueue_home_styles() {
    if ( is_front_page() ) {
        $css_path = get_stylesheet_directory() . '/assets/css/home.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-home',
                get_stylesheet_directory_uri() . '/assets/css/home.css',
                array(),
                filemtime( $css_path )
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_home_styles' );

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

function extrachill_enqueue_main_styles() {
    $badge_colors_path = get_stylesheet_directory() . '/assets/css/badge-colors.css';
    if ( file_exists( $badge_colors_path ) ) {
        wp_enqueue_style(
            'badge-colors',
            get_stylesheet_directory_uri() . '/assets/css/badge-colors.css',
            array('extrachill-root'),
            filemtime( $badge_colors_path )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_main_styles', 10 );

function extrachill_modify_default_style() {
    wp_dequeue_style('extrachill-style');
    wp_deregister_style('extrachill-style');

    wp_enqueue_style(
        'extrachill-style',
        get_stylesheet_uri(),
        array('extrachill-root'),
        filemtime(get_template_directory() . '/style.css')
    );
}
add_action('wp_enqueue_scripts', 'extrachill_modify_default_style', 20);

function extrachill_enqueue_single_post_styles() {
    if ( is_singular('post') ) {
        $css_path = get_stylesheet_directory() . '/assets/css/single-post.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-single-post',
                get_stylesheet_directory_uri() . '/assets/css/single-post.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime( $css_path )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_single_post_styles', 20);

function extrachill_enqueue_archive_styles() {
    if ( is_archive() || is_search() || is_page_template('page-templates/all-posts.php') ) {
        $css_path = get_stylesheet_directory() . '/assets/css/archive.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-archive',
                get_stylesheet_directory_uri() . '/assets/css/archive.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime( $css_path )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_archive_styles', 20);

/**
 * Enqueue admin editor styles with root CSS dependencies
 */
function extrachill_enqueue_admin_styles($hook) {
    if ($hook == 'post.php' || $hook == 'post-new.php') {
        $root_css_path = get_stylesheet_directory() . '/assets/css/root.css';
        if (file_exists($root_css_path)) {
            wp_enqueue_style(
                'extrachill-admin-root',
                get_stylesheet_directory_uri() . '/assets/css/root.css',
                array(),
                filemtime($root_css_path)
            );
        }

        $admin_css_path = get_stylesheet_directory() . '/assets/css/editor-style.css';
        if (file_exists($admin_css_path)) {
            wp_enqueue_style(
                'extrachill-admin-editor',
                get_stylesheet_directory_uri() . '/assets/css/editor-style.css',
                array('extrachill-admin-root'),
                filemtime($admin_css_path)
            );
        }
    }
}
add_action('admin_enqueue_scripts', 'extrachill_enqueue_admin_styles');