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
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_navigation_assets');







function extrachill_enqueue_wp_embed_for_bbpress() {
    if ( is_admin() ) {
        return;
    }

    if ( function_exists( 'is_bbpress' ) && is_bbpress() ) {
        wp_enqueue_script( 'wp-embed' );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_wp_embed_for_bbpress', 20 );

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
add_action( 'enqueue_block_assets', 'extrachill_enqueue_root_styles', 5 );

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
            array('extrachill-root'),
            filemtime( $taxonomy_badges_path )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_taxonomy_badges', 10 );

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
}
add_action( 'blocks_everywhere_enqueue_iframe_assets', 'extrachill_enqueue_blocks_everywhere_iframe_assets' );

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
    if ( is_archive() || is_search() || get_query_var('extrachill_blog_archive') ) {
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
        array(),
        filemtime( get_template_directory() . '/assets/js/shared-tabs.js' ),
        true
    );
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_shared_tabs', 5 );

function extrachill_register_mini_dropdown() {
    wp_register_script(
        'extrachill-mini-dropdown',
        get_template_directory_uri() . '/assets/js/mini-dropdown.js',
        array(),
        filemtime( get_template_directory() . '/assets/js/mini-dropdown.js' ),
        true
    );
}
add_action( 'wp_enqueue_scripts', 'extrachill_register_mini_dropdown', 5 );

function extrachill_register_share_assets() {
    wp_register_style(
        'extrachill-share',
        get_template_directory_uri() . '/assets/css/share.css',
        array(),
        filemtime( get_template_directory() . '/assets/css/share.css' )
    );

    wp_register_script(
        'extrachill-share',
        get_template_directory_uri() . '/assets/js/share.js',
        array( 'extrachill-mini-dropdown' ),
        filemtime( get_template_directory() . '/assets/js/share.js' ),
        true
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



function extrachill_enqueue_view_tracking() {
    if (!is_singular() || is_preview()) {
        return;
    }

    $js_path = get_template_directory() . '/assets/js/view-tracking.js';
    if (!file_exists($js_path)) {
        return;
    }

    wp_enqueue_script(
        'extrachill-view-tracking',
        get_template_directory_uri() . '/assets/js/view-tracking.js',
        array(),
        filemtime($js_path),
        true
    );

    wp_localize_script('extrachill-view-tracking', 'ecViewTracking', array(
        'postId'   => get_the_ID(),
        'endpoint' => rest_url('extrachill/v1/analytics/view'),
    ));
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_view_tracking');