# Template Routing System

The ExtraChill theme uses WordPress's native `template_include` filter for template routing through `/inc/core/template-router.php`. This provides proper integration with WordPress core while maintaining plugin override capabilities.

## Router Architecture

### WordPress Native Integration (`inc/core/template-router.php`)
The template router uses WordPress's `template_include` filter to handle all page types:
- Homepage and front page routing
- Single posts and pages
- All archive types (categories, tags, authors, dates)
- Search results
- 404 error pages

### Emergency Fallback (`index.php`)
The `index.php` file serves as a minimal emergency fallback that is rarely reached. Template routing is handled by the dedicated router file.

### Plugin Override Filters
Each template route supports dedicated filter for complete override capability:
- `extrachill_template_homepage` - Front page and home page routing
- `extrachill_template_single_post` - Single post template override
- `extrachill_template_page` - Page template override
- `extrachill_template_archive` - Archive, category, tag, author, date pages
- `extrachill_template_search` - Search results (uses dedicated multisite search template)
- `extrachill_template_404` - 404 error pages
- `extrachill_template_fallback` - Unknown page types fallback

### Template File Paths
Default template file locations when no plugin overrides are active:
- Homepage: `/inc/home/templates/front-page.php`
- Single posts: `/inc/single/single-post.php`
- Pages: `/inc/single/single-page.php`
- Archives: `/inc/archives/archive.php`
- Search: `/inc/archives/search/search.php` (dedicated multisite search template)
- 404: `/inc/core/templates/404.php`

## Implementation Details

### Router Function (`extrachill_route_templates`)
The router function is hooked into WordPress's `template_include` filter:

```php
add_filter( 'template_include', 'extrachill_route_templates' );

function extrachill_route_templates( $template ) {
    // Route based on WordPress conditional tags
    if ( is_front_page() || is_home() ) {
        $template = apply_filters( 'extrachill_template_homepage',
            get_template_directory() . '/inc/home/templates/front-page.php'
        );
    } elseif ( is_single() ) {
        $template = apply_filters( 'extrachill_template_single_post',
            get_template_directory() . '/inc/single/single-post.php'
        );
    }
    // ... additional routing logic
    return $template;
}
```

## Usage Examples

### Plugin Template Override
```php
// Plugin can completely override single post template
add_filter( 'extrachill_template_single_post', 'my_plugin_single_template' );
function my_plugin_single_template( $template ) {
    return MY_PLUGIN_PATH . '/templates/custom-single.php';
}
```

### Conditional Template Override
```php
// Override archive template for specific taxonomy
add_filter( 'extrachill_template_archive', 'custom_artist_archive' );
function custom_artist_archive( $template ) {
    if ( is_tax( 'artist' ) ) {
        return MY_PLUGIN_PATH . '/templates/artist-archive.php';
    }
    return $template;
}
```

## Multisite Search Integration

The theme includes a dedicated multisite search system located in `/inc/archives/search/`:

- **search.php** - Main search template using `extrachill_multisite_search()` function
- **search-header.php** - Search results header with result counts and query display
- **search-site-badge.php** - Site identification badges for cross-site search results

The search template integrates with the extrachill-multisite plugin to provide cross-site search functionality across the WordPress multisite network. When the `extrachill_multisite_search()` function is available, it returns results from all sites in the network with proper pagination and result counting.

## Benefits

- **WordPress Native**: Proper integration with WordPress core via `template_include` filter
- **Plugin Control**: Complete template override at routing level via dedicated filters
- **Centralized Logic**: All routing decisions in dedicated router file (`inc/core/template-router.php`)
- **Performance**: Efficient routing while maintaining WordPress compatibility
- **Extensibility**: Filter system allows complete template customization
- **Maintainability**: Clear separation between routing logic and template content
- **Emergency Fallback**: Minimal `index.php` fallback ensures theme always works
- **Multisite Search**: Dedicated search template system with cross-site results integration