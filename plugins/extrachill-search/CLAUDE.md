# ExtraChill Search

Network-activated WordPress plugin providing centralized multisite search for the ExtraChill Platform. Searches across the network's nine active sites and displays unified results. Front-end searches are **scoped to the current site by default**, with an opt-in "Entire network" toggle (see Search Scope below).

## Plugin Information

- **Name**: ExtraChill Search
- **Version**: 0.2.2
- **Text Domain**: `extrachill-search`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: true (network activated across all sites)
 - **Requires at least**: 6.9
 - **Tested up to**: 6.9

## Architecture

### Plugin Loading Pattern
- **Procedural WordPress Pattern**: Uses direct `require_once` includes for all functionality
- **Singleton Class**: Main plugin class uses singleton pattern for initialization
- **No PSR-4 Autoloading**: All files loaded via explicit includes
- **Network Plugin Structure**: Network-activated plugin available to all multisite installations

### Core Functionality

#### Multisite Search System
**Universal Network Search** (`inc/core/search-functions.php`):
- **`extrachill_multisite_search( $term, $site_urls = array(), $args = array() )`**: Core search primitive. An empty `$site_urls` searches every network site; a non-empty list restricts to those sites.
- **Canonical Site Map**: `extrachill_get_network_site_map()` builds the searchable site list from `ec_get_domain_map()` (extrachill-multisite), filtered via `extrachill_search_site_map`. No `get_sites()` discovery.
- **Per-Site Post Types**: `extrachill_get_site_post_types()` maps each blog ID to its searchable post types (filterable via `extrachill_search_site_post_types_map`).
- **Pagination and Sorting**: Supports limit, offset, orderby, order with cross-site date + relevance sorting.
- **Relevance Scoring**: Weighted algorithm prioritizing exact matches, phrase matches, and word-level matching (FULLTEXT MATCH AGAINST where indexed, with a word-level fallback).
- **Network Site Discovery**: `extrachill_get_network_sites()` with static caching for performance.
- **Site URL Resolution**: `extrachill_resolve_site_urls()` converts domain/host strings (or numeric blog IDs) to blog IDs.

#### Search Scope (`inc/core/search-scope.php`)
Front-end searches default to the **current site only** so visitors are not surprised by network-wide results. A `search_scope` query var carries the choice:
- `site` (default) → restricts to the current blog's host via `extrachill_search_scope_site_urls()`.
- `network` → empty `$site_urls`, i.e. the whole network.

Key functions:
- **`extrachill_resolve_search_scope()`**: reads + validates `$_GET['search_scope']`, falling back to the default scope.
- **`extrachill_search_scope_site_urls( $scope = null )`**: resolves a scope into the `$site_urls` argument for the search primitive.
- **`extrachill_search_default_scope()`**: the default (`site`), filterable.

The underlying `extrachill_multisite_search()` primitive is unchanged — empty `$site_urls` still means "whole network". Only the front-end default policy is scoped. The theme's `searchform.php` renders the "This site / Entire network" toggle. Filters: `extrachill_search_default_scope`, `extrachill_search_scope`, `extrachill_search_scope_site_urls`.

#### Pagination Fix Architecture
**404 Override System** (`extrachill-search.php`):
- **`fix_search_404()` method**: Intercepts 404 errors on paginated search queries
- **`template_redirect` hook**: Executes at priority 1 before template loading
- **Network-Wide Fix**: Resolves pagination 404s across network sites; respects the active search scope (previously only worked on extrachill.com)
- **Intelligent Detection**: Checks for 404 status with search query parameter (`s`), not relying on `is_search()`
- **Result Verification**: Runs `extrachill_multisite_search()` to verify multisite results exist for current page
- **Query Override**: Sets `$wp_query->is_404 = false` and `$wp_query->is_search = true` when results found
- **Status Header Fix**: Sets `status_header(200)` to prevent 404 HTTP response
- **Template Routing**: WordPress then loads `search.php` template instead of `404.php`

**Why This Fix Is Needed**:
WordPress native search only checks the current site for results. When paginating multisite search results, if the current site has no matching posts on that page, WordPress returns 404 even though other network sites have results. The `fix_search_404()` method queries all network sites to verify results exist before allowing the 404.

#### Template System
**Search Results Template** (`templates/search.php`):
- Displays unified search results from all network sites
- Shows site badges indicating result origin
- Uses theme's `post-card.php` template for consistent styling
- Integrates with theme action hooks (`extrachill_before_body_content`, `extrachill_after_body_content`)
- Supports theme functions (`extrachill_breadcrumbs()`, `extrachill_no_results()`)

