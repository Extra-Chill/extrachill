# Template Routing System

The ExtraChill theme uses a centralized template routing system that replaces WordPress's traditional template hierarchy. All page types are routed through `index.php` with plugin override capabilities.

## Router Architecture

### Central Dispatch (`index.php`)
The universal template router handles all page types through conditional logic:
- Homepage and front page routing
- Single posts and pages
- All archive types (categories, tags, authors, dates)
- Search results
- 404 error pages

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

- **Plugin Control**: Complete template override at routing level
- **Centralized Logic**: All routing decisions in single file
- **Performance**: Eliminates WordPress template hierarchy overhead
- **Extensibility**: Filter system allows complete template customization
- **Maintainability**: Clear separation between routing logic and template content
- **Multisite Search**: Dedicated search template system with cross-site results integration