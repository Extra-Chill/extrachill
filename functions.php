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

        // Enhanced editor styles for block editor
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
 * Remove unnecessary WordPress image sizes for performance
 */
function extrachill_unregister_image_sizes() {
    remove_image_size('thumbnail');
    remove_image_size('2048x2048');
}
add_action('init', 'extrachill_unregister_image_sizes', 99);



define('EXTRACHILL_PARENT_DIR', get_template_directory());
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');


require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/post-meta.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/pagination.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/single-post/comments.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/share.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/templates/social-links.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/assets.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/breadcrumbs.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/template-overrides.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/header/walker.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/city-state-taxonomy.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/rewrite-rules.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/yoast-stuff.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/recent-posts-in-sidebar.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/admin/log-404-errors.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/bandcamp-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/instagram-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/editor/spotify-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/contextual-search-excerpt.php');

require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-child-terms-dropdown.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/archives/archive-custom-sorting.php');

    
add_filter('the_content', function($content)
{
    return str_replace('margin-left: 1em; margin-right: 1em;', '', $content);
});
add_filter('auto_update_theme', '__return_false');
/**
 * Disable WordPress emoji functionality for performance
 */
function disable_wp_emojicons()
{
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

function disable_emojicons_tinymce($plugins)
{
    if (is_array($plugins)) {
        return array_diff($plugins, array(
            'wpemoji'
        ));
    } else {
        return array();
    }
}

add_action('init', 'disable_wp_emojicons');

/**
 * Allow SVG file uploads
 */
function add_file_types_to_uploads($file_types)
{
    $new_filetypes['svg'] = 'image/svg+xml';
    return array_merge($file_types, $new_filetypes);
}
add_action('upload_mimes', 'add_file_types_to_uploads');

/**
 * Limit search results to specific post types
 */
function exclude_from_search($query) {
    if ($query->is_main_query() && $query->is_search && !is_admin()) {
        $query->set('post_type', array('post', 'page', 'product'));
    }
    return $query;
}
add_filter('pre_get_posts', 'exclude_from_search');




/**
 * Load all community integration files using native WordPress multisite functions
 */
function include_community_integration_files() {
    $directory = get_template_directory() . '/inc/community/';
    $php_files = glob($directory . '*.php');

    foreach ($php_files as $file) {
        include_once $file;
    }
}
add_action('after_setup_theme', 'include_community_integration_files');



function wpb_password_post_filter( $where = '' ) {
    if (!is_single() && !is_admin()) {
        $where .= " AND post_password = ''";
    }
    return $where;
}
add_filter( 'posts_where', 'wpb_password_post_filter' );

function wpshapere_remove_dashicons_wordpress() {
  if ( ! is_user_logged_in() ) {
    wp_dequeue_style('dashicons');
    wp_deregister_style( 'dashicons' );
  }
}
add_action( 'wp_enqueue_scripts', 'wpshapere_remove_dashicons_wordpress' );


add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
function wpse_cleantags_add_noindex( $robots ) {
    global $wp_query;


    if ( is_tag() && $wp_query->found_posts < 2 ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }


    return $robots;
}


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
 * Note: JavaScript filtering functionality has been removed
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



function extrachill_register_menus() {
    register_nav_menus(
        array(
            'footer-1' => __( 'Footer 1' ),
            'footer-2' => __( 'Footer 2' ),
            'footer-3' => __( 'Footer 3' ),
            'footer-4' => __( 'Footer 4' ),
            'footer-5' => __( 'Footer 5' ),
            'footer-extra' => __( 'Footer Extra' ), // New menu location
            'navigation-footer' => __( 'Navigation Footer' ), // Navigation menu footer links
        )
    );
}
add_action( 'init', 'extrachill_register_menus' );



function wp_innovator_randomize_posts( $query ) {
    if ( $query->is_main_query() && !is_admin() && is_archive() && isset($_GET['randomize']) ) {
        $query->set( 'orderby', 'rand' );
    }
}

add_action( 'pre_get_posts', 'wp_innovator_randomize_posts' );

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
    function extrachill_coauthors_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>Co-Authors Plus plugin is not active. Some author-related features may use fallbacks.</p></div>';
    }
}