**Template Helper Functions** (`inc/templates/template-functions.php`):
- **`extrachill_get_search_results()`**: Fetches paginated search results with total count
- **`extrachill_create_search_query_object()`**: Creates mock WP_Query object for theme pagination compatibility

**Site Badge Component** (`inc/templates/site-badge.php`):
- **`extrachill_search_site_badge()`**: Displays site badge indicating result origin
- Hooked to `extrachill_archive_above_tax_badges` action
- Uses metadata attached to post object (`_site_name`, `_site_url`, `_origin_site_id`)
- Only displays on search pages with multisite results

 #### Taxonomy Functions (Placeholder)
 **Future Functionality** (`inc/core/taxonomy-functions.php`):
 - Currently placeholder for future multisite taxonomy archive sharing
 - Taxonomy badge display handled by ExtraChill theme
 - Taxonomy archive sharing deferred for future implementation

#### Abilities API Integration (WordPress 6.9+)
**Ability Registration** (`inc/core/abilities.php`):
 - **`extrachill_register_abilities()`**: Registers `extrachill/multisite-search` ability
 - **`extrachill_ability_multisite_search()`**: Execute callback wrapping the core search function
 - **Permission Level**: Open to all authenticated users (`read` capability)
 - **REST API**: Enabled via `show_in_rest: true` meta
 - **Annotations**: Marked as `readonly`, `idempotent`, non-destructive
 - **Conditional Loading**: Only loads when WordPress 6.9+ is detected
 - **Input Schema**: Full parameter support (search_term, site_urls, limit, offset, post_status, orderby, order, return_count)
 - **Output Schema**: Same as core search function - results array or paginated object with total count

## WordPress Multisite Integration

### Network Sites Covered
Sites are resolved at runtime from `ec_get_domain_map()` in `extrachill-multisite` (not hardcoded here, and not via `get_sites()`). The nine active sites:
1. **extrachill.com** - Main music journalism site (Blog ID 1)
2. **community.extrachill.com** - Community forums (bbPress) (Blog ID 2)
3. **shop.extrachill.com** - E-commerce (WooCommerce) (Blog ID 3)
4. **artist.extrachill.com** - Artist platform and profiles (Blog ID 4)
5. **events.extrachill.com** - Event calendar hub (Blog ID 7; calendar engine comes from external Data Machine + datamachine-events plugins)
6. **newsletter.extrachill.com** - Newsletter management hub (Blog ID 9)
7. **docs.extrachill.com** - Documentation hub (Blog ID 10)
8. **wire.extrachill.com** - News wire (Blog ID 11)
9. **studio.extrachill.com** - Studio / internal team workspace (Blog ID 12)

Blog ID 8 (`stream.extrachill.com`) was decommissioned (April 2026); Blog IDs 5–6 are unused historical artifacts.

### Native WordPress Functions Used
- **`switch_to_blog()`**: Cross-site database access
- **`restore_current_blog()`**: Restore original site context
- **`is_multisite()`**: Multisite installation detection
- **`get_blog_details()`**: Site metadata retrieval
- **FULLTEXT `MATCH ... AGAINST`**: Sub-second relevance search where a FULLTEXT index exists, with a WP `LIKE`/word-level fallback otherwise

Site enumeration comes from `ec_get_domain_map()` (extrachill-multisite), **not** `get_sites()`.

### Performance Optimizations
- **Static Caching**: Network sites cached in memory via `extrachill_get_network_sites()`
- **WordPress Blog-ID-Cache**: Automatic blog ID caching for optimal performance
- **Efficient Blog Switching**: Minimal context switching with proper error handling
- **Cross-Site Date Sorting**: Results sorted by date across all sites for unified chronology

## File Structure

 ```
 extrachill-search/
 ├── extrachill-search.php           # Main plugin file with singleton initialization
 ├── inc/
 │   └── core/
 │       ├── search-functions.php    # Site map, post-type map, term normalization
 │       ├── search-scope.php        # Scope resolution (site vs network) for front-end searches
 │       ├── search-algorithm.php    # Search execution, FULLTEXT, relevance scoring
 │       ├── taxonomy-functions.php  # Placeholder for future taxonomy archives
 │       └── abilities.php           # WordPress 6.9+ Abilities API integration
 ├── templates/
 │   ├── search.php                  # Search results template
 │   ├── template-functions.php      # Template helper functions for search results
 │   └── site-badge.php              # Site badge component for multisite results
 ├── CLAUDE.md                       # This documentation file
 └── README.md                       # GitHub standard format documentation
 ```

