# ExtraChill Search

Network-activated WordPress plugin providing universal multisite search functionality for the Extra Chill Platform. Searches across all sites in a WordPress multisite network and displays unified results.

## Features

- **Universal Multisite Search** - Search all network sites or specific sites with single function call
- **Domain-Based Site Resolution** - Uses WordPress native `get_blog_id_from_url()` with automatic caching
- **Flexible Post Type Support** - Searches all public post types (posts, pages, custom post types, bbPress topics/replies)
- **Advanced Filtering** - Full `WP_Query` parameter support including meta queries
- **Contextual Excerpts** - Generates search-term-centered excerpts with highlighted matches
- **Cross-Site Pagination** - Results sorted chronologically across all sites
- **Site Badges** - Visual indicators showing which site each result originates from

## Requirements

- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Multisite**: WordPress multisite installation required
- **Theme**: ExtraChill theme (for template integration)

## Installation

### Via GitHub
```bash
# Clone repository
git clone https://github.com/Extra-Chill/extrachill-search.git

# Navigate to plugin directory
cd extrachill-search

# Build production package
./build.sh

# Upload /build/extrachill-search.zip to WordPress Network Admin
```

### Via WordPress Admin
1. Download latest release from GitHub
2. Upload ZIP file to Network Admin → Plugins → Add New
3. Network activate the plugin

## Usage

### Search All Sites
```php
// Basic search
$results = extrachill_multisite_search( 'search term' );

// Search returns array of post objects with site metadata
foreach ( $results as $result ) {
    echo $result['post_title'];      // Post title
    echo $result['site_name'];       // Site name
    echo $result['permalink'];       // Full URL to post
}
```

### Search Specific Sites
```php
$results = extrachill_multisite_search(
    'search term',
    array( 'community.extrachill.com', 'extrachill.com' )
);
```

### Advanced Search with Filters
```php
$results = extrachill_multisite_search(
    'search term',
    array(),  // Empty array = all sites
    array(
        'limit'       => 20,
        'offset'      => 0,
        'post_status' => array( 'publish' ),
        'orderby'     => 'date',
        'order'       => 'DESC',
        'meta_query'  => array(
            array(
                'key'     => '_bbp_forum_id',
                'value'   => '1494',
                'compare' => '!=',
            ),
        ),
    )
);
```

### Template Functions
```php
// Get paginated search results with total count
list( $results, $total ) = extrachill_get_search_results( $_GET['s'], $page, $per_page );

// Generate pagination UI
extrachill_search_pagination( $total, $per_page, $page, $_GET['s'] );

// Get contextual excerpt with search term highlighted
$excerpt = ec_get_contextual_excerpt_multisite( $post_content, $search_term, 300 );
```

## Architecture

### Network Sites Covered
The plugin searches across all sites in your WordPress multisite network. In the Extra Chill Platform, this includes:

1. extrachill.com - Main site
2. community.extrachill.com - Forums
3. shop.extrachill.com - E-commerce
4. chat.extrachill.com - AI chatbot
5. artist.extrachill.com - Artist profiles
6. events.extrachill.com - Events calendar
7. app.extrachill.com - Mobile API

### Search Result Structure
```php
array(
    'ID'           => 123,                    // Post ID
    'post_title'   => 'Post Title',           // Post title
    'post_content' => 'Full content...',      // Full content
    'post_excerpt' => 'Excerpt...',           // Excerpt
    'post_date'    => '2025-10-07 12:00:00',  // Publication date
    'post_type'    => 'post',                 // Post type
    'post_name'    => 'post-slug',            // Post slug
    'post_author'  => 1,                      // Author ID
    'site_id'      => 1,                      // Blog ID
    'site_name'    => 'Extra Chill',          // Site name
    'site_url'     => 'extrachill.com',       // Site URL
    'permalink'    => 'https://...',          // Full post URL
    'taxonomies'   => array(                  // Taxonomy terms
        'category' => array( 'term_name' => term_id ),
    ),
)
```

### Performance Optimizations
- **Static Caching** - Network sites cached in memory
- **WordPress Native Caching** - `get_blog_id_from_url()` uses blog-id-cache automatically
- **Efficient Blog Switching** - Minimal context switching with proper error handling
- **Cross-Site Date Sorting** - Results sorted chronologically across all sites

## Theme Integration

### Required Theme Functions
The plugin expects your theme to provide:
- `extrachill_breadcrumbs()` - Breadcrumb navigation
- `extrachill_no_results()` - No results message
- `extrachill_display_taxonomy_badges()` - Taxonomy badge display
- `inc/archives/post-card.php` - Post card template

### Action Hooks Used
- `extrachill_before_body_content` - Before main content area
- `extrachill_after_body_content` - After main content area
- `extrachill_search_header` - Search results header area
- `extrachill_archive_below_description` - Below archive description
- `extrachill_archive_above_posts` - Above posts loop

### Template Override Filter
The plugin overrides the search template using:
```php
add_filter( 'extrachill_template_search', 'override_search_template', 10 );
```

## File Structure

```
extrachill-search/
├── extrachill-search.php           # Main plugin file
├── inc/
│   ├── core/
│   │   ├── search-functions.php    # Core multisite search functionality
│   │   └── taxonomy-functions.php  # Placeholder for future taxonomy features
│   └── templates/
│       ├── template-functions.php  # Template helper functions
│       └── site-badge.php          # Site badge display component
├── templates/
│   └── search.php                  # Search results template
├── build.sh                        # Production build script
├── .buildignore                    # Build exclusions
├── CLAUDE.md                       # Developer documentation
└── README.md                       # This file
```

## Development

### Build Production Package
```bash
# Create production ZIP file
./build.sh

# Output: /build/extrachill-search/ directory and /build/extrachill-search.zip file
```

### Development Commands
```bash
# Check PHP syntax
php -l extrachill-search.php

# Test search function (requires WordPress environment)
wp eval 'print_r(extrachill_multisite_search("test"));'
```

## Support

- **Issues**: [GitHub Issues](https://github.com/Extra-Chill/extrachill-search/issues)
- **Documentation**: See CLAUDE.md for detailed developer documentation
- **Website**: [Extra Chill Platform](https://extrachill.com)

## Author

**Chris Huber**
- Website: [chubes.net](https://chubes.net)
- GitHub: [@chubes4](https://github.com/chubes4)
- Extra Chill: [extrachill.com](https://extrachill.com)

## License

GPL v2 or later
