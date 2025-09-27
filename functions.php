<?php
/**
 * ExtraChill Theme Setup and Configuration
 *
 * Main theme initialization with modular architecture:
 * - Custom taxonomies (artist, venue, festival) with REST API support
 * - Native WordPress multisite integration for community features
 * - Conditional WooCommerce support and performance optimizations
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
 * Configures theme features, menus, editor styles, and WordPress support
 *
 * @since 69.57
 */
if (!function_exists('extrachill_setup')):
    function extrachill_setup()
    {
        load_theme_textdomain('extrachill', get_template_directory() . '/languages');
        add_theme_support('automatic-feed-links');
        add_theme_support('post-thumbnails');

        register_nav_menus(array(
            'primary' => __('Primary Menu', 'extrachill'),
        ));

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
 * Eliminates generation of unused thumbnail and 2048x2048 image sizes
 *
 * @since 69.57
 */
function extrachill_unregister_image_sizes() {
    remove_image_size('thumbnail');
    remove_image_size('2048x2048');
}
add_action('init', 'extrachill_unregister_image_sizes', 99);



define('EXTRACHILL_PARENT_DIR', get_template_directory());
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');

// Core shared templates - reusable components across theme
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/pagination.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/no-results.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/single/comments.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/share.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/social-links.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/taxonomy-badges.php');

// Core functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/core/assets.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/breadcrumbs.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/template-overrides.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/header/walker.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/custom-taxonomies.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/rewrite-rules.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/yoast-stuff.php');

// Sidebar functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/recent-posts.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/sidebar/community-activity.php');

// Admin functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/admin/log-404-errors.php');

// Custom embeds
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/bandcamp-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/instagram-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/spotify-embeds.php');

// Archive functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-child-terms-dropdown.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-custom-sorting.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/post-card.php');

// Single post/page functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/single/single-post.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/single/single-page.php');

// Search functionality
require_once(EXTRACHILL_INCLUDES_DIR . '/core/multisite/multisite-search.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/searchform.php');

// Remove default WordPress block margins for custom styling control
add_filter('the_content', function($content) {
    return str_replace('margin-left: 1em; margin-right: 1em;', '', $content);
});
add_filter('auto_update_theme', '__return_false');

/**
 * Disable WordPress emoji scripts and styles for performance optimization
 * Removes all emoji-related scripts, styles, and filters to reduce HTTP requests
 *
 * @since 69.57
 */
function disable_wp_emojicons() {
    add_filter('tiny_mce_plugins', 'disable_emojicons_tinymce');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_action('wp_head', 'print_emoji_detection_script', 1);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('the_content', 'wp_staticize_emoji');
}

/**
 * Remove emoji plugin from TinyMCE editor
 *
 * @param array $plugins TinyMCE plugins array
 * @return array Modified plugins array without wpemoji
 * @since 69.57
 */
function disable_emojicons_tinymce($plugins) {
    if (is_array($plugins)) {
        return array_diff($plugins, array('wpemoji'));
    } else {
        return array();
    }
}

add_action('init', 'disable_wp_emojicons');

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
 * Restrict search results to specific post types
 * Limits search to posts, pages, and WooCommerce products only
 *
 * @param WP_Query $query WordPress query object
 * @return WP_Query Modified query object
 * @since 69.57
 */
function exclude_from_search($query) {
    if ($query->is_main_query() && $query->is_search && !is_admin()) {
        $query->set('post_type', array('post', 'page', 'product'));
    }
    return $query;
}
add_filter('pre_get_posts', 'exclude_from_search');




/**
 * Load all multisite integration files using native WordPress multisite functions
 * Uses glob() to dynamically include all PHP files in multisite directory
 * Enables cross-site search, activity feeds, and license validation
 *
 * @since 69.57
 */
function include_multisite_integration_files() {
    $directory = get_template_directory() . '/inc/core/multisite/';
    $php_files = glob($directory . '*.php');

    foreach ($php_files as $file) {
        include_once $file;
    }
}
add_action('after_setup_theme', 'include_multisite_integration_files');

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


add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
/**
 * Add noindex directive to tag pages with less than 2 posts
 * Prevents low-content tag pages from being indexed by search engines
 *
 * @param array $robots Robots directives array
 * @return array Modified robots directives
 * @since 69.57
 */
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
 * Get artists associated with posts in a specific category
 * @param string $category_name Category slug or uses current queried object
 * @return array Artist terms indexed by term_id
 */
function wp_innovator_get_artists_in_category($category_name) {
    $category_id = null;
    if (is_category()) {
        $category_id = get_queried_object_id();
    } else {
        $category = get_category_by_slug($category_name);
        if ($category) {
            $category_id = $category->term_id;
        }
    }

    if (!$category_id) {
        return array();
    }

    $posts_with_artists = get_posts(array(
        'post_type' => 'post',
        'post_status' => 'publish',
        'category' => $category_id,
        'numberposts' => -1,
        'fields' => 'ids',
        'tax_query' => array(
            array(
                'taxonomy' => 'artist',
                'operator' => 'EXISTS'
            )
        )
    ));

    if (empty($posts_with_artists)) {
        return array();
    }

    $artist_ids = array();
    foreach ($posts_with_artists as $post_id) {
        $post_artists = wp_get_post_terms($post_id, 'artist', array('fields' => 'ids'));
        if (!is_wp_error($post_artists)) {
            $artist_ids = array_merge($artist_ids, $post_artists);
        }
    }

    $artist_ids = array_unique($artist_ids);

    if (empty($artist_ids)) {
        return array();
    }

    $artists_terms = get_terms(array(
        'taxonomy' => 'artist',
        'include' => $artist_ids,
        'orderby' => 'name',
        'order' => 'ASC'
    ));

    $artists = array();
    if (!is_wp_error($artists_terms) && !empty($artists_terms)) {
        foreach ($artists_terms as $artist) {
            $artists[$artist->term_id] = $artist->name;
        }
    }

    return $artists;
}

/**
 * Generate artist dropdown menu for category filtering
 * @param string $category_name Category to filter artists for
 * @param string $filter_heading Display heading for filter section
 */
function wp_innovator_dropdown_menu($category_name, $filter_heading) {
    $current_artist = get_query_var('artist');
    $artists = wp_innovator_get_artists_in_category($category_name);

    echo '<div id="artist-filters"><h2 class="filter-head">' . esc_html($filter_heading) . '</h2>';
    echo '<select id="artist-filter-dropdown">';

    $selected = empty($current_artist) ? ' selected' : '';
    echo "<option value='all'{$selected}>View All</option>";

    foreach ($artists as $id => $name) {
        $slug = get_term($id, 'artist')->slug;
        $selected = ($slug == $current_artist) ? ' selected' : '';
        echo "<option value='{$slug}'{$selected}>{$name}</option>";
    }

    echo '</select></div>';
}



/**
 * Register footer navigation menu locations
 * Provides multiple footer menu areas for flexible footer layouts
 *
 * @since 69.57
 */
function extrachill_register_menus() {
    register_nav_menus(
        array(
            'footer-1' => __( 'Footer 1' ),
            'footer-2' => __( 'Footer 2' ),
            'footer-3' => __( 'Footer 3' ),
            'footer-4' => __( 'Footer 4' ),
            'footer-5' => __( 'Footer 5' ),
            'footer-extra' => __( 'Footer Extra' ),
            'navigation-footer' => __( 'Navigation Footer' ),
        )
    );
}
add_action( 'init', 'extrachill_register_menus' );



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
    $favicon_url = get_site_url() . '/favicon.ico';
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
}
add_action('wp_head', 'add_custom_favicon');

