# Multisite Integration

Integration with WordPress multisite network and the extrachill-multisite plugin.

## Network Overview

ExtraChill theme serves **all 8 active sites** in the WordPress multisite network (Blog ID 6 unused; horoscope.extrachill.com planned for future Blog ID 10):

1. **extrachill.com** - Main music journalism and content site (Blog ID 1)
2. **community.extrachill.com** - Community forums and user hub (Blog ID 2)
3. **shop.extrachill.com** - E-commerce platform with WooCommerce (Blog ID 3)
4. **artist.extrachill.com** - Artist platform and profiles (Blog ID 4)
5. **chat.extrachill.com** - AI chatbot system (Blog ID 5)
6. **events.extrachill.com** - Event calendar hub (Blog ID 7)
7. **stream.extrachill.com** - Live streaming platform (Phase 1 UI) (Blog ID 8)
8. **newsletter.extrachill.com** - Newsletter management and archive hub (Blog ID 9)

## Plugin Dependency

**Required Plugin**: extrachill-multisite (network-activated)

The theme integrates with multisite features but **does not implement them directly**. All multisite functionality provided by the extrachill-multisite plugin.

## Multisite Features Used

### Cross-Site Search

**Function**: `extrachill_multisite_search( $search_query )`

**Plugin**: extrachill-search (network-activated)

Searches across all network sites or specified sites.

**Theme Integration**:
```php
// In search template
if ( function_exists( 'extrachill_multisite_search' ) ) {
    $results = extrachill_multisite_search( get_search_query() );
}
```

**Returns**: Combined array of:
- Main site posts
- Community forum topics and replies
- Site identification metadata
- Contextual excerpts

### Community Activity Widgets

**Theme Implementation**: Centralized shared helper library performs direct bbPress queries with multisite blog switching

**Locations**:
- `/inc/core/templates/community-activity.php` - Shared helper library with reusable data and render functions
- `/inc/sidebar/community-activity.php` - Sidebar wrapper calling shared helper with sidebar-specific styling
- `/inc/home/templates/section-3x3-grid.php` - Homepage 3x3 grid calling shared helper with grid styling

**Architecture**:
- **Centralized Helper**: `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions provide reusable library
- **Multi-Site Queries**: Queries BOTH community.extrachill.com (blog ID 2) AND artist.extrachill.com (blog ID 4) for bbPress topics/replies
- **Activity Merging**: Combines activities from both sites into unified, chronologically sorted list
- Direct `WP_Query` for bbPress topics and replies
- Uses `switch_to_blog()` and `restore_current_blog()` for cross-site access
- Manual forum URL construction: `https://community.extrachill.com/r/{forum-slug}`
- User profile URLs via `ec_get_user_profile_url()` from extrachill-users plugin
- 10-minute WordPress object cache (`wp_cache_get()` / `wp_cache_set()`)

**Blog ID Resolution**:
```php
// Hardcoded blog IDs for performance
$community_blog_id = 2; // community.extrachill.com
$artist_blog_id = 4;    // artist.extrachill.com

foreach (array($community_blog_id, $artist_blog_id) as $blog_id) {
    switch_to_blog($blog_id);
    // Query bbPress data from each site
    restore_current_blog();
}
// Merge and sort chronologically
```

**URL Patterns**:
- Forums: `https://community.extrachill.com/r/{forum-slug}` (NOT `/forums/forum/`)
- User profiles: `https://community.extrachill.com/u/{username}` (via `ec_get_user_profile_url()`)
- Topics: Standard `get_permalink()` works for topic URLs

## Theme-Level Multisite Functions

Theme uses centralized shared helper library for community activity display:

**Shared Helper Functions**:

```php
// Get activity items from both community and artist sites
$items = extrachill_get_community_activity_items(5);

// Render activity with custom styling options
extrachill_render_community_activity(array(
    'items'          => $items,
    'render_wrapper' => false,
    'item_class'     => 'sidebar-activity-card',
));
```

**Data Function**: `extrachill_get_community_activity_items( $limit )`
- Queries both blog ID 2 (community) and blog ID 4 (artist) for bbPress activities
- Merges results chronologically
- Returns array of activity items with full metadata
- 10-minute WordPress object cache

**Render Function**: `extrachill_render_community_activity( $args )`
- Customizable HTML structure and CSS classes
- Accepts pre-fetched items or fetches automatically
- Configurable wrapper, item styling, and empty states

## Multisite URL Structure

**Main Site**: extrachill.com
- Posts: `/category/post-name/`
- Pages: `/page-name/`
- Archives: `/category-name/`
- Authors: `/author/username/`

**Community Site**: community.extrachill.com
- Forums: `/r/{forum-slug}/` (short URL format)
- Topics: Standard WordPress permalinks
- Users: `/u/{username}/` (bbPress profile format)

## Cross-Site Links

Theme generates community links using standardized patterns:

**User Profile Links** (via extrachill-users plugin):
```php
// Intelligently routes to main site author page or bbPress profile
$user_profile_url = ec_get_user_profile_url( $user_id );
```

**Forum Links** (manual construction):
```php
// Forum URL pattern: /r/{forum-slug}
$forum_url = 'https://community.extrachill.com/r/' . get_post_field('post_name', $forum_id);
```

**Topic Links** (WordPress standard):
```php
// Standard WordPress permalinks work for topics
$topic_url = get_permalink( $topic_id );
```

## Authentication