## Theme Integration

### Required Theme Functions
The plugin expects the ExtraChill theme to provide:
- **`extrachill_breadcrumbs()`**: Breadcrumb navigation display
- **`extrachill_no_results()`**: No results found message
- **`extrachill_display_taxonomy_badges()`**: Taxonomy badge display for posts
- **`inc/archives/post-card.php`**: Post card template for result display

### Theme Action Hooks Used
**Hooks Used by Plugin**:
- **`extrachill_before_body_content`**: Before main content area (search.php template)
- **`extrachill_after_body_content`**: After main content area (search.php template)
- **`extrachill_search_header`**: Search results header area - theme renders `archive-header.php` which has `is_search()` case for "Search Results for:" title
- **`extrachill_archive_below_description`**: Below archive description (search.php template)
- **`extrachill_archive_above_posts`**: Above posts loop - theme renders filter bar with search-specific items (sort dropdown + search input)

**Hooks Provided by Plugin**:
- **`extrachill_archive_above_tax_badges`**: Site badge display hook (used by site-badge.php component)
- **`extrachill_search_args`**: Filter for customizing search query arguments
- **`extrachill_search_scoring_weights`**: Filter for customizing relevance scoring weights

### Template Override Filter
The plugin uses the ExtraChill theme's `extrachill_template_search` filter at priority 10 to override the default search template:
```php
add_filter( 'extrachill_template_search', array( $this, 'override_search_template' ), 10 );
```

This integrates with the theme's universal template routing system rather than using WordPress's generic `template_include` filter.

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
    'ID'            => 123,                    // Post ID
    'post_title'    => 'Post Title',           // Post title
    'post_content'  => 'Full content...',      // Full post content
    'post_excerpt'  => 'Excerpt or trimmed...', // Excerpt
    'post_date'     => '2025-10-07 12:00:00',  // Publication date
    'post_modified' => '2025-10-08 14:30:00',  // Last modified date
    'post_type'     => 'post',                 // Post type
    'post_name'     => 'post-slug',            // Post slug
    'post_author'   => 1,                      // Author ID
    'site_id'       => 1,                      // Blog ID
    'site_name'     => 'Extra Chill',          // Site name
    'site_url'      => 'extrachill.com',       // Site URL (host only)
    'permalink'     => 'https://...',          // Full post URL
    'taxonomies'    => array(                  // Taxonomy terms
        'category' => array( 'term_name' => term_id ),
    ),
    'thumbnail'     => array(                  // Featured image data
        'thumbnail_id'     => 456,
        'thumbnail_url'    => 'https://...',
        'thumbnail_srcset' => '...',
        'thumbnail_sizes'  => '...',
        'thumbnail_alt'    => 'Alt text',
    ),
    '_search_score' => 750,                    // Internal relevance score (when searching)
)
```

### Relevance Scoring Algorithm
The plugin uses a weighted relevance scoring system (via `extrachill_calculate_search_score()`) that prioritizes exact matches:

**Scoring Weights** (filterable via `extrachill_search_scoring_weights`):
- **Exact title match**: 1000 points
- **Title contains exact phrase**: 500 points
- **Phrase at start of title**: +200 bonus points
- **All search words in title**: 400 points + 25 per word
- **Content occurrences**: 50 points per occurrence (max 200)
- **Recency bonus**: Up to 100 points (diminishing over 365 days)

**Multi-word Search Example**: "grateful dead althea meaning"
- Matches title "The Meaning of the Grateful Dead's 'Althea'" with word-level matching
- All four words present in title = 400 + (4 × 25) = 500 points + recency

**Sorting**: Results sorted by relevance score (descending), with post date as tiebreaker

## Build System

### Build System
- **Build System**: Use `homeboy build extrachill-search` for production builds
- **Production Build**: Creates `/build/extrachill-search.zip` file only
- **Composer Integration**: Production builds use `composer install --no-dev`, restores dev dependencies after
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Structure Validation**: Ensures network plugin integrity before packaging

### Build Process
```bash
# Create production build
homeboy build extrachill-search
```

**Output**:
- `/build/extrachill-search.zip` - Non-versioned deployment package (ZIP file only)

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
