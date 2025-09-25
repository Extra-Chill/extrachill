<?php
/**
 * ExtraChill functions related to defining constants, adding files and WordPress core functionality.
 *
 * Defining some constants, loading all the required files and Adding some core functionality.
 *
 * @uses       add_theme_support() To add support for post thumbnails and automatic feed links.
 * @uses       register_nav_menu() To add support for navigation menu.
 * @uses       set_post_thumbnail_size() To set a custom post thumbnail size.
 *
 * @package    ExtraChill
 * @since      ExtraChill 1.0
 */

include_once(ABSPATH . 'wp-admin/includes/plugin.php');

add_theme_support( "responsive-embeds" );
add_theme_support( "wp-block-styles" );
add_theme_support( "align-wide" );

/* WooCommerce functionality moved to extrachill-shop plugin */





add_action('after_setup_theme', 'extrachill_setup');

/**
 * All setup functionalities.
 *
 * @since 1.0
 */
if (!function_exists('extrachill_setup')):
    function extrachill_setup()
    {
        
        /*
         * Make theme available for translation.
         * Translations can be filed in the /languages/ directory.
         */
        load_theme_textdomain('extrachill', get_template_directory() . '/languages');
        
        // Add default posts and comments RSS feed links to head
        add_theme_support('automatic-feed-links');
        
        // This theme uses Featured Images (also known as post thumbnails) for per-post/per-page.
        add_theme_support('post-thumbnails');
        
        // Registering navigation menu.
        register_nav_menus(array(
            'primary' => __('Primary Menu', 'extrachill'),
        ));
        
        /*
         * Let WordPress manage the document title.
         * By adding theme support, we declare that this theme does not use a
         * hard-coded <title> tag in the document head, and expect WordPress to
         * provide it for us.
         */
        add_theme_support('title-tag');
        
        
        // Adding excerpt option box for pages as well
        add_post_type_support('page', 'excerpt');
        add_theme_support( 'editor-styles' );
        add_editor_style( 'css/root.css' );
        add_editor_style( 'css/editor-style.css' );
        add_editor_style( 'style.css' );
        add_editor_style( 'css/single-post.css' );
        
        /*
         * Switch default core markup for search form, comment form, and comments
         * to output valid HTML5.
         */
    add_theme_support('html5', array(
    'search-form',
    'comment-form',
    'comment-list',
    'gallery',
    'caption',
    'script'
));
        
        // Adds the support for the Custom Logo introduced in WordPress 4.5
        add_theme_support('custom-logo', array(
            'flex-width' => true,
            'flex-height' => true
        ));
        
        

    }
endif;

/**
 * Image Size Optimization - Remove unnecessary WordPress bloat sizes
 * Removes excessive image sizes from WordPress registry to prevent generation
 * and clean up media library interface. Keeps essential sizes for theme:
 * - medium (300x300) - Content images, grids, news cards
 * - medium_large (768x0) - Featured images in content.php and sidebar
 * - large (1024x1024) - Featured images, full-width content
 * - 1536x1536 - Concert photography galleries and high-res displays
 */
function extrachill_unregister_image_sizes() {
    // Remove WordPress core bloat sizes
    remove_image_size('thumbnail');     // 150x150 - not used in templates
    remove_image_size('2048x2048');     // Excessive for most use cases, removes storage bloat
    
    // Keep 1536x1536 - Essential for concert photography galleries and high-res displays
}
add_action('init', 'extrachill_unregister_image_sizes', 99);

// WordPress 5.3+ automatically scales down large images for performance
// This default behavior helps prevent serving massive unoptimized images
// Large uploads (typically 2560px+) will be automatically scaled to reasonable sizes

/* WooCommerce context detection moved to /inc/woocommerce.php */

/**
 * Define Directory Location Constants
 */
define('EXTRACHILL_PARENT_DIR', get_template_directory());
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');

/** Load functions */
require_once(EXTRACHILL_INCLUDES_DIR . '/functions.php');

/** Load core breadcrumb system */
require_once(EXTRACHILL_INCLUDES_DIR . '/core/breadcrumbs.php');

