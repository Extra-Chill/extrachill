# Template Components

Reusable template components for consistent display across the theme.

## Pagination Component

**Location**: `/inc/core/templates/pagination.php`
**Function**: `extrachill_pagination( $query, $context )`

### Parameters

- `$query` (WP_Query|null) - Custom query or global `$wp_query`
- `$context` (string) - Styling context: 'default', 'archive', 'search'

### Features

- Post count display with intelligent formatting
- Previous/Next navigation
- Page number links with ellipsis
- URL parameter preservation
- Mobile responsive

### Usage

```php
// Use global query
extrachill_pagination();

// Use custom query
$query = new WP_Query( $args );
extrachill_pagination( $query, 'archive' );
```

### Output Format

```
Viewing posts 1-10 of 156 total
[« Previous] [1] [2] [3] ... [16] [Next »]
```

### Count Display Logic

- Single post: "Viewing 1 post"
- Single result on page: "Viewing post 5 of 156"
- Multiple results: "Viewing posts 1-10 of 156 total"

## Post Meta Component

**Location**: `/inc/core/templates/post-meta.php`
**Function**: `extrachill_entry_meta()`

### Displays

**Standard Posts**:
- Publication date
- Author (with Co-Authors Plus support)
- Last updated date (if modified)

**Forum Posts** (multisite):
- Post date
- Forum author with community link
- Forum name with link

### Filter Hook

```php
apply_filters( 'extrachill_post_meta', $default_meta, $post_id, $post_type )
```

### Usage

```php
// In templates
extrachill_entry_meta();

// Customize output
add_filter( 'extrachill_post_meta', function( $meta, $post_id, $post_type ) {
    return $meta . '<div>Custom meta</div>';
}, 10, 3 );
```

### Co-Authors Plus Integration

Automatically uses `coauthors_posts_links()` when plugin active, falls back to `the_author_posts_link()`.

### Update Detection

Shows "Last Updated" date only when:
- Modification date differs from publication date
- Difference is at least 1 day

## Taxonomy Badges Component

**Location**: `/inc/core/templates/taxonomy-badges.php`
**Function**: `extrachill_display_taxonomy_badges( $post_id, $args )`

### Parameters

- `$post_id` (int|null) - Post ID (defaults to current post)
- `$args` (array) - Configuration options

### Arguments

```php
$args = array(
    'wrapper_class' => 'taxonomy-badges',  // Wrapper CSS class
    'show_wrapper'  => true,                // Show wrapper div
    'wrapper_style' => '',                  // Inline styles
);
```

### Displays Badges For

- Categories
- Tags
- Custom taxonomies (location, festival, artist, venue)
- Any registered taxonomy (except 'author')

### Usage

```php
// Display all taxonomy badges
extrachill_display_taxonomy_badges();

// Display for specific post
extrachill_display_taxonomy_badges( 123 );

// Custom wrapper
extrachill_display_taxonomy_badges( null, array(
    'wrapper_class' => 'custom-badges',
    'wrapper_style' => 'margin-bottom: 20px;'
) );

// No wrapper
extrachill_display_taxonomy_badges( null, array(
    'show_wrapper' => false
) );
```

### Badge Styling

Badges use CSS classes for styling:
- `.taxonomy-badge` - Base class
- `.category-badge` - Category badges
- `.artist-badge` - Artist badges
- `.festival-badge` - Festival badges
- `.location-badge` - Location badges
- `.venue-badge` - Venue badges

**Category-specific**:
- `.category-{slug}-badge` - Per-category colors

**Styling File**: `/assets/css/badge-colors.css`

## Breadcrumb Component

**Location**: `/inc/core/templates/breadcrumbs.php`
**Function**: `extrachill_breadcrumbs()`

### Displays For

- Single posts (with category and tag hierarchy)
- Pages (with parent hierarchy)
- Archives (category, tag, taxonomy, author, date)
- Custom post types

### Features

- Hierarchical navigation
- Parent term/category support
- Custom taxonomy integration
- WooCommerce exclusion (handled by shop plugin)
- Override capability via `override_display_breadcrumbs()`

### Usage

```php
// Display breadcrumbs
extrachill_breadcrumbs();
```

### Format Examples

**Single Post**:
```
Home › Category › Tag › Post Title
```

**Page with Parent**:
```
Home › Parent Page › Current Page
```

**Category Archive**:
```
Home › Parent Category › Child Category
```

**Taxonomy Archive**:
```
Home › Locations › Colorado › Denver
```

## Social Links Component

**Location**: `/inc/core/templates/social-links.php`
**Function**: `extrachill_social_links()`
**Hook**: `extrachill_social_links`

### Social Platforms

- Facebook: facebook.com/extrachill
- Twitter/X: twitter.com/extra_chill
- Instagram: instagram.com/extrachill
- YouTube: youtube.com/@extra-chill
- Pinterest: pinterest.com/extrachill
- GitHub: github.com/Extra-Chill

### Icon Source

Uses `ec_icon()` helper with extrachill.svg sprite:
```php
<?php echo ec_icon('facebook'); ?>
```

### Usage

```php
// Display social links
do_action( 'extrachill_social_links' );

// Or direct function call
extrachill_social_links();
```

