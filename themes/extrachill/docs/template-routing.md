# Template Routing System

The ExtraChill theme uses WordPress's native `template_include` filter for template routing, providing plugin override capability for complete customization.

## Core Router Function

**Location**: `inc/core/template-router.php`

The `extrachill_route_templates()` function routes all page types to modular template files in the `/inc` directory.

## Route Mapping

| Page Type | Default Template | Filter Hook | Notes |
|-----------|-----------------|-------------|-------|
| Homepage/Front Page | `/inc/home/templates/front-page.php` | `extrachill_template_homepage` | |
| Single Post | `/inc/single/single-post.php` | `extrachill_template_single_post` | |
| Page | `/inc/single/single-page.php` | `extrachill_template_page` | Only when no custom template assigned |
| Archives | `/inc/archives/archive.php` | `extrachill_template_archive` | |
| Search | extrachill-search plugin template | `extrachill_template_search` | Plugin provides main template |
| 404 Error | `/inc/core/templates/404.php` | `extrachill_template_404` | |
| Unknown Types | `/inc/core/templates/404.php` | `extrachill_template_fallback` | |

## Plugin Override Example

Plugins can completely override template files at the routing level:

```php
// Override homepage template
add_filter( 'extrachill_template_homepage', function( $template ) {
    return MY_PLUGIN_DIR . '/templates/custom-homepage.php';
} );

// Override single post template conditionally
add_filter( 'extrachill_template_single_post', function( $template ) {
    if ( has_tag( 'special' ) ) {
        return MY_PLUGIN_DIR . '/templates/special-post.php';
    }
    return $template;
} );
```

## Integration with WordPress

- Uses `template_include` filter for proper WordPress integration
- Respects WordPress template hierarchy
- Compatible with child themes
- `index.php` serves as emergency fallback only

## Archive Detection

Archive template triggers for:
- Category archives (`is_category()`)
- Tag archives (`is_tag()`)
- Author archives (`is_author()`)
- Date archives (`is_date()`)
- Custom taxonomy archives (`is_archive()`)

## Custom Page Template Support

The router automatically respects custom page templates assigned via WordPress admin:

### How It Works

1. **Detection**: Router checks for custom templates using `get_page_template_slug()`
2. **Validation**: Verifies the custom template file exists via `locate_template()`
3. **Bypass**: If custom template found, router returns WordPress's natural selection
4. **Filter Application**: `extrachill_template_page` filter only applies when no custom template assigned

### Example Custom Page Template

Create a custom page template in `/page-templates/`:

```php
<?php
/**
 * Template Name: All Posts
 * Description: Displays all posts in a custom layout
 */

get_header();
?>

<div class="custom-page-template">
    <?php
    // Your custom template logic here
    $query = new WP_Query(array('post_type' => 'post', 'posts_per_page' => -1));
    // Display posts...
    ?>
</div>

<?php get_footer(); ?>
```

### Using Custom Templates

1. **Create template file** in `/page-templates/` directory with proper header comment
2. **Edit page** in WordPress admin
3. **Select template** from Page Attributes > Template dropdown
4. **Router respects selection** automatically, bypassing plugin filters

### Plugin Integration with Custom Templates

Plugins using `extrachill_template_page` filter should be aware:

```php
add_filter('extrachill_template_page', function($template) {
    // This filter ONLY fires when no custom template is assigned
    // Custom templates bypass this filter entirely
    return MY_PLUGIN_DIR . '/custom-page.php';
});
```

## Why This Matters

**Centralized Control**: All routing logic in one file
**Plugin Extensibility**: Filters allow complete template customization
**WordPress Compatibility**: Respects native WordPress custom page template system
**Performance**: Efficient routing while maintaining WordPress compatibility
**Maintainability**: Clear separation of routing from template logic
**Flexibility**: Supports both plugin-based and WordPress-native template customization