/**
 * Default homepage hero section handler
 * Includes hero template for homepage layout
 *
 * @since 69.57
 */
function extrachill_default_homepage_hero() {
    include get_template_directory() . '/inc/home/templates/hero.php';
}
add_action( 'extrachill_homepage_hero', 'extrachill_default_homepage_hero', 10 );

/**
 * Default homepage top content section handler
 * Includes 3x3 grid template for featured content
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_top() {
    include get_template_directory() . '/inc/home/templates/section-3x3-grid.php';
}
add_action( 'extrachill_homepage_content_top', 'extrachill_default_homepage_content_top', 10 );

/**
 * Default homepage middle content section handler
 * Includes recent posts template for additional content
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_middle() {
    include get_template_directory() . '/inc/home/templates/section-more-recent-posts.php';
}
add_action( 'extrachill_homepage_content_middle', 'extrachill_default_homepage_content_middle', 10 );

/**
 * Default homepage bottom content section handler
 * Includes ExtraChill link section template
 *
 * @since 69.57
 */
function extrachill_default_homepage_content_bottom() {
    include get_template_directory() . '/inc/home/templates/section-extrachill-link.php';
}
add_action( 'extrachill_homepage_content_bottom', 'extrachill_default_homepage_content_bottom', 10 );

/**
 * Default homepage final left section handler
 * Includes about section template for homepage footer area
 *
 * @since 69.57
 */
function extrachill_default_final_left() {
    include get_template_directory() . '/inc/home/templates/section-about.php';
}
add_action( 'extrachill_home_final_left', 'extrachill_default_final_left', 10 );



/**
 * Add archive body class to all-posts page template
 * Ensures consistent styling between archives and all-posts template
 *
 * @param array $classes Existing body classes
 * @return array Modified body classes array
 * @since 69.57
 */
function add_archive_body_class($classes) {
    if (is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'archive';
    }
    return $classes;
}
add_filter('body_class', 'add_archive_body_class');



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

/**
 * Hook taxonomy badges above post title
 * Displays artist, venue, festival taxonomy badges for current post
 *
 * @since 69.57
 */
function extrachill_hook_taxonomy_badges_above_title() {
    extrachill_display_taxonomy_badges( get_the_ID() );
}
add_action( 'extrachill_above_post_title', 'extrachill_hook_taxonomy_badges_above_title' );

/**
 * Hook comments section for posts
 * Includes enhanced comments system with community integration
 *
 * @since 69.57
 */
function extrachill_hook_comments_section() {
    require_once get_template_directory() . '/inc/single/comments.php';
}
add_action( 'extrachill_comments_section', 'extrachill_hook_comments_section' );










/**
 * Add full-width body class to archives, search, and all-posts template
 * Enables full-width layout for listing pages
 *
 * @param array $classes Existing body classes
 * @return array Modified body classes array
 * @since 69.57
 */
function extrachill_add_full_width_body_class($classes) {
    if (is_archive() || is_search() || is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'full-width-content';
    }
    return $classes;
}
add_filter('body_class', 'extrachill_add_full_width_body_class');