/** Load core functionality - Always required */
require_once(EXTRACHILL_INCLUDES_DIR . '/core/city-state-taxonomy.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/reading-progress.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/rewrite-rules.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/yoast-stuff.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/recent-posts-in-sidebar.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/core/dm-events-integration.php');

/** Load admin functionality - Admin only files (conditional loading could be added later) */
require_once(EXTRACHILL_INCLUDES_DIR . '/admin/log-404-errors.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/admin/contact-form.php');

/** Load conditional functionality - These files could be conditionally loaded in future optimization */
require_once(EXTRACHILL_INCLUDES_DIR . '/bandcamp-embeds.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/contextual-search-excerpt.php');
require_once(EXTRACHILL_INCLUDES_DIR . '/location-filter.php');


/**
 * Detect plugin. For use on Front End only.
 */
include_once(ABSPATH . 'wp-admin/includes/plugin.php');

    
add_filter('the_content', function($content)
{
    return str_replace('margin-left: 1em; margin-right: 1em;', '', $content);
});
add_filter('auto_update_theme', '__return_false');
// Disable Emoji Mess
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

function add_file_types_to_uploads($file_types)
{
    $new_filetypes        = array();
    $new_filetypes['svg'] = 'image/svg+xml';
    $file_types           = array_merge($file_types, $new_filetypes);
    return $file_types;
}
add_action('upload_mimes', 'add_file_types_to_uploads');

function exclude_from_search($query) {
    // Check if the query is the main query, a search query, and not in the admin area.
    if ($query->is_main_query() && $query->is_search && !is_admin()) {
        $query->set('post_type', array(
            'post',    // Include standard posts in search results.
            'page',    // Include pages in search results.
            'product', // Include WooCommerce products in search results.
        ));
    }
    return $query;
}
add_filter('pre_get_posts', 'exclude_from_search');




// include all PHP files in the 'community-integration' directory

function include_community_integration_files() {
    $directory = get_template_directory() . '/inc/community/';
    
    // Get all PHP files in the directory
    $php_files = glob($directory . '*.php');
    
    // Include each PHP file
    foreach ($php_files as $file) {
        include_once $file;
    }
}
add_action('after_setup_theme', 'include_community_integration_files');


/**
 * Remove wp-embed-aspect-21-9 class from Spotify embeds
 */
function remove_spotify_aspect_ratio_class( $content ) {
    // Search for any instance of the Spotify embed HTML markup
    $spotify_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify wp-embed-aspect-21-9 wp-has-aspect-ratio">';
    $pos = strpos( $content, $spotify_embed_html );
    while ( $pos !== false ) {
        // Replace the Spotify embed HTML markup with a modified version that does not include the wp-embed-aspect-21-9 class
        $new_embed_html = '<figure class="wp-block-embed is-type-rich is-provider-spotify wp-block-embed-spotify">';
        $content = substr_replace( $content, $new_embed_html, $pos, strlen( $spotify_embed_html ) );
        // Search for the next instance of the Spotify embed HTML markup
        $pos = strpos( $content, $spotify_embed_html, $pos + strlen( $new_embed_html ) );
    }
    return $content;
}
add_filter( 'the_content', 'remove_spotify_aspect_ratio_class' );

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

// noindex tags with 1 post or less

add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
function wpse_cleantags_add_noindex( $robots ) {
    global $wp_query;


    if ( is_tag() && $wp_query->found_posts < 2 ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }


    return $robots;
}


/*     external links in new tab     */

function add_target_blank_to_external_links($content) {
    $home_url = home_url(); // Gets your blog's URL
    $content = preg_replace_callback(
        '@<a\s[^>]*href=([\'"])(.+?)\1[^>]*>@i',
        function($matches) use ($home_url) {
            // Check if the link is an internal anchor
            if (strpos($matches[2], '#') === 0) {
                return $matches[0]; // Return the link as is, it's an internal anchor
            }
            // Check if the link does not contain the home URL
            if (strpos($matches[2], $home_url) === false) {
                return str_replace('<a', '<a target="_blank" rel="noopener noreferrer"', $matches[0]);
            } else {
                return $matches[0]; // It's an internal link, not external
            }
        },
        $content
    );

    return $content;
}
add_filter('the_content', 'add_target_blank_to_external_links');