function add_custom_favicon() {
    $favicon_url = get_site_url() . '/favicon.ico';
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
}
add_action('wp_head', 'add_custom_favicon');

/**
 * Default homepage sections - extensible via plugin hooks
 */
function extrachill_default_homepage_hero() {
    include get_template_directory() . '/inc/home/templates/hero.php';
}
add_action( 'extrachill_homepage_hero', 'extrachill_default_homepage_hero', 10 );

function extrachill_default_homepage_content_top() {
    include get_template_directory() . '/inc/home/templates/section-3x3-grid.php';
}
add_action( 'extrachill_homepage_content_top', 'extrachill_default_homepage_content_top', 10 );

function extrachill_default_homepage_content_middle() {
    include get_template_directory() . '/inc/home/templates/section-more-recent-posts.php';
}
add_action( 'extrachill_homepage_content_middle', 'extrachill_default_homepage_content_middle', 10 );

function extrachill_default_homepage_content_bottom() {
    include get_template_directory() . '/inc/home/templates/section-extrachill-link.php';
}
add_action( 'extrachill_homepage_content_bottom', 'extrachill_default_homepage_content_bottom', 10 );

function extrachill_default_final_left() {
    include get_template_directory() . '/inc/home/templates/section-about.php';
}
add_action( 'extrachill_home_final_left', 'extrachill_default_final_left', 10 );



function add_archive_body_class($classes) {
    if (is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'archive';
    }
    return $classes;
}
add_filter('body_class', 'add_archive_body_class');



/**
 * Prevent unnecessary admin styles from loading on frontend
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


add_action('init', function() {
    if (!taxonomy_exists('festival')) {
        register_taxonomy('festival', array('post', 'festival_wire'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Festivals', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Festival', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Festivals', 'extrachill'),
                'all_items' => __('All Festivals', 'extrachill'),
                'edit_item' => __('Edit Festival', 'extrachill'),
                'update_item' => __('Update Festival', 'extrachill'),
                'add_new_item' => __('Add New Festival', 'extrachill'),
                'new_item_name' => __('New Festival Name', 'extrachill'),
                'menu_name' => __('Festivals', 'extrachill'),
            ),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'festival'),
            'show_in_rest' => true,
        ));
    }

    if (!taxonomy_exists('artist')) {
        register_taxonomy('artist', array('post'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Artists', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Artist', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Artists', 'extrachill'),
                'all_items' => __('All Artists', 'extrachill'),
                'edit_item' => __('Edit Artist', 'extrachill'),
                'update_item' => __('Update Artist', 'extrachill'),
                'add_new_item' => __('Add New Artist', 'extrachill'),
                'new_item_name' => __('New Artist Name', 'extrachill'),
                'menu_name' => __('Artists', 'extrachill'),
            ),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'artist'),
            'show_in_rest' => true,
        ));
    }

    if (!taxonomy_exists('venue')) {
        register_taxonomy('venue', array('post'), array(
            'hierarchical' => false,
            'labels' => array(
                'name' => _x('Venues', 'taxonomy general name', 'extrachill'),
                'singular_name' => _x('Venue', 'taxonomy singular name', 'extrachill'),
                'search_items' => __('Search Venues', 'extrachill'),
                'all_items' => __('All Venues', 'extrachill'),
                'edit_item' => __('Edit Venue', 'extrachill'),
                'update_item' => __('Update Venue', 'extrachill'),
                'add_new_item' => __('Add New Venue', 'extrachill'),
                'new_item_name' => __('New Venue Name', 'extrachill'),
                'menu_name' => __('Venues', 'extrachill'),
            ),
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
            'rewrite' => array('slug' => 'venue'),
            'show_in_rest' => true,
        ));
    }
}, 0);



require_once get_stylesheet_directory() . '/inc/admin/tag-migration-admin.php';










function extrachill_add_full_width_body_class($classes) {
    if (is_archive() || is_search() || is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'full-width-content';
    }
    return $classes;
}
add_filter('body_class', 'extrachill_add_full_width_body_class');


