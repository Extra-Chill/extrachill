<?php
/**
 * ExtraChill Theme Setup and Configuration
 *
 * Main theme initialization with modular architecture:
 * - Custom taxonomies (artist, venue, festival) with REST API support
 * - Native WordPress multisite integration for community features
 * - Performance optimizations and modular CSS/JS loading
 * - Plugin extensibility via homepage action hooks
 * - Editor style enhancements and block support
 *
 * @package ExtraChill
 * @since 69.57
 */

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

add_theme_support( 'responsive-embeds' );
add_theme_support( 'wp-block-styles' );
add_theme_support( 'align-wide' );

add_action('after_setup_theme', 'extrachill_setup');


/**
 * ExtraChill theme setup function
 */
if (!function_exists('extrachill_setup')):
    function extrachill_setup()
    {
        load_theme_textdomain('extrachill', get_template_directory() . '/languages');
        add_theme_support('automatic-feed-links');
        add_theme_support('post-thumbnails');


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

/**
 * Remove unused WordPress image sizes for performance
 */
function extrachill_unregister_image_sizes() {
    remove_image_size('thumbnail');
    remove_image_size('2048x2048');
}
add_action('init', 'extrachill_unregister_image_sizes', 99);



define('EXTRACHILL_PARENT_DIR', get_template_directory());
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');

// Multisite integration - now handled by extrachill-multisite plugin

// Core shared templates
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/pagination.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/no-results.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/share.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/social-links.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/taxonomy-badges.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/actions.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/assets.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/breadcrumbs.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/header/navigation-menu.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/custom-taxonomies.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/rewrite-rules.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/yoast-stuff.php');

// Sidebar functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/recent-posts.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/community-activity.php');

// Admin functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/admin/log-404-errors.php');

/**
 * Remove WordPress menu management from admin interface
 * Since theme uses hardcoded hook-based menu system, menu management is unnecessary
 *
 * @since 69.57
 */
function extrachill_remove_menu_admin_pages() {
    // Remove "Appearance > Menus" page
    remove_submenu_page('themes.php', 'nav-menus.php');
}
add_action('admin_menu', 'extrachill_remove_menu_admin_pages', 999);

/**
 * Remove menu management from WordPress Customizer
 * Prevents confusion since menus are now hardcoded
 *
 * @since 69.57
 */
function extrachill_remove_customizer_menus($wp_customize) {
    $wp_customize->remove_panel('nav_menus');
}
add_action('customize_register', 'extrachill_remove_customizer_menus', 20);

// Custom embeds
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/bandcamp-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/instagram-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/spotify-embeds.php');

// Archive functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-child-terms-dropdown.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-custom-sorting.php');

// Single post/page functionality handled by template hierarchy - no direct includes needed

// Search functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/searchform.php');

// Remove default WordPress block margins for custom styling control
add_filter('the_content', function($content) {
    return str_replace('margin-left: 1em; margin-right: 1em;', '', $content);
});
add_filter('auto_update_theme', '__return_false');


/**
 * Allow SVG file uploads by adding SVG MIME type
 *
 * @param array $file_types Existing MIME types
 * @return array Modified MIME types including SVG support
 * @since 69.57
 */
function add_file_types_to_uploads($file_types)
{
    $new_filetypes['svg'] = 'image/svg+xml';
    return array_merge($file_types, $new_filetypes);
}
add_action('upload_mimes', 'add_file_types_to_uploads');






/**
 * Multisite integration for community features
 * ExtraChill Newsletter, Contact, and Shop functionality handled by dedicated plugins
 * Includes cross-site search, activity feeds, and ad-free license validation
 */



/**
 * Exclude password-protected posts from archives and listings
 * Only applies to non-single views and frontend
 *
 * @param string $where SQL WHERE clause
 * @return string Modified WHERE clause excluding password-protected posts
 * @since 69.57
 */
function wpb_password_post_filter( $where = '' ) {
    if (!is_single() && !is_admin()) {
        $where .= " AND post_password = ''";
    }
    return $where;
}
add_filter( 'posts_where', 'wpb_password_post_filter' );

/**
 * Remove Dashicons for non-logged-in users for performance optimization
 * Reduces CSS payload for frontend visitors
 *
 * @since 69.57
 */
function wpshapere_remove_dashicons_wordpress() {
  if ( ! is_user_logged_in() ) {
    wp_dequeue_style('dashicons');
    wp_deregister_style( 'dashicons' );
  }
}
add_action( 'wp_enqueue_scripts', 'wpshapere_remove_dashicons_wordpress' );


/**
 * Add noindex directive to tag pages with less than 2 posts
 * Prevents low-content tag pages from being indexed by search engines
 *
 * @param array $robots Robots directives array
 * @return array Modified robots directives
 * @since 69.57
 */
add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
function wpse_cleantags_add_noindex( $robots ) {
    global $wp_query;

    if ( is_tag() && $wp_query->found_posts < 2 ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }

    return $robots;
}


/**
 * Automatically add target="_blank" and security attributes to external links
 * Adds rel="noopener noreferrer" for security and opens external links in new tab
 * Excludes anchor links (#) and internal links
 *
 * @param string $content Post content HTML
 * @return string Modified content with external link attributes
 * @since 69.57
 */
function add_target_blank_to_external_links($content) {
    $home_url = home_url();
    $content = preg_replace_callback(
        '@<a\s[^>]*href=([\'"])(.+?)\1[^>]*>@i',
        function($matches) use ($home_url) {
            if (strpos($matches[2], '#') === 0) {
                return $matches[0];
            }
            if (strpos($matches[2], $home_url) === false) {
                return str_replace('<a', '<a target="_blank" rel="noopener noreferrer"', $matches[0]);
            } else {
                return $matches[0];
            }
        },
        $content
    );

    return $content;
}
add_filter('the_content', 'add_target_blank_to_external_links');





/**
 * Menu registration removed - now using hardcoded hook-based system
 * All menus (footer and navigation) use performance-optimized hardcoded approach
 *
 * @since 69.57
 */




/**
 * Randomize post order in archive pages when ?randomize parameter is present
 * Allows users to discover content in random order via URL parameter
 *
 * @param WP_Query $query WordPress query object
 * @since 69.57
 */
function wp_innovator_randomize_posts( $query ) {
    if ( $query->is_main_query() && !is_admin() && is_archive() && isset($_GET['randomize']) ) {
        $query->set( 'orderby', 'rand' );
    }
}
add_action( 'pre_get_posts', 'wp_innovator_randomize_posts' );


if ( is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
    add_action( 'rest_api_init', 'custom_register_coauthors' );
    /**
     * Register coauthors field for REST API
     * Enables access to Co-Authors Plus data via WordPress REST API
     *
     * @since 69.57
     */
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

    /**
     * Get coauthors data for REST API response
     * Returns array of coauthor information with fallback to default author
     *
     * @param array $object Post object data
     * @param string $field_name Field name being requested
     * @param WP_REST_Request $request REST request object
     * @return array Array of author objects with display_name and user_nicename
     * @since 69.57
     */
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
            // Fallback to default author
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
} else {
    add_action('admin_notices', 'extrachill_coauthors_notice');
    /**
     * Display admin notice when Co-Authors Plus plugin is not active
     * Informs administrators about missing plugin dependency
     *
     * @since 69.57
     */
    function extrachill_coauthors_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>Co-Authors Plus plugin is not active. Some author-related features may use fallbacks.</p></div>';
    }
}