function wp_innovator_get_artists_in_category($category_name) {
    // Get the current category ID
    $category_id = null;
    if (is_category()) {
        $category_id = get_queried_object_id();
    } else {
        // Fallback: get category by slug
        $category = get_category_by_slug($category_name);
        if ($category) {
            $category_id = $category->term_id;
        }
    }
    
    if (!$category_id) {
        return array();
    }
    
    // Query posts in the current category that have artist taxonomy terms
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
    
    // Get all unique artist terms from these posts
    $artist_ids = array();
    foreach ($posts_with_artists as $post_id) {
        $post_artists = wp_get_post_terms($post_id, 'artist', array('fields' => 'ids'));
        if (!is_wp_error($post_artists)) {
            $artist_ids = array_merge($artist_ids, $post_artists);
        }
    }
    
    // Remove duplicates
    $artist_ids = array_unique($artist_ids);
    
    if (empty($artist_ids)) {
        return array();
    }
    
    // Get the artist term objects for only the relevant artists
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

function wp_innovator_dropdown_menu($category_name, $filter_heading) {
    $current_artist = get_query_var('artist');
    $artists = wp_innovator_get_artists_in_category($category_name);
    
    // Add H2 tag above the dropdown with dynamic heading text
    echo '<div id="artist-filters"><h2 class="filter-head">' . esc_html($filter_heading) . '</h2>';

    echo '<select id="artist-filter-dropdown" onchange="filterPostsByArtist(this.value)">';

    // Set 'View All' option
    $selected = empty($current_artist) ? ' selected' : '';
    echo "<option value='all'{$selected}>View All</option>";

    foreach ($artists as $id => $name) {
        $slug = get_term($id, 'artist')->slug;
        $selected = ($slug == $current_artist) ? ' selected' : '';
        echo "<option value='{$slug}'{$selected}>{$name}</option>";
    }

    echo '</select></div>';
}


function wp_innovator_enqueue_scripts() {
    if (is_archive()) {  // Enqueue the script for all archive pages
        $js_path = get_template_directory() . '/js/chill-custom.js';
        $js_url = get_template_directory_uri() . '/js/chill-custom.js';
        $js_version = file_exists($js_path) ? filemtime($js_path) : '1.0.0';
        wp_enqueue_script('wp-innovator-custom-script', $js_url, array(), $js_version, true);
    }
    // Enqueue the new nav.css file
    $nav_css_path = get_theme_file_path('/css/nav.css');
    if ( file_exists( $nav_css_path ) ) {
        wp_enqueue_style(
            'extrachill-nav-styles',
            get_theme_file_uri('/css/nav.css'),
            array(), // No dependencies
            filemtime( $nav_css_path ), // Dynamic versioning
            'all' // Media type
        );
    }
}

add_action('wp_enqueue_scripts', 'wp_innovator_enqueue_scripts');

function extrachill_register_menus() {
    register_nav_menus(
        array(
            'footer-1' => __( 'Footer 1' ),
            'footer-2' => __( 'Footer 2' ),
            'footer-3' => __( 'Footer 3' ),
            'footer-4' => __( 'Footer 4' ),
            'footer-5' => __( 'Footer 5' ),
            'footer-extra' => __( 'Footer Extra' ), // New menu location
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
    // Admin notice if plugin not active
    add_action('admin_notices', 'extrachill_coauthors_notice');
    function extrachill_coauthors_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>Co-Authors Plus plugin is not active. Some author-related features may use fallbacks.</p></div>';
    }
}



class Custom_Walker_Nav_Menu extends Walker_Nav_Menu {
    function start_lvl( &$output, $depth = 0, $args = null ) {
        if ( isset( $args->item_spacing ) && 'discard' === $args->item_spacing ) {
            $t = '';
            $n = '';
        } else {
            $t = "\t";
            $n = "\n";
        }
        $indent = str_repeat( $t, $depth );
        $classes = array( 'sub-menu' );
        $class_names = join( ' ', apply_filters( 'nav_menu_submenu_css_class', $classes, $args, $depth ) );
        $class_names = $class_names ? ' class="' . esc_attr( $class_names ) . '"' : '';
        
        $output .= "{$n}{$indent}<ul$class_names>{$n}";
    }

    function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {
        if ( isset( $args->item_spacing ) && 'discard' === $args->item_spacing ) {
            $t = '';
            $n = '';
        } else {
            $t = "\t";
            $n = "\n";
        }
        $indent = ( $depth ) ? str_repeat( $t, $depth ) : '';

        $classes = empty( $item->classes ) ? array() : (array) $item->classes;
        $classes[] = 'menu-item-' . $item->ID;

        $args = (object) $args;
        $class_names = join( ' ', apply_filters( 'nav_menu_css_class', array_filter( $classes ), $item, $args, $depth ) );
        $class_names = $class_names ? ' class="' . esc_attr( $class_names ) . '"' : '';

        $id = apply_filters( 'nav_menu_item_id', 'menu-item-'. $item->ID, $item, $args, $depth );
        $id = $id ? ' id="' . esc_attr( $id ) . '"' : '';

        $output .= $indent . '<li' . $id . $class_names .'>';

        $atts = array();
        $atts['title']  = ! empty( $item->attr_title ) ? $item->attr_title : '';
        $atts['target'] = ! empty( $item->target )     ? $item->target     : '';
        $atts['rel']    = ! empty( $item->xfn )        ? $item->xfn        : '';
        $atts['href']   = ! empty( $item->url )        ? $item->url        : '';

        $atts = apply_filters( 'nav_menu_link_attributes', $atts, $item, $args, $depth );

        $attributes = '';
        foreach ( $atts as $attr => $value ) {
            if ( ! empty( $value ) ) {
                $value = ( 'href' === $attr ) ? esc_url( $value ) : esc_attr( $value );
                $attributes .= ' ' . $attr . '="' . $value . '"';
            }
        }

        $item_output = $args->before;
        $item_output .= '<a'. $attributes .'>';
        $item_output .= $args->link_before . apply_filters( 'the_title', $item->title, $item->ID ) . $args->link_after;

        // Add SVG if menu item has children
        if ( is_array( $item->classes ) && in_array( 'menu-item-has-children', $item->classes ) ) {
            $item_output .= ' <svg class="submenu-indicator"><use href="' . get_template_directory_uri() . '/fonts/extrachill.svg?v=1.5#angle-down-solid"></use></svg>';
        }

        $item_output .= '</a>';
        $item_output .= $args->after;

        $output .= apply_filters( 'walker_nav_menu_start_el', $item_output, $item, $depth, $args );
    }
}


// Add favicon to the head section
function add_custom_favicon() {
    // Define the favicon URL
    $favicon_url = get_site_url() . '/favicon.ico';
    
    // Output the favicon link
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
}

// Hook the function to wp_head
add_action('wp_head', 'add_custom_favicon');


function add_archive_body_class($classes) {
    if (is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'archive';
    }
    return $classes;
}
add_filter('body_class', 'add_archive_body_class');

function custom_instagram_embed_handler($matches, $attr, $url, $rawattr) {
    // Check if the URL is an Instagram profile
    if (preg_match('#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i', $url)) {
        $embed = sprintf(
            '<blockquote class="instagram-media" data-instgrm-permalink="%s" data-instgrm-version="14" style=" background:#FFF; border:0; border-radius:3px; box-shadow:0 0 1px 0 rgba(0,0,0,0.5),0 1px 10px 0 rgba(0,0,0,0.15); margin: 1px; max-width:540px; min-width:326px; padding:0; width:99.375%%; width:-webkit-calc(100%% - 2px); width:calc(100%% - 2px);"><a href="%s" target="_blank"></a></blockquote><script async src="//www.instagram.com/embed.js"></script>',
            esc_url($url),
            esc_url($url)
        );
    } else {
        // For posts or reels, use the existing embed format
        $embed = sprintf(
            '<iframe src="%s/embed" width="400" height="500" frameborder="0" scrolling="no" allowtransparency="true"></iframe>',
            esc_url($matches[0])
        );
    }

    return apply_filters('custom_instagram_embed', $embed, $embed, $matches, $attr, $url, $rawattr);
}

function register_custom_instagram_embed_handler() {
    wp_embed_register_handler(
        'instagram',
        '#https?://(www\.)?instagram\.com/(p|reel)/[a-zA-Z0-9_-]+#i',
        'custom_instagram_embed_handler'
    );

    // Register the handler for Instagram profiles as well
    wp_embed_register_handler(
        'instagram_profile',
        '#https?://(www\.)?instagram\.com/[a-zA-Z0-9_.-]+/?$#i',
        'custom_instagram_embed_handler'
    );
}
add_action('init', 'register_custom_instagram_embed_handler');

/* inject_mediavine_settings moved to /inc/woocommerce.php */

/* WooCommerce wrappers and CSS enqueuing moved to /inc/woocommerce.php */


function enqueue_custom_lightbox_script() {
    if ( is_singular() ) {
        $post_id = get_queried_object_id();
        $content = get_post_field( 'post_content', $post_id );

        if ( $content && (
            has_block( 'core/gallery', $content ) ||
            has_block( 'gallery', $content ) ||
            strpos( $content, 'wp:gallery' ) !== false
        ) ) {
            // Define paths for the JS and CSS files.
            $js_path  = get_stylesheet_directory() . '/js/custom-lightbox.js';
            $css_path = get_stylesheet_directory() . '/css/custom-lightbox.css';

            // Use filemtime() for dynamic versioning if the file exists.
            $js_version  = file_exists( $js_path )  ? filemtime( $js_path )  : null;
            $css_version = file_exists( $css_path ) ? filemtime( $css_path ) : null;

            // Enqueue the lightbox JS.
            wp_enqueue_script(
                'custom-lightbox',
                get_stylesheet_directory_uri() . '/js/custom-lightbox.js',
                array( 'jquery' ),
                $js_version,
                true
            );

            // Enqueue the lightbox CSS.
            wp_enqueue_style(
                'custom-lightbox-style',
                get_stylesheet_directory_uri() . '/css/custom-lightbox.css',
                array(),
                $css_version
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_custom_lightbox_script' );


/* WooCommerce asset dequeuing moved to /inc/woocommerce.php */

/**
 * Prevent admin styles from loading on frontend
 * This removes unnecessary admin bar and plugin admin styles from frontend pages
 */
function extrachill_prevent_admin_styles_on_frontend() {
    // Only run on frontend
    if ( is_admin() ) {
        return;
    }
    
    // Remove admin bar styles on frontend (unless user is logged in and admin bar is enabled)
    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        wp_dequeue_style( 'admin-bar' );
        wp_dequeue_style( 'dashicons' );
    }
    
    // Remove plugin admin styles that shouldn't be on frontend
    wp_dequeue_style( 'tribe-events-admin-menu' );
    wp_dequeue_style( 'imagify-admin-bar' );
    
    // Remove co-authors-plus styles unless we're on a post with co-authors
    if ( ! is_single() || ! is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
        wp_dequeue_style( 'co-authors-plus-coauthors-style' );
        wp_dequeue_style( 'co-authors-plus-avatar-style' );
        wp_dequeue_style( 'co-authors-plus-name-style' );
        wp_dequeue_style( 'co-authors-plus-image-style' );
    }
    
    // Remove trivia block styles unless we're on a page with trivia blocks
    if ( ! is_single() && ! is_page() ) {
        wp_dequeue_style( 'trivia-block-trivia-style' );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_prevent_admin_styles_on_frontend', 100 );

/* WooCommerce blocks CSS loading moved to /inc/woocommerce.php */

// --- Custom Taxonomies: Festival, Artist, Venue ---
add_action('init', function() {
    // Festival taxonomy (for both post and festival_wire)
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
    // Artist taxonomy (for post only)
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
    // Venue taxonomy (for post only)
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
// --- End Custom Taxonomies ---



require_once get_stylesheet_directory() . '/tag-migration-admin.php';

function extrachill_enqueue_home_styles() {
    if ( is_front_page() ) {
        $css_path = get_stylesheet_directory() . '/css/home.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-home',
                get_stylesheet_directory_uri() . '/css/home.css',
                array(),
                filemtime( $css_path )
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_home_styles' );

function extrachill_enqueue_root_styles() {
    $css_path = get_stylesheet_directory() . '/css/root.css';
    if ( file_exists( $css_path ) ) {
        wp_enqueue_style(
            'extrachill-root',
            get_stylesheet_directory_uri() . '/css/root.css',
            array(),
            filemtime( $css_path )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_root_styles', 5 ); // Priority 5: before other styles

// Ensure root.css is loaded before style.css by making it a dependency
function extrachill_enqueue_main_styles() {
    // WordPress automatically loads style.css, so we just need to add root.css as a dependency
    // and enqueue additional stylesheets
    
    // Enqueue badge colors style
    $badge_colors_path = get_stylesheet_directory() . '/css/badge-colors.css';
    if ( file_exists( $badge_colors_path ) ) {
        wp_enqueue_style(
            'badge-colors',
            get_stylesheet_directory_uri() . '/css/badge-colors.css',
            array('extrachill-root'), // Make it dependent on root.css
            filemtime( $badge_colors_path )
        );
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_enqueue_main_styles', 10 );

// Ensure root.css is loaded before the default WordPress style.css
function extrachill_modify_default_style() {
    // WordPress automatically loads style.css with handle 'extrachill-style'
    // We need to ensure root.css is loaded first
    wp_dequeue_style('extrachill-style');
    wp_deregister_style('extrachill-style');
    
    // Re-enqueue with root.css as dependency
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
        $css_path = get_stylesheet_directory() . '/css/single-post.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-single-post',
                get_stylesheet_directory_uri() . '/css/single-post.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime( $css_path )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_single_post_styles', 20);

function extrachill_enqueue_archive_styles() {
    if ( is_archive() || is_search() || is_page_template('page-templates/all-posts.php') ) {
        $css_path = get_stylesheet_directory() . '/css/archive.css';
        if ( file_exists( $css_path ) ) {
            wp_enqueue_style(
                'extrachill-archive',
                get_stylesheet_directory_uri() . '/css/archive.css',
                array('extrachill-root', 'extrachill-style'),
                filemtime( $css_path )
            );
        }
    }
}
add_action('wp_enqueue_scripts', 'extrachill_enqueue_archive_styles', 20);

function extrachill_add_full_width_body_class($classes) {
    // Add full-width class to archive, search, and all-posts pages
    if (is_archive() || is_search() || is_page_template('page-templates/all-posts.php')) {
        $classes[] = 'full-width-content';
    }
    return $classes;
}
add_filter('body_class', 'extrachill_add_full_width_body_class');

/**
 * Enqueue admin styles to fix editor styling issues
 */
function extrachill_enqueue_admin_styles($hook) {
    // Only load on post edit screens
    if ($hook == 'post.php' || $hook == 'post-new.php') {
        // First enqueue root.css
        $root_css_path = get_stylesheet_directory() . '/css/root.css';
        if (file_exists($root_css_path)) {
            wp_enqueue_style(
                'extrachill-admin-root',
                get_stylesheet_directory_uri() . '/css/root.css',
                array(),
                filemtime($root_css_path)
            );
        }
        
        // Then enqueue editor-style.css with root.css as dependency
        $admin_css_path = get_stylesheet_directory() . '/css/editor-style.css';
        if (file_exists($admin_css_path)) {
            wp_enqueue_style(
                'extrachill-admin-editor',
                get_stylesheet_directory_uri() . '/css/editor-style.css',
                array('extrachill-admin-root'),
                filemtime($admin_css_path)
            );
        }
    }
}
add_action('admin_enqueue_scripts', 'extrachill_enqueue_admin_styles');

/* WooCommerce safe wrapper functions moved to /inc/woocommerce.php */

/* WooCommerce prevention function moved to /inc/woocommerce.php */


// CSS optimization code removed - was causing memory bloat by using file_get_contents() on every page load