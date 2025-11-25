# Filter Hooks Reference

Theme filters for customizing functionality and output.

## Template Routing Filters

All template routing filters receive the default template path and must return a template path.

### extrachill_template_homepage
Override homepage template.

**Parameters**: `$template` (string) - Default: `/inc/home/templates/front-page.php`
**Returns**: Template file path

### extrachill_template_single_post
Override single post template.

**Parameters**: `$template` (string) - Default: `/inc/single/single-post.php`
**Returns**: Template file path

### extrachill_template_page
Override page template (only when no custom template assigned).

**Parameters**: `$template` (string) - Default: `/inc/single/single-page.php`
**Returns**: Template file path

**Important**: This filter does NOT fire when a custom page template is assigned via WordPress admin. Custom page templates (e.g., `page-templates/all-posts.php`) bypass this filter entirely, allowing WordPress's native template system to work naturally.

### extrachill_template_archive
Override archive template.

**Parameters**: `$template` (string) - Default: `/inc/archives/archive.php`
**Returns**: Template file path

### extrachill_template_search
Override search results template.

**Parameters**: `$template` (string) - Default: `/inc/archives/search/search.php`
**Returns**: Template file path

### extrachill_template_404
Override 404 error template.

**Parameters**: `$template` (string) - Default: `/inc/core/templates/404.php`
**Returns**: Template file path

### extrachill_template_fallback
Override fallback template for unknown page types.

**Parameters**: `$template` (string) - Default: `/inc/core/templates/404.php`
**Returns**: Template file path

## Post Meta Filter

### extrachill_post_meta
Customize post metadata display.

**Parameters**:
- `$default_meta` (string) - Default metadata HTML
- `$post_id` (int) - Post ID
- `$post_type` (string) - Post type

**Returns**: Modified HTML string

**Example**:
```php
add_filter( 'extrachill_post_meta', function( $meta, $post_id, $post_type ) {
    if ( $post_type === 'custom_type' ) {
        return '<div>Custom meta</div>';
    }
    return $meta;
}, 10, 3 );
```

## Sticky Header Filter

### extrachill_enable_sticky_header
Control sticky header behavior across the entire site.

**Parameters**: `$enabled` (bool) - Default: `true`
**Returns**: Boolean

**Location**: Applied in `functions.php` via `extrachill_add_sticky_header_class()` function

**Effects**:
- Adds `sticky-header` CSS class to `<body>` element when enabled
- Controls reading progress script enqueuing in `inc/core/assets.php`
- Affects navigation bar behavior and positioning

**Use Cases**:
- Disable on landing pages for full-screen layouts
- Disable on mobile devices to save screen space
- Conditional enabling based on user preferences
- Disable for specific post types or page templates

**Examples**:
```php
// Disable sticky header globally
add_filter( 'extrachill_enable_sticky_header', '__return_false' );

// Disable on specific pages
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return ! is_page( array( 'landing', 'splash' ) );
} );

// Disable on mobile devices
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return ! wp_is_mobile();
} );

// Disable for non-logged-in users
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return is_user_logged_in();
} );

// Enable only on single posts
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    return is_single();
} );
```

## Category Base Filter

### pre_option_category_base
Force blank category base for root-level URLs.

**Managed By**: `extrachill_force_category_base()`
**Returns**: Empty string

**Effect**: Category archives appear at `example.com/news/` instead of `example.com/category/news/`

### pre_update_option_category_base
Prevent category base updates.

**Managed By**: `extrachill_force_category_base()`
**Returns**: Empty string

## Embed Filters

### custom_bandcamp_embed
Customize Bandcamp embed output.

**Parameters**:
- `$embed_code` (string) - Generated embed HTML
- `$matches` (array) - URL regex matches
- `$attr` (array) - Embed attributes
- `$url` (string) - Original URL
- `$rawattr` (string) - Raw attributes

**Returns**: Modified embed HTML

## Multisite Plugin Filters

These filters require the extrachill-multisite plugin:

### extrachill_multisite_search
Provides cross-site search functionality.

**Required Plugin**: extrachill-multisite
**Returns**: Array of search results from multiple sites

## Using Filters

**Override Template**:
```php
add_filter( 'extrachill_template_archive', function( $template ) {
    if ( is_category( 'special' ) ) {
        return MY_PLUGIN_DIR . '/templates/special-archive.php';
    }
    return $template;
} );
```

**Modify Output**:
```php
add_filter( 'extrachill_post_meta', function( $meta, $post_id, $post_type ) {
    // Add custom fields
    $meta .= '<div>Custom: ' . get_post_meta( $post_id, 'custom', true ) . '</div>';
    return $meta;
}, 10, 3 );
```

**Conditional Behavior**:
```php
add_filter( 'extrachill_enable_sticky_header', function( $enabled ) {
    // Disable on mobile
    return ! wp_is_mobile();
} );
```