**Managed By**: extrachill-multisite plugin

Theme does not handle authentication. WordPress native multisite authentication provides seamless login across all `.extrachill.com` subdomains.

## Site Identification

**Search Results**: Site badges identify result source

**Function**: `extrachill_search_site_badge( $site_name )`
**Location**: `/inc/archives/search/search-site-badge.php`

Used in search results to identify which site results came from.

## Blog ID Resolution

**Theme Pattern**: Theme uses **hardcoded blog ID numbers** for optimal performance

**Implementation**:
```php
// Direct blog ID numbers for community and artist sites
$community_blog_id = 2; // community.extrachill.com
$artist_blog_id = 4;    // artist.extrachill.com

switch_to_blog($community_blog_id);
// Cross-site operations
restore_current_blog();
```

**Performance**: Direct blog ID usage eliminates lookup overhead and provides maximum performance

## Network-Wide Features

Provided by extrachill-multisite plugin:

- **Cross-Site Data Access**: Native WordPress `switch_to_blog()` and `restore_current_blog()`
- **Real-Time Search**: Direct database queries eliminate REST API overhead
- **Network Security**: Admin access control across all sites
- **License Validation**: Cross-domain ad-free license checking
- **Unified Authentication**: WordPress multisite native user authentication

## Theme Multisite Integration Points

### 1. Search Template
**Location**: `/inc/archives/search/search.php`
**Integration**: Calls `extrachill_multisite_search()` from extrachill-search plugin

### 2. Post Meta Display
**Location**: `/inc/core/templates/post-meta.php`
**Integration**: Uses `ec_get_user_profile_url()` from extrachill-users plugin for author links

### 3. Community Activity Components
**Location**: `/inc/core/templates/community-activity.php`
**Integration**: Centralized shared helper library with reusable `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions. Queries BOTH blog ID 2 (community) and blog ID 4 (artist) for bbPress activities via `switch_to_blog()`, merges chronologically, and provides configurable rendering. Wrapped in `/inc/sidebar/community-activity.php` (sidebar styling) and `/inc/home/templates/section-3x3-grid.php` (grid styling).

### 5. Search Site Badges
**Location**: `/inc/archives/search/search-site-badge.php`
**Integration**: Identifies result source in multisite search

## Direct Database Access Pattern

Theme **directly accesses** bbPress data from BOTH community and artist sites for activity widgets using WordPress multisite functions:

**Multi-Site Pattern**:
```php
// Direct blog switching with hardcoded blog IDs
$community_blog_id = 2; // community.extrachill.com
$artist_blog_id = 4;    // artist.extrachill.com

$all_activities = array();

foreach (array($community_blog_id, $artist_blog_id) as $blog_id) {
    switch_to_blog($blog_id);

    // Direct WP_Query for bbPress data
    $query = new WP_Query(array(
        'post_type' => array('topic', 'reply'),
        // ... query args
    ));

    // Collect activities from this site
    // ...

    restore_current_blog();
}

// Merge and sort chronologically
usort($all_activities, function($a, $b) {
    return strtotime($b['date_time']) - strtotime($a['date_time']);
});

// Manual URL construction
$forum_url = 'https://community.extrachill.com/r/' . get_post_field('post_name', $forum_id);
```

## Graceful Degradation

Theme functions without network plugins:

**Without extrachill-search**:
- Search works (single-site only)
- No cross-site forum results

**Without extrachill-users**:
- User profile URLs fall back to standard author URLs
- Avatar menu not available

**Without community.extrachill.com or artist.extrachill.com**:
- Community activity widgets show partial or no results
- Missing site activities gracefully excluded from display
- No forum integration from missing sites

**With All Plugins**:
- Cross-site search via extrachill-search
- Intelligent profile URL routing via extrachill-users
- Community activity widgets display forum activity
- Enhanced multisite features

## Performance Considerations

**Caching**:
- Community activity: 10-minute WordPress object cache
- Hardcoded blog IDs: No lookup overhead (direct integer usage)
- Search results: Plugin-managed caching

**Efficiency**:
- **Multi-Site Queries**: Single cached result contains activities from both community and artist sites
- Direct WP_Query for bbPress data (no plugin abstraction layer)
- Hardcoded blog ID numbers (maximum performance, no lookup overhead)
- WordPress object cache for merged query results
- Efficient blog switching (only 2 sites queried)
- Chronological merge operation after data collection
- Manual URL construction (no bbPress function overhead)

**Architecture Benefits**:
- Direct database access eliminates REST API overhead
- Hardcoded blog IDs provide optimal performance
- WordPress object caching reduces query load
- Conditional loading prevents unnecessary queries

## Why This Architecture

**Multi-Site Activity Display**: Unified activity feed from both community and artist platforms
**Centralized Helper Library**: Reusable functions eliminate code duplication across widgets
**Direct Database Access**: Theme directly queries bbPress data for optimal performance
**WordPress Native Functions**: Uses core multisite functions (no abstraction overhead)
**Hardcoded Blog IDs**: Direct integer usage for both sites eliminates lookup overhead
**Chronological Merging**: Activities sorted across sites for unified timeline
**Manual URL Construction**: Avoids bbPress function calls for forum URLs
**Plugin Integration**: Leverages extrachill-users for intelligent profile routing
**Caching Strategy**: WordPress object cache provides 10-minute merged result caching
**Performance**: Eliminates plugin abstraction layers for community activity display
