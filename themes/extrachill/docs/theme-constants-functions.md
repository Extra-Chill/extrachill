# Theme Constants and Functions

Core constants, configuration, and utility functions.

## Theme Constants

**Location**: `functions.php`

### EXTRACHILL_PARENT_DIR

Theme root directory path.

```php
define('EXTRACHILL_PARENT_DIR', get_template_directory());
```

**Value**: `/path/to/wp-content/themes/extrachill`

**Usage**:
```php
require_once( EXTRACHILL_PARENT_DIR . '/file.php' );
```

### EXTRACHILL_INCLUDES_DIR

Inc directory path for modular includes.

```php
define('EXTRACHILL_INCLUDES_DIR', EXTRACHILL_PARENT_DIR . '/inc');
```

**Value**: `/path/to/wp-content/themes/extrachill/inc`

**Usage**:
```php
require_once( EXTRACHILL_INCLUDES_DIR . '/core/actions.php' );
```

## Theme Setup

**Function**: `extrachill_setup()`
**Hook**: `after_setup_theme`

### Features Enabled

```php
function extrachill_setup() {
    // Translation support
    load_theme_textdomain('extrachill', get_template_directory() . '/languages');

    // Automatic feed links
    add_theme_support('automatic-feed-links');

    // Post thumbnails
    add_theme_support('post-thumbnails');

    // Title tag support
    add_theme_support('title-tag');

    // Page excerpts
    add_post_type_support('page', 'excerpt');

    // Block editor styles
    add_theme_support( 'editor-styles' );
    add_editor_style( 'assets/css/root.css' );
    add_editor_style( 'assets/css/editor-style.css' );
    add_editor_style( 'style.css' );
    add_editor_style( 'assets/css/single-post.css' );

    // HTML5 support
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
        'script'
    ));

    // Custom logo
    add_theme_support('custom-logo', array(
        'flex-width' => true,
        'flex-height' => true
    ));
}
```

### Block Editor Support

Theme supports:
- Responsive embeds
- Block styles
- Wide alignment
- Editor styles (4 CSS files)
- Share buttons in editor
- Notice system integration

```php
add_theme_support( 'responsive-embeds' );
add_theme_support( 'wp-block-styles' );
add_theme_support( 'align-wide' );
```

## Image Size Management

### Unregister Default Sizes

```php
function extrachill_unregister_image_sizes() {
    remove_image_size('thumbnail');
    remove_image_size('2048x2048');
}
add_action('init', 'extrachill_unregister_image_sizes', 99);
```

**Reason**: Reduces server storage and processing for unused image sizes

## File Upload Support

### SVG Upload Support

```php
function add_file_types_to_uploads($file_types) {
    $new_filetypes['svg'] = 'image/svg+xml';
    return array_merge($file_types, $new_filetypes);
}
add_action('upload_mimes', 'add_file_types_to_uploads');
```

**Enables**: SVG file uploads in media library

## Security Features

### Password Protection Filter

```php
function wpb_password_post_filter( $where = '' ) {
    if (!is_single() && !is_admin()) {
        $where .= " AND post_password = ''";
    }
    return $where;
}
add_filter( 'posts_where', 'wpb_password_post_filter' );
```

**Effect**: Hides password-protected posts from archives and listings

## Performance Optimizations

### Dashicons Removal (Non-logged-in Users)

```php
function wpshapere_remove_dashicons_wordpress() {
  if ( ! is_user_logged_in() ) {
    wp_dequeue_style('dashicons');
    wp_deregister_style( 'dashicons' );
  }
}
add_action( 'wp_enqueue_scripts', 'wpshapere_remove_dashicons_wordpress' );
```

**Benefit**: Reduces CSS load for non-admin users

### Notice System Performance

Notice dismissal uses efficient cookie-based tracking with minimal JavaScript overhead. Notices are only loaded when displayed, preventing unnecessary asset loading.

### Admin Styles Prevention

```php
function extrachill_prevent_admin_styles_on_frontend() {
    if ( is_admin() ) {
        return;
    }

    // Dequeue admin bar styles for non-logged-in users
    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        wp_dequeue_style( 'admin-bar' );
        wp_dequeue_style( 'dashicons' );
    }

    // Dequeue plugin admin styles
    wp_dequeue_style( 'imagify-admin-bar' );

    // Conditionally dequeue Co-Authors Plus
    if ( ! is_single() || ! is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
        wp_dequeue_style( 'co-authors-plus-coauthors-style' );
        // ... more Co-Authors styles
    }
}
add_action( 'wp_enqueue_scripts', 'extrachill_prevent_admin_styles_on_frontend', 100 );
```

## SEO Features

### Noindex Low-Content Tags

```php
function wpse_cleantags_add_noindex( $robots ) {
    global $wp_query;

    if ( is_tag() && $wp_query->found_posts < 2 ) {
        $robots['noindex'] = true;
        $robots['follow']  = true;
    }

    return $robots;
}
add_filter( 'wp_robots', 'wpse_cleantags_add_noindex' );
```

**Effect**: Tag pages with less than 2 posts get `noindex` meta tag

### Yoast Sitemap Integration

**Location**: `/inc/core/yoast-stuff.php`

Removes duplicate images from Yoast sitemap:

```php
function filter_yoast_sitemap_images($images, $post_id) {
    $featured_image_url = get_the_post_thumbnail_url($post_id);
    $post_content = get_post_field('post_content', $post_id);

    // Remove featured image from sitemap if already in content
    if (strpos($post_content, $featured_image_url) !== false) {
        foreach ($images as $key => $image) {
            if ($image['src'] === $featured_image_url) {
                unset($images[$key]);
                break;
            }
        }
    }

    return $images;
}
add_filter('wpseo_sitemap_urlimages', 'filter_yoast_sitemap_images', 10, 2);
```

## Content Cleanup

## Sticky Header Control

### Body Class Filter

```php
function extrachill_add_sticky_header_class( $classes ) {
    if ( apply_filters( 'extrachill_enable_sticky_header', true ) ) {
        $classes[] = 'sticky-header';
    }
    return $classes;
}
add_filter( 'body_class', 'extrachill_add_sticky_header_class' );
```

**Filter**: `extrachill_enable_sticky_header`
**Default**: Enabled (`true`)

**Disable Globally**:
```php
add_filter( 'extrachill_enable_sticky_header', '__return_false' );
```

**Disable Conditionally**:
```php
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return ! is_page( 'no-sticky' );
} );
```

## Favicon Support

```php
function add_custom_favicon() {
    if ( is_admin() ) {
        return;
    }

    $favicon_url = get_site_url() . '/favicon.ico';
    echo '<link rel="icon" href="' . esc_url($favicon_url) . '" type="image/x-icon" />';
}
add_action('wp_head', 'add_custom_favicon');
```

**Location**: Root `favicon.ico`

## Auto-Update Control

```php
add_filter('auto_update_theme', '__return_false');
```

**Effect**: Prevents automatic theme updates

## Co-Authors Plus Integration

### REST API Support

```php
if ( is_plugin_active('co-authors-plus/co-authors-plus.php') ) {
    add_action( 'rest_api_init', 'custom_register_coauthors' );

    function custom_register_coauthors() {
        register_rest_field( 'post', 'coauthors', array(
            'get_callback' => 'custom_get_coauthors',
            'schema'       => null,
        ));
    }

    function custom_get_coauthors( $object, $field_name, $request ) {
        $coauthors = get_coauthors($object['id']);

        $authors = array();
        foreach ($coauthors as $author) {
            $authors[] = array(
                'display_name'  => $author->display_name,
                'user_nicename' => $author->user_nicename
            );
        }

        return $authors;
    }
}
```

**Exposes**: Co-author data via REST API

### Admin Notice

```php
else {
    add_action('admin_notices', 'extrachill_coauthors_notice');
    function extrachill_coauthors_notice() {
        echo '<div class="notice notice-warning is-dismissible"><p>Co-Authors Plus plugin is not active. Some author-related features may use fallbacks.</p></div>';
    }
}
```

**Shows**: Warning when Co-Authors Plus not active

## Theme Textdomain

**Domain**: `extrachill`
**Directory**: `/languages/`

All translatable strings use `'extrachill'` textdomain:

```php
__( 'Search Locations', 'extrachill' )
_x( 'Artists', 'taxonomy general name', 'extrachill' )
esc_html_e( 'No results found', 'extrachill' )
```
