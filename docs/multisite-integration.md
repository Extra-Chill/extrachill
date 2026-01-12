# Multisite Integration

Integration with WordPress multisite network and the extrachill-multisite plugin.

## Network Overview

ExtraChill theme serves the active sites in the WordPress multisite network (Blog ID 6 unused):

1. **extrachill.com** - Main music journalism and content site (Blog ID 1)
2. **community.extrachill.com** - Community forums and user hub (Blog ID 2)
3. **shop.extrachill.com** - E-commerce platform with WooCommerce (Blog ID 3)
4. **artist.extrachill.com** - Artist platform and profiles (Blog ID 4)
5. **chat.extrachill.com** - AI chatbot system (Blog ID 5)
6. **events.extrachill.com** - Event calendar hub (Blog ID 7; calendar engine comes from external Data Machine + datamachine-events plugins)
7. **stream.extrachill.com** - Live streaming platform (Phase 1 UI) (Blog ID 8)
8. **newsletter.extrachill.com** - Newsletter management and archive hub (Blog ID 9)
9. **docs.extrachill.com** - Documentation hub (Blog ID 10)
10. **wire.extrachill.com** - Automated news feeds directory (Blog ID 11)
11. **horoscope.extrachill.com** - Horoscope functionality (Blog ID 12)

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
- Homepage grids are plugin-owned via `extrachill_homepage_content` (theme keeps the shared helper + sidebar wrapper)

**Architecture**:
- **Centralized Helper**: `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions provide reusable library
- **Community Queries**: Queries community.extrachill.com for bbPress topics/replies.
- **Activity Display**: Displays community activities with 10-minute caching
- Direct `WP_Query` for bbPress topics and replies
- Uses `switch_to_blog()` and `restore_current_blog()` for cross-site access
- Manual forum URL construction: `https://community.extrachill.com/r/{forum-slug}`
- User profile URLs via `ec_get_user_profile_url()` from extrachill-users plugin (community-first)
- 10-minute WordPress object cache (`wp_cache_get()` / `wp_cache_set()`)

**Blog ID Resolution**:
```php
$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

if ( $community_blog_id ) {
    try {
        switch_to_blog( $community_blog_id );
        // Query bbPress data
    } finally {
        restore_current_blog();
    }
}
```


**URL Patterns**:
- Forums: `https://community.extrachill.com/r/{forum-slug}` (NOT `/forums/forum/`)
- User profiles: `https://community.extrachill.com/u/{username}` (via `ec_get_user_profile_url()`, community-first)
- Author archives: `https://extrachill.com/author/{username}/` (via `ec_get_user_author_archive_url()`)
- Topics: Standard `get_permalink()` works for topic URLs
- Total member counts: Pulled from Blog ID 2 via `count_users()` when rendering online stats

## Theme-Level Multisite Functions

Theme uses centralized shared helper library for community activity display:

**Shared Helper Functions**:

```
// Get activity items from community.extrachill.com (blog 2)
$items = extrachill_get_community_activity_items(5);

// Render activity with custom styling options
extrachill_render_community_activity(array(
    'items'          => $items,
    'render_wrapper' => false,
    'item_class'     => 'sidebar-activity-card',
));
```

**Data Function**: `extrachill_get_community_activity_items( $limit )`
- Queries blog ID 2 (community) for bbPress activities
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
// General profile links (mentions, avatar menu, “View Profile”) should resolve to community when possible.
$user_profile_url = ec_get_user_profile_url( $user_id );

// Article contexts (bylines, “More articles by…”) should use the explicit author archive helper.
$user_author_archive_url = ec_get_user_author_archive_url( $user_id );
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

## Blog/Domain Resolution

**Theme Pattern**: Theme uses canonical helper functions for blog/domain resolution.

- Runtime blog/domain mapping uses `ec_get_blog_id()` / `ec_get_domain_map()` from `extrachill-multisite/inc/core/blog-ids.php`.
- Numeric mapping is permitted only in `.github/sunrise.php` (executes before WordPress loads).

