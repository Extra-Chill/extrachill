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
- `extrachill_template_search` - Search results (uses archive template)
- `extrachill_template_404` - 404 error pages
- `extrachill_template_fallback` - Unknown page types fallback

### Template File Paths
Default template file locations when no plugin overrides are active:
- Homepage: `/inc/home/templates/front-page.php`
- Single posts: `/inc/single/single-post.php`
- Pages: `/inc/single/single-page.php`
- Archives: `/inc/archives/archive.php`
- Search: `/inc/archives/archive.php`
- 404: `/404.php`

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

## Benefits

- **Plugin Control**: Complete template override at routing level
- **Centralized Logic**: All routing decisions in single file
- **Performance**: Eliminates WordPress template hierarchy overhead
- **Extensibility**: Filter system allows complete template customization
- **Maintainability**: Clear separation between routing logic and template content