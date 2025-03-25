<?php
/**
 * ColorMag functions related to defining constants, adding files and WordPress core functionality.
 *
 * Defining some constants, loading all the required files and Adding some core functionality.
 *
 * @uses       add_theme_support() To add support for post thumbnails and automatic feed links.
 * @uses       register_nav_menu() To add support for navigation menu.
 * @uses       set_post_thumbnail_size() To set a custom post thumbnail size.
 *
 * @package    ThemeGrill
 * @subpackage ColorMag
 * @since      ColorMag 1.0
 */
add_theme_support( "responsive-embeds" );
add_theme_support( "wp-block-styles" );
add_theme_support( "align-wide" );
add_action('after_switch_theme', 'colormag_options_migrate');
add_action( 'after_setup_theme', function() {
    add_theme_support( 'woocommerce' );
} );


// chill generators

add_action( 'wp_ajax_rapper_name_generator', 'rapper_name_generator_ajax_handler' );
add_action( 'wp_ajax_nopriv_rapper_name_generator', 'rapper_name_generator_ajax_handler' );

add_action( 'wp_ajax_band_name_generator', 'band_name_generator_ajax_handler' );
add_action( 'wp_ajax_nopriv_band_name_generator', 'band_name_generator_ajax_handler' );

if (!function_exists('colormag_options_migrate')): /**
 * Migrate customizer options from free to Pro
 *
 * @since 1.0
 */ 
    function colormag_options_migrate()
    {
        
        // Bail out if ColorMag Pro theme is already activated.
        if (get_option('colormag_pro_active')) {
            return;
        }
        
        // Add option to check if pro theme is already activated or not.
        update_option('colormag_pro_active', 1);
        
        // Bail out if `theme_mods_colormag` does not exists
        if (false === ($mods = get_option("theme_mods_colormag"))) {
            return;
        }
        
        // Bail out if `colormag_transfer` already exists,
        // which refers that the database is already migrated.
        if (get_option('colormag_transfer')) {
            return;
        }
        
        // Migrate the free theme mods data to pro,
        // ie, from `theme_mods_colormag` to `theme_mods_colormag-pro`
        update_option('colormag_check', 'changed');
        $free = get_option("theme_mods_colormag");
        update_option("theme_mods_colormag-pro", $free);
        
        // Set transfer as complete
        update_option('colormag_transfer', 1);
    }
endif;


add_action('after_setup_theme', 'colormag_setup');

/**
 * All setup functionalities.
 *
 * @since 1.0
 */