/**
 * Add custom favicon to site head
 * Uses favicon.ico from site root directory
 *
 * @since 69.57
 */
function add_custom_favicon() {
    if ( is_admin() ) {
        return;
    }

    $favicon_url = get_site_url() . '/favicon.ico';
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
}
add_action('wp_head', 'add_custom_favicon');







/**
 * Prevent unnecessary admin and plugin styles from loading on frontend
 * Performance optimization that removes unused CSS from frontend pages
 * Conditionally removes admin-bar, dashicons, and plugin-specific styles
 *
 * @since 69.57
 */
function extrachill_prevent_admin_styles_on_frontend() {
    if ( is_admin() ) {
        return;
    }

    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        wp_dequeue_style( 'admin-bar' );
        wp_dequeue_style( 'dashicons' );
    }

    wp_dequeue_style( 'imagify-admin-bar' );

    if ( ! is_single() || ! is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
        wp_dequeue_style( 'co-authors-plus-coauthors-style' );
        wp_dequeue_style( 'co-authors-plus-avatar-style' );
        wp_dequeue_style( 'co-authors-plus-name-style' );
        wp_dequeue_style( 'co-authors-plus-image-style' );
    }

    if ( ! is_single() && ! is_page() ) {
        wp_dequeue_style( 'trivia-block-trivia-style' );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_prevent_admin_styles_on_frontend', 100 );

require_once get_stylesheet_directory() . '/inc/admin/tag-migration-admin.php';


