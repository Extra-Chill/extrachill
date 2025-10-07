# ExtraChill Search

Network-activated WordPress plugin providing centralized multisite search functionality for the ExtraChill Platform. Searches across all seven sites in the WordPress multisite network and displays unified results.

## Plugin Information

- **Name**: ExtraChill Search
- **Version**: 1.0.0
- **Text Domain**: `extrachill-search`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: true (network activated across all sites)
- **Requires at least**: 5.0
- **Tested up to**: 6.4

## Architecture

### Plugin Loading Pattern
- **Procedural WordPress Pattern**: Uses direct `require_once` includes for all functionality
- **Singleton Class**: Main plugin class uses singleton pattern for initialization
- **No PSR-4 Autoloading**: All files loaded via explicit includes
- **Network Plugin Structure**: Network-activated plugin available to all multisite installations

### Core Functionality

#### Multisite Search System
**Universal Network Search** (`inc/core/search-functions.php`):
- **`extrachill_multisite_search()`**: Core search function querying all network sites or specified sites
- **Domain-Based Site Resolution**: Uses WordPress native `get_blog_id_from_url()` with automatic blog-id-cache
- **Flexible Post Type Support**: Automatically searches all public post types across sites
- **Meta Query Support**: Full support for `meta_query` parameter for advanced filtering (bbPress support)
- **Pagination and Sorting**: Supports limit, offset, orderby, order parameters with cross-site date sorting
- **Contextual Excerpts**: `ec_get_contextual_excerpt_multisite()` generates search term centered excerpts
- **Network Site Discovery**: `extrachill_get_network_sites()` with static caching for performance
- **Site URL Resolution**: `extrachill_resolve_site_urls()` converts domain strings to blog IDs
- **Fallback Excerpt Function**: Provides `ec_get_contextual_excerpt()` for themes without native implementation

#### Template System
**Search Results Template** (`templates/search.php`):
- Displays unified search results from all network sites
- Shows site badges indicating result origin
- Uses theme's `post-card.php` template for consistent styling
- Integrates with theme action hooks (`extrachill_before_body_content`, `extrachill_after_body_content`)
- Supports theme functions (`extrachill_breadcrumbs()`, `extrachill_no_results()`)

**Template Helper Functions** (`inc/templates/template-functions.php`):
- **`extrachill_get_search_results()`**: Fetches paginated search results with total count
- **`extrachill_search_pagination()`**: Generates pagination UI for search results

#### Taxonomy Functions (Placeholder)
**Future Functionality** (`inc/core/taxonomy-functions.php`):
- Currently placeholder for future multisite taxonomy archive sharing
- Taxonomy badge display handled by ExtraChill theme
- Taxonomy archive sharing deferred for future implementation

## WordPress Multisite Integration

### Network Sites Covered
The plugin searches across all seven sites in the ExtraChill Platform network:
1. **extrachill.com** - Main music journalism site
2. **community.extrachill.com** - Community forums (bbPress)
3. **shop.extrachill.com** - E-commerce (WooCommerce)
4. **app.extrachill.com** - Mobile API backend (planning stage)
5. **chat.extrachill.com** - AI chatbot interface
6. **artist.extrachill.com** - Artist platform and profiles
7. **events.extrachill.com** - Event calendar hub

### Native WordPress Functions Used
- **`switch_to_blog()`**: Cross-site database access
- **`restore_current_blog()`**: Restore original site context
- **`get_blog_id_from_url()`**: Domain-based blog ID resolution with automatic caching
- **`is_multisite()`**: Multisite installation detection
- **`get_sites()`**: Network site discovery
- **`get_blog_details()`**: Site metadata retrieval

### Performance Optimizations
- **Static Caching**: Network sites cached in memory via `extrachill_get_network_sites()`
- **WordPress Native Caching**: `get_blog_id_from_url()` uses blog-id-cache automatically
- **Efficient Blog Switching**: Minimal context switching with proper error handling
- **Cross-Site Date Sorting**: Results sorted by date across all sites for unified chronology

## File Structure

```
extrachill-search/
├── extrachill-search.php           # Main plugin file with singleton initialization
├── inc/
│   ├── core/
│   │   ├── search-functions.php    # Core multisite search functionality
│   │   └── taxonomy-functions.php  # Placeholder for future taxonomy archives
│   └── templates/
│       └── template-functions.php  # Template helper functions for search results
├── templates/
│   └── search.php                  # Search results template
├── build.sh                        # Symlink to universal build script
├── .buildignore                    # Production build exclusions
└── CLAUDE.md                       # This documentation file
```

## Theme Integration

