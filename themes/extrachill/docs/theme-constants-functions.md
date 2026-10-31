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

## Performance Optimizations

### Admin Styles Prevention

```php
function extrachill_prevent_admin_styles_on_frontend() {
    if ( is_admin() ) {
        return;
    }

    if ( ! is_user_logged_in() || ! is_admin_bar_showing() ) {
        wp_dequeue_style( 'admin-bar' );
    }

    wp_dequeue_style( 'imagify-admin-bar' );

    wp_dequeue_style( 'wp-block-library-theme' );
}
add_action( 'wp_enqueue_scripts', 'extrachill_prevent_admin_styles_on_frontend', 100 );
```

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


## Theme Textdomain

**Domain**: `extrachill`
**Directory**: `/languages/`

All translatable strings use `'extrachill'` textdomain:

```php
__( 'Search Locations', 'extrachill' )
_x( 'Artists', 'taxonomy general name', 'extrachill' )
esc_html_e( 'No results found', 'extrachill' )
```