### Output Structure

```html
<div class="social-links">
    <ul>
        <li><a href="..." aria-label="Facebook"><svg>...</svg></a></li>
        <!-- ... more platforms ... -->
    </ul>
</div>
```

## Share Button Component

**Location**: `/inc/core/templates/share.php`
**Function**: `extrachill_share_button( $args )`
**Hook**: `extrachill_share_button`

### Parameters

```php
$args = array(
    'share_url'         => get_permalink(),      // URL to share
    'share_title'       => get_the_title(),      // Title to share
    'share_description' => '',                    // Description (optional)
    'share_image'       => '',                    // Image URL (optional)
);
```

### Share Options

- Facebook
- Twitter/X
- Email
- Copy Link (with clipboard API + fallback)

### Usage

```php
// Display with defaults
do_action( 'extrachill_share_button' );

// Custom share data
extrachill_share_button( array(
    'share_url'   => 'https://example.com/page',
    'share_title' => 'Custom Title',
) );
```

### Features

- Dropdown toggle on click
- Close on outside click
- Clipboard copy with "Copied!" feedback
- Fallback prompt for unsupported browsers
- External links open in new tab

## No Results Component

**Location**: `/inc/core/templates/no-results.php`

Displays when no content found:
- Search pages with zero results
- Empty archives
- 404 pages

### Features

- Contextual messaging
- Search form for new search
- Helpful suggestions

## 404 Error Component

**Location**: `/inc/core/templates/404.php`

Standard 404 error page template.

## Search Form Component

**Location**: `/inc/core/templates/searchform.php`
**Function**: `extrachill_search_form()`

Standard WordPress search form with theme styling.

### Usage

```php
// Display search form
extrachill_search_form();

// Or WordPress function
get_search_form();
```

## Community Activity Component

**Location**: `/inc/core/templates/community-activity.php`
**Functions**: `extrachill_get_community_activity_items()`, `extrachill_render_community_activity()`

Centralized shared helper library for displaying bbPress activity from multiple sites.

### Features

- **Community Queries**: Fetches activities from community.extrachill.com (blog ID 2)
- **Chronological Merging**: Combines activities from both sites into unified timeline
- **Configurable Rendering**: Customizable HTML structure and CSS classes
- **WordPress Object Cache**: 10-minute caching for optimal performance
- **Reusable Library**: Eliminates code duplication across sidebar and homepage widgets

### Data Function

**Function**: `extrachill_get_community_activity_items( $limit )`

**Parameters**:
- `$limit` (int) - Number of activity items to return from cached pool

**Returns**: Array of activity items with metadata:
- `id` - Post ID
- `type` - 'Topic' or 'Reply'
- `username` - Author username
- `user_profile_url` - Profile URL (via extrachill-users plugin)
- `topic_title` - Topic title
- `forum_title` - Forum title
- `date_time` - ISO 8601 datetime
- `forum_url` - Forum permalink
- `topic_url` - Topic permalink

### Render Function

**Function**: `extrachill_render_community_activity( $args )`

**Parameters**:
```php
$args = array(
    'limit'          => 5,                         // Number of items to display
    'wrapper_tag'    => 'div',                     // HTML tag for container
    'wrapper_class'  => 'community-activity-list', // Container CSS class
    'item_class'     => '',                        // Activity card CSS class
    'empty_class'    => '',                        // Empty state CSS class
    'render_wrapper' => true,                      // Whether to render container
    'counter_offset' => 0,                         // Topic ID counter offset
    'items'          => null,                      // Pre-fetched items (bypasses query)
);
```

### Usage Examples

```php
// Fetch and render with defaults
extrachill_render_community_activity();

// Custom styling for sidebar
$items = extrachill_get_community_activity_items( 5 );
extrachill_render_community_activity( array(
    'items'          => $items,
    'render_wrapper' => false,
    'item_class'     => 'sidebar-activity-card',
) );

// Custom styling for homepage grid
extrachill_render_community_activity( array(
    'limit'         => 9,
    'wrapper_class' => 'home-activity-grid',
    'item_class'    => 'grid-activity-card',
) );
```

### Implementation Locations

- **Sidebar Widget**: `/inc/sidebar/community-activity.php` - Calls shared helper with sidebar styling
- **Homepage 3x3 Grid**: `/inc/home/templates/section-3x3-grid.php` - Calls shared helper with grid styling
- **Legacy Wrapper**: `/inc/home/templates/community-activity.php` - Deprecated wrapper (v1.1.1+)

### Output Format

```html
<div class="community-activity-list">
    <div class="community-activity-card">
        <a href="/profile/username">Username</a> replied to
        <a href="/topic/slug/">Topic Title</a> in
        <a href="/forum/slug/">Forum Name</a> - 2 hours ago
    </div>
    <!-- More activities... -->
</div>
```

### Caching

Activities cached for 10 minutes using WordPress object cache with key `extrachill_community_activity_all`. Cache contains results from community site.

## Component Integration

All components designed for:
- **Modularity**: Use independently
- **Consistency**: Uniform output across theme
- **Flexibility**: Configurable via parameters/filters
- **Accessibility**: ARIA labels, semantic HTML
- **Performance**: Efficient database queries