### Required Theme Functions
The plugin expects the ExtraChill theme to provide:
- **`extrachill_breadcrumbs()`**: Breadcrumb navigation display
- **`extrachill_no_results()`**: No results found message
- **`extrachill_display_taxonomy_badges()`**: Taxonomy badge display for posts
- **`inc/archives/post-card.php`**: Post card template for result display

### Theme Action Hooks Used
- **`extrachill_before_body_content`**: Before main content area
- **`extrachill_after_body_content`**: After main content area
- **`extrachill_search_header`**: Search results header area
- **`extrachill_archive_below_description`**: Below archive description
- **`extrachill_archive_above_posts`**: Above posts loop

### Template Include Filter
The plugin uses WordPress's `template_include` filter at priority 99 to override the default search template:
```php
add_filter( 'template_include', 'extrachill_search_template_include', 99 );
```

## Search Functionality

### Basic Usage
```php
// Search all network sites
$results = extrachill_multisite_search( 'search term' );

// Search specific sites
$results = extrachill_multisite_search(
    'search term',
    array( 'community.extrachill.com', 'extrachill.com' )
);

// Advanced search with filters
$results = extrachill_multisite_search(
    'search term',
    array(),
    array(
        'limit'      => 20,
        'offset'     => 0,
        'post_status' => array( 'publish' ),
        'orderby'    => 'date',
        'order'      => 'DESC',
        'meta_query' => array(
            array(
                'key'     => '_bbp_forum_id',
                'value'   => '1494',
                'compare' => '!=',
            ),
        ),
    )
);
```

### Search Result Structure
```php
array(
    'ID'           => 123,                    // Post ID
    'post_title'   => 'Post Title',           // Post title
    'post_content' => 'Full content...',      // Full post content
    'post_excerpt' => 'Excerpt or trimmed...', // Excerpt
    'post_date'    => '2025-10-07 12:00:00',  // Publication date
    'post_type'    => 'post',                 // Post type
    'post_name'    => 'post-slug',            // Post slug
    'post_author'  => 1,                      // Author ID
    'site_id'      => 1,                      // Blog ID
    'site_name'    => 'Extra Chill',          // Site name
    'site_url'     => 'extrachill.com',       // Site URL (host only)
    'permalink'    => 'https://...',          // Full post URL
    'taxonomies'   => array(                  // Taxonomy terms
        'category' => array( 'term_name' => term_id ),
    ),
)
```

## Build System

### Universal Build Script
- **Symlinked to**: `../../.github/build.sh`
- **Auto-Detection**: Script automatically detects network plugin from `Network: true` header
- **Production Build**: Creates `/build/extrachill-search/` directory and `/build/extrachill-search.zip` file (non-versioned)
- **Composer Integration**: Production builds use `composer install --no-dev`, restores dev dependencies after
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Structure Validation**: Ensures network plugin integrity before packaging

### Build Process
```bash
# Create production build
./build.sh
```

**Output**:
- `/build/extrachill-search/` - Clean production-ready directory
- `/build/extrachill-search.zip` - Non-versioned deployment package

## Development Standards

### Code Organization
- **Procedural Pattern**: Direct `require_once` includes throughout plugin architecture
- **WordPress Standards**: Full compliance with network plugin development guidelines
- **Security Implementation**: Comprehensive error logging and input validation
- **Performance Focus**: Direct database queries with domain-based site resolution

### Common Development Commands

```bash
# Create production build
./build.sh

# Check PHP syntax
php -l extrachill-search.php

# Test multisite search function
wp eval 'print_r(extrachill_multisite_search("test"));'
```

## Dependencies

### PHP Requirements
- **PHP**: 7.4+
- **WordPress**: 5.0+ multisite network
- **Multisite**: Requires WordPress multisite installation (enforced on activation)

### Plugin Dependencies
- **ExtraChill Theme**: Required for template functions and post card display
- **ExtraChill Multisite**: Provides network-wide functionality (no direct dependency)

### WordPress Integration
- **Network Activation**: Must be network activated to function properly
- **Multisite Functions**: Leverages native `switch_to_blog()` and `restore_current_blog()`
- **Cross-Site Data**: Uses WordPress multisite database structure for cross-site access

## Future Development

### Planned Features
- **Multisite Taxonomy Archives**: Shared taxonomy archives across all network sites
- **Custom Rewrite Rules**: Pretty URLs for taxonomy archives (`/taxonomy/{taxonomy}/{term}/`)
- **Advanced Filtering**: Post type filtering, date range filtering, site-specific searches
- **Search Analytics**: Track popular search terms and result click-through rates
- **Search Suggestions**: Auto-complete and related search suggestions

### Deferred Functionality
- **Taxonomy Badge URL Override**: Currently handled by theme's native `get_term_link()`
- **Taxonomy Archive Templates**: Placeholder exists in `inc/core/taxonomy-functions.php`

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com