if (!function_exists('colormag_setup')):
    function colormag_setup()
    {
        
        /*
         * Make theme available for translation.
         * Translations can be filed in the /languages/ directory.
         */
        load_theme_textdomain('colormag-pro', get_template_directory() . '/languages');
        
        // Add default posts and comments RSS feed links to head
        add_theme_support('automatic-feed-links');
        
        // This theme uses Featured Images (also known as post thumbnails) for per-post/per-page.
        add_theme_support('post-thumbnails');
        
        // Registering navigation menu.
        register_nav_menus(array(
            'primary' => __('Primary Menu', 'colormag-pro'),
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
        add_editor_style( 'style.css' ); 
        
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
        
        // Support Auto Load Next Post plugin
        add_theme_support('auto-load-next-post');
        
        // Support for selective refresh widgets in Customizer
        add_theme_support('customize-selective-refresh-widgets');
    }
endif;

/**
 * Define Directory Location Constants
 */
define('COLORMAG_PARENT_DIR', get_template_directory());
define('COLORMAG_CHILD_DIR', get_stylesheet_directory());

define('COLORMAG_INCLUDES_DIR', COLORMAG_PARENT_DIR . '/inc');
define('COLORMAG_CSS_DIR', COLORMAG_PARENT_DIR . '/css');
define('COLORMAG_JS_DIR', COLORMAG_PARENT_DIR . '/js');
define('COLORMAG_LANGUAGES_DIR', COLORMAG_PARENT_DIR . '/languages');

define('COLORMAG_ADMIN_DIR', COLORMAG_INCLUDES_DIR . '/admin');
define('COLORMAG_WIDGETS_DIR', COLORMAG_INCLUDES_DIR . '/widgets');

define('COLORMAG_ADMIN_IMAGES_DIR', COLORMAG_ADMIN_DIR . '/images');

/**
 * Define URL Location Constants
 */
define('COLORMAG_PARENT_URL', get_template_directory_uri());
define('COLORMAG_CHILD_URL', get_stylesheet_directory_uri());

define('COLORMAG_INCLUDES_URL', COLORMAG_PARENT_URL . '/inc');
define('COLORMAG_CSS_URL', COLORMAG_PARENT_URL . '/css');
define('COLORMAG_JS_URL', COLORMAG_PARENT_URL . '/js');
define('COLORMAG_LANGUAGES_URL', COLORMAG_PARENT_URL . '/languages');

define('COLORMAG_ADMIN_URL', COLORMAG_INCLUDES_URL . '/admin');
define('COLORMAG_WIDGETS_URL', COLORMAG_INCLUDES_URL . '/widgets');

define('COLORMAG_ADMIN_IMAGES_URL', COLORMAG_ADMIN_URL . '/images');

/** Load functions */
require_once(COLORMAG_INCLUDES_DIR . '/functions.php');
require_once(COLORMAG_INCLUDES_DIR . '/header-functions.php');
require_once(COLORMAG_INCLUDES_DIR . '/customizer.php');
require_once(COLORMAG_INCLUDES_DIR . '/ajax.php');

require_once(COLORMAG_ADMIN_DIR . '/meta-boxes.php');

/** Load Widgets and Widgetized Area */
require_once(COLORMAG_WIDGETS_DIR . '/widgets.php');

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



// include all PHP files in the 'extrachill-custom' directory

function extrachill_include_custom_files() {
    $custom_dir = get_template_directory() . '/extrachill-custom';

    // Check if directory exists
    if (is_dir($custom_dir)) {
        // Get all PHP files in the directory
        foreach (glob($custom_dir . '/*.php') as $file) {
            require_once $file;
        }
    }
}
add_action('after_setup_theme', 'extrachill_include_custom_files');

// include all PHP files in the 'community-integration' directory

function include_community_integration_files() {
    $directory = get_template_directory() . '/extrachill-custom/community-integration/';
    
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


function wp_innovator_get_tags_in_category($category_name) {
    $args = array(
        'category_name' => $category_name,
        'posts_per_page' => -1  // Retrieve all posts
    );
    $posts = get_posts($args);
    $tags = array();

    foreach ($posts as $post) {
        $post_tags = get_the_tags($post->ID);
        if ($post_tags) {
            foreach ($post_tags as $tag) {
                $tags[$tag->term_id] = $tag->name;
            }
        }
    }

    asort($tags); // Sort tags alphabetically
    return $tags;
}

function wp_innovator_dropdown_menu($category_name, $filter_heading) {
    $current_tag = get_query_var('tag'); 
    $tags = wp_innovator_get_tags_in_category($category_name);
    
    // Add H2 tag above the dropdown with dynamic heading text
    echo '<div id="tag-filters"><h2 class="filter-head">' . esc_html($filter_heading) . '</h2>';

    echo '<select id="tag-filter-dropdown" onchange="filterPostsByTag(this.value)">';

    // Set 'View All' option
    $selected = empty($current_tag) ? ' selected' : '';
    echo "<option value='all'{$selected}>View All</option>";

    foreach ($tags as $id => $name) {
        $slug = get_tag($id)->slug;
        $selected = ($slug == $current_tag) ? ' selected' : '';
        echo "<option value='{$slug}'{$selected}>{$name}</option>";
    }

    echo '</select></div>';
}


function wp_innovator_enqueue_scripts() { // Modified to enqueue community-comments.js
    if (is_archive()) {  // Enqueue the script for all archive pages
        wp_enqueue_script('wp-innovator-custom-script', get_template_directory_uri() . '/js/chill-custom.js', array(), '1.0.0', true);
    }
    if (is_singular('post')) { // Enqueue community-comments.js for single posts
        $js_path = get_template_directory() . '/js/community-comments.js';
        $js_url = get_template_directory_uri() . '/js/community-comments.js';
        $js_version = file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0'; // Dynamic versioning
        wp_enqueue_script('community-comments-js', $js_url, array(), $js_version, true);
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

function extrachill_register_widget_areas() {
    for ( $i = 1; $i <= 4; $i++ ) {
        register_sidebar( array(
            'name'          => sprintf( __( 'Footer Widget Area %d', 'colormag-pro' ), $i ),
            'id'            => 'footer-' . $i,
            'description'   => sprintf( __( 'Widgets added here will appear in footer column %d.', 'colormag-pro' ), $i ),
            'before_widget' => '<aside id="%1$s" class="widget %2$s">',
            'after_widget'  => '</aside>',
            'before_title'  => '<h3 class="widget-title">',
            'after_title'   => '</h3>',
        ) );
    }
}
add_action( 'widgets_init', 'extrachill_register_widget_areas' );

function wp_innovator_randomize_posts( $query ) {
    if ( $query->is_main_query() && !is_admin() && is_archive() && isset($_GET['randomize']) ) {
        $query->set( 'orderby', 'rand' );
    }
}

add_action( 'pre_get_posts', 'wp_innovator_randomize_posts' );

if ( function_exists('get_coauthors') ) {
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
        foreach ($coauthors as $author) {
            $authors[] = array(
                'display_name' => $author->display_name,
                'user_nicename' => $author->user_nicename
            );
        };
 
        return $authors;
    }
}

function include_event_scrapers_recursively($directory) {
    // Get all files and directories in the specified directory
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory), RecursiveIteratorIterator::SELF_FIRST);

    // Iterate through items
    foreach ($items as $item) {
        // Check if the item is a file
        if ($item->isFile()) {
            // Check if the file has a '.php' extension
            $fileExtension = pathinfo($item->getFilename(), PATHINFO_EXTENSION);
            if ($fileExtension === 'php') {
                // Include the PHP file
                require_once $item->getRealPath();
            }
        }
    }
}

// Define the path to the directory containing your scraping functions
$scrapersDirPath = get_template_directory() . '/extrachill-custom/events-scraping';

// Call the function to include all PHP files from the directory and its subdirectories
include_event_scrapers_recursively($scrapersDirPath);

add_filter( 'tribe_aggregator_import_event_image', function ( $import_event_image, $event, $activity ) {
    // Check if the event has an EventBriteID.
    if ( ! empty( $event['EventBriteID'] ) ) {
        // Log or perform actions if needed, indicating image processing is being skipped.
        do_action(
            'tribe_log',
            'debug',
            'Skipping image import',
            [
                'message' => 'Image import skipped for Eventbrite event.',
                'event'   => $event,
            ]
        );
        // Return false to stop the image import process for this event.
        return false;
    }

    // If not an Eventbrite event or if images should be imported, return the original value.
    return $import_event_image;
}, 10, 3 );


function disable_lazy_load_for_first_image($excluded_attributes) {
    $excluded_attributes[] = 'data-skip-lazy';
    return $excluded_attributes;
}
add_filter('rocket_lazyload_excluded_attributes', 'disable_lazy_load_for_first_image');

function add_skip_lazy_to_first_image($content) {
    if (is_singular() && strpos($content, '<img') !== false) { // Check if it's a singular page and contains at least one <img> tag
        $content = preg_replace_callback(
            '/<img\s[^>]+>/',
            function($matches) {
                $imgTag = $matches[0];
                // Check if 'data-skip-lazy' is already set
                if (strpos($imgTag, 'data-skip-lazy') === false) {
                    // Insert 'data-skip-lazy="true"' into the first <img> tag
                    $imgTag = str_replace('<img', '<img data-skip-lazy="true"', $imgTag);
                }
                return $imgTag;
            },
            $content,
            1 // Limit the replacement to the first occurrence
        );
    }
    return $content;
}
add_filter('the_content', 'add_skip_lazy_to_first_image', 1);




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
        if ( in_array( 'menu-item-has-children', $item->classes ) ) {
            $item_output .= ' <svg class="submenu-indicator"><use href="/wp-content/themes/colormag-pro/fonts/extrachill.svg?v=1.5#angle-down-solid"></use></svg>';
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

function inject_mediavine_settings() {
    echo '<div id="mediavine-settings" data-blocklist-all="1"></div>';
}
add_action( 'woocommerce_before_main_content', 'inject_mediavine_settings' );



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
            $css_path = get_stylesheet_directory() . '/extrachill-custom/css/custom-lightbox.css';

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
                get_stylesheet_directory_uri() . '/extrachill-custom/css/custom-lightbox.css',
                array(),
                $css_version
            );
        }
    }
}
add_action( 'wp_enqueue_scripts', 'enqueue_custom_lightbox_script' );
