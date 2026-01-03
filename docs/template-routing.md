# Template Routing System

The ExtraChill theme uses WordPress's native `template_include` filter for template routing, providing plugin override capability for complete customization.

## Core Router Function

**Location**: `inc/core/template-router.php`

The `extrachill_route_templates()` function routes all page types to modular template files in the `/inc` directory.

## Route Mapping

| Page Type | Default Template | Override Mechanism | Notes |
|-----------|-----------------|-------------|-------|
| Homepage/Front Page | `/inc/home/templates/front-page.php` | `extrachill_homepage_content` action | No template filter; plugins inject content via action hook |
| Single Post | `/inc/single/single-post.php` | `extrachill_template_single_post` filter | |
| Page | `/inc/single/single-page.php` | `extrachill_template_page` filter | Only when no custom template assigned |
| Archives | `/inc/archives/archive.php` | `extrachill_template_archive` filter | |
| Search | extrachill-search plugin template | `extrachill_template_search` filter | Plugin provides main template |
| 404 Error | `/inc/core/templates/404.php` | `extrachill_template_404` filter | |
| Unknown Types | `/inc/core/templates/404.php` | `extrachill_template_fallback` filter | |

## Plugin Override Examples

### Homepage Content (via Action Hook)

The homepage uses an action hook pattern - plugins inject content rather than replacing the template:

```php
// Add content to homepage
add_action( 'extrachill_homepage_content', function() {
    include MY_PLUGIN_DIR . '/templates/homepage-section.php';
} );
```

### Other Templates (via Filters)

Non-homepage templates can be completely overridden via filters:

```php
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
