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

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

add_theme_support( 'responsive-embeds' );
add_theme_support( 'wp-block-styles' );
add_theme_support( 'align-wide' );

add_filter('should_load_separate_core_block_assets', '__return_true');

if (!function_exists('extrachill_setup')):
    function extrachill_setup()
    {
        load_theme_textdomain('extrachill', get_template_directory() . '/languages');
        add_theme_support('automatic-feed-links');
        add_theme_support('post-thumbnails');
        add_theme_support('woocommerce');
        add_theme_support('title-tag');
        add_post_type_support('page', 'excerpt');

        add_theme_support( 'editor-styles' );
        add_editor_style( 'assets/css/root.css' );
        add_editor_style( 'assets/css/editor-style.css' );
        add_editor_style( 'style.css' );
        add_editor_style( 'assets/css/single-post.css' );

    add_theme_support('html5', array(
    'search-form',
    'comment-form',
    'comment-list',
    'gallery',
    'caption',
    'script'
));

        add_theme_support('custom-logo', array(
            'flex-width' => true,
            'flex-height' => true
        ));
    }
endif;
add_action('after_setup_theme', 'extrachill_setup');

function extrachill_unregister_image_sizes() {
    remove_image_size('thumbnail');
    remove_image_size('2048x2048');
}
add_action('init', 'extrachill_unregister_image_sizes', 99);

define('EXTRACHILL_PARENT_DIR', get_template_directory());

define('EXTRACHILL_THEME_VERSION', '1.2.5');

define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/pagination.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/no-results.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/share.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/social-links.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/community-activity.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/taxonomy-badges.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/actions.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/assets.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/icons.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/notices.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/view-counts.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/rewrite.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/template-router.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/breadcrumbs.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/network-dropdown.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/header/header-search.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/custom-taxonomies.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/yoast-stuff.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/recent-posts.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/community-activity.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/footer/back-to-home-link.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/footer/online-users-stats.php');

function extrachill_remove_menu_admin_pages() {
    remove_submenu_page('themes.php', 'nav-menus.php');

    // Hide Posts and Comments menus on all sites except main blog (ID 1)
    if (get_current_blog_id() !== 1) {
        remove_menu_page('edit.php');
        remove_menu_page('edit-comments.php');
    }
}
add_action('admin_menu', 'extrachill_remove_menu_admin_pages', 999);

function extrachill_remove_customizer_menus($wp_customize) {
    $wp_customize->remove_panel('nav_menus');
}
add_action('customize_register', 'extrachill_remove_customizer_menus', 20);

require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/bandcamp-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/instagram-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/spotify-embeds.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-child-terms-dropdown.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-custom-sorting.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-filter-bar.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/artist-profile-link.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/searchform.php');

add_filter('the_content', function($content) {
    return str_replace('margin-left: 1em; margin-right: 1em;', '', $content);
});
add_filter('auto_update_theme', '__return_false');

function add_file_types_to_uploads($file_types)
{
    $new_filetypes['svg'] = 'image/svg+xml';
    return array_merge($file_types, $new_filetypes);
}
add_action('upload_mimes', 'add_file_types_to_uploads');

function wpb_password_post_filter( $where = '' ) {
    if (!is_single() && !is_admin()) {
        $where .= " AND post_password = ''";
    }
    return $where;
}
add_filter( 'posts_where', 'wpb_password_post_filter' );

add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
function wpse_cleantags_add_noindex( $robots ) {
    global $wp_query;

    if ( is_tag() && $wp_query->found_posts < 2 ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }

    return $robots;
}

if ( is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
    add_action( 'rest_api_init', 'custom_register_coauthors' );
    function custom_register_coauthors() {
        register_rest_field( 'post',
            'coauthors',
            array(
                'get_callback'    => 'custom_get_coauthors',
                'update_callback' => null,
                'schema'          => null,
            )
        );
    }

    function custom_get_coauthors( $object, $field_name, $request ) {
        $coauthors = get_coauthors($object['id']);

        $authors = array();
        if (!empty($coauthors)) {
            foreach ($coauthors as $author) {
                $authors[] = array(
                    'display_name' => $author->display_name,
                    'user_nicename' => $author->user_nicename
                );
            }
        } else {
            $default_author = get_userdata(get_post_field('post_author', $object['id']));
            if ($default_author) {
                $authors[] = array(
                    'display_name' => $default_author->display_name,
                    'user_nicename' => $default_author->user_nicename
                );
            }
        }

        return $authors;
    }
}

function add_custom_favicon() {
    if ( is_admin() ) {
        return;
    }

    $favicon_url = get_site_url() . '/favicon.ico';
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
    echo '<link rel="apple-touch-icon" href="' . esc_url($favicon_url) . '" />';
    echo '<link rel="apple-touch-icon-precomposed" href="' . esc_url($favicon_url) . '" />';
}
add_action('wp_head', 'add_custom_favicon');

function extrachill_prevent_admin_styles_on_frontend() {
    if ( is_admin() ) {
        return;
    }

    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        wp_dequeue_style( 'admin-bar' );
    }

    wp_dequeue_style( 'imagify-admin-bar' );

    if ( ! is_single() || ! is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
        wp_dequeue_style( 'co-authors-plus-coauthors-style' );
        wp_dequeue_style( 'co-authors-plus-avatar-style' );
        wp_dequeue_style( 'co-authors-plus-name-style' );
        wp_dequeue_style( 'co-authors-plus-image-style' );
    }

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