**Implementation**:
```php
$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

if ( $community_blog_id ) {
    try {
        switch_to_blog( $community_blog_id );
        // Cross-site operations
    } finally {
        restore_current_blog();
    }
}
```

## Network-Wide Features

Provided by the `extrachill-multisite` plugin:

- **Cross-Site Data Access**: Native WordPress `switch_to_blog()` and `restore_current_blog()`
- **Real-Time Search**: Direct database queries eliminate REST API overhead
- **Network Security**: Admin access control across all sites
- **License Validation**: Cross-domain ad-free license checking
- **Unified Authentication**: WordPress multisite native user authentication

## Theme Multisite Integration Points

### 1. Search Template
**Location**: Provided by extrachill-search plugin via `extrachill_template_search`
**Integration**: Theme still outputs search header + assets before handing loop control to plugin

### 2. Post Meta Display
**Location**: `/inc/core/templates/post-meta.php`
**Integration**:
- Uses `ec_get_user_author_archive_url()` for main site bylines
- Uses `ec_get_user_profile_url()` for community/forum identity links

### 3. Community Activity Components
**Location**: `/inc/core/templates/community-activity.php`
**Integration**: Centralized shared helper library with reusable `extrachill_get_community_activity_items()` and `extrachill_render_community_activity()` functions. Queries community.extrachill.com (blog ID 2) for bbPress activities via `switch_to_blog()` with 10-minute caching. Sidebar rendering lives in `/inc/sidebar/community-activity.php`; homepage grids now live in plugins via `extrachill_homepage_content`.

### 5. Search Site Badges
**Integration**: extrachill-search plugin injects badges alongside each multisite result entry so users know which network site provided the content

## Direct Database Access Pattern

Theme **directly accesses** bbPress data from community.extrachill.com for activity widgets using WordPress multisite functions:

**Multi-Site Pattern**:
```php
$community_blog_id = function_exists( 'ec_get_blog_id' ) ? ec_get_blog_id( 'community' ) : null;

if ( $community_blog_id ) {
    try {
        switch_to_blog( $community_blog_id );

        // Direct WP_Query for bbPress data
        $query = new WP_Query(
            array(
                'post_type' => array( 'topic', 'reply' ),
                // ... query args
            )
        );

        // Collect activities
        // ...
    } finally {
        restore_current_blog();
    }

    // Manual URL construction
    $forum_url = 'https://community.extrachill.com/r/' . get_post_field( 'post_name', $forum_id );
}
```


## Graceful Degradation

The theme assumes network plugins are active in production and uses function-existence checks before calling into other plugins. If a dependency is inactive, the integration point simply does not render.

## Performance Considerations

**Caching**:
- Community activity uses a 10-minute WordPress object cache.
- Search caching is owned by the `extrachill-search` plugin.

**Efficiency**:
- Cross-site reads use `switch_to_blog()` with `try/finally`.
- Blog resolution uses `ec_get_blog_id()` (single source of truth) and avoids ad-hoc numeric fallbacks.

## Why This Architecture

**Community Activity Display**: Unified activity feed sourced from community.extrachill.com
**Centralized Helper Library**: Reusable functions eliminate code duplication across widgets
**Direct Database Access**: Theme directly queries bbPress data for optimal performance
**WordPress Native Functions**: Uses core multisite functions (no abstraction overhead)
**Blog ID helpers**: Runtime code uses `ec_get_blog_id()` for clarity and centralized mapping (numeric mapping is reserved for `.github/sunrise.php`).
**Manual URL Construction**: Avoids bbPress function calls for forum URLs
**Plugin Integration**: Leverages extrachill-users for intelligent profile routing
**Caching Strategy**: WordPress object cache provides 10-minute cached results
**Performance**: Eliminates plugin abstraction layers for community activity display
