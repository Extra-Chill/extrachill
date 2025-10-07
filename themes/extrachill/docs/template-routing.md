# Template Routing System

The ExtraChill theme uses WordPress's native `template_include` filter for template routing, providing plugin override capability for complete customization.

## Core Router Function

**Location**: `inc/core/template-router.php`

The `extrachill_route_templates()` function routes all page types to modular template files in the `/inc` directory.

## Route Mapping

| Page Type | Default Template | Filter Hook |
|-----------|-----------------|-------------|
| Homepage/Front Page | `/inc/home/templates/front-page.php` | `extrachill_template_homepage` |
| Single Post | `/inc/single/single-post.php` | `extrachill_template_single_post` |
| Page | `/inc/single/single-page.php` | `extrachill_template_page` |
| Archives | `/inc/archives/archive.php` | `extrachill_template_archive` |
| Search | `/inc/archives/search/search.php` | `extrachill_template_search` |
| 404 Error | `/inc/core/templates/404.php` | `extrachill_template_404` |
| Unknown Types | `/inc/core/templates/404.php` | `extrachill_template_fallback` |

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

## Why This Matters

**Centralized Control**: All routing logic in one file
**Plugin Extensibility**: Filters allow complete template customization
**Performance**: Efficient routing while maintaining WordPress compatibility
**Maintainability**: Clear separation of routing from template logic
