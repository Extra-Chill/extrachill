<?php
/**
 * Asset Management
 *
 * Conditional asset loading with filemtime() versioning per WordPress convention.
 * File existence checks before enqueuing, context-aware loading throughout.
 *
 * @package ExtraChill
 * @since 69.60
 */

function extrachill_enqueue_navigation_assets() {
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

    $nav_js_path = get_template_directory() . '/assets/js/nav-menu.js';
    if ( file_exists( $nav_js_path ) ) {
        wp_enqueue_script(
            'extrachill-nav-menu',
            get_template_directory_uri() . '/assets/js/nav-menu.js',
            array(),
            filemtime( $nav_js_path ),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_navigation_assets');

function extrachill_enqueue_fontawesome() {
    wp_enqueue_style('font-awesome', '//cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css');
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_fontawesome');

function extrachill_enqueue_archive_scripts() {
    if (is_archive()) {
        $js_path = get_template_directory() . '/assets/js/chill-custom.js';
        $js_url = get_template_directory_uri() . '/assets/js/chill-custom.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('wp-innovator-custom-script', $js_url, array(), $js_version, true);
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_archive_scripts');

function extrachill_enqueue_reading_progress() {
    if ( ! apply_filters( 'extrachill_enable_sticky_header', true ) ) {
        return;
    }

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
        $template_override = apply_filters( 'extrachill_template_homepage', false );

        if ( ! $template_override ) {
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
    if ( is_singular( array( 'post', 'newsletter', 'festival_wire' ) ) ) {
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

function extrachill_enqueue_search_styles() {
    if ( is_search() ) {
        $css_path = get_stylesheet_directory() . '/assets/css/search.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-search',
                get_stylesheet_directory_uri() . '/assets/css/search.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime( $css_path )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_search_styles', 20);

function extrachill_enqueue_sidebar_styles() {
    if ( is_singular( array( 'post', 'newsletter', 'festival_wire' ) ) || is_404() ) {
        $sidebar_override = apply_filters( 'extrachill_sidebar_content', false );

        if ( $sidebar_override === false ) {
            $css_path = get_stylesheet_directory() . '/assets/css/sidebar.css';
            if ( file_exists( $css_path ) ) {
                wp_enqueue_style(
                    'extrachill-sidebar',
                    get_stylesheet_directory_uri() . '/assets/css/sidebar.css',
                    array('extrachill-root', 'extrachill-style'),
                    filemtime( $css_path )
                );
            }
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_sidebar_styles', 20);

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
        array( 'jquery' ),
        filemtime( get_template_directory() . '/assets/js/shared-tabs.js' ),
        true
    );
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_shared_tabs', 5 );

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