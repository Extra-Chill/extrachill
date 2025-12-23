# Archive Functionality

Archive pages with filtering, sorting, and search capabilities.

## Core Components

### Archive Template

**Location**: `/inc/archives/archive.php`
**Filter**: `extrachill_template_archive`

Handles all archive types:
- Category archives
- Tag archives
- Taxonomy archives (location, festival, artist, venue)
- Author archives
- Date archives

### Archive Header

**Location**: `/inc/archives/archive-header.php`
**Hook**: `extrachill_archive_header`

Displays:
- Archive title (plain text, not wrapped in links)
- Archive description (taxonomy descriptions)
- Author bio (on author archives, first page only)
- `extrachill_after_author_bio` action hook (passes $author_id parameter)

### Filter Bar

**Component**: `/inc/components/filter-bar.php`, `/inc/components/filter-bar-defaults.php`
**Styles**: `/assets/css/filter-bar.css`
**Hook**: `extrachill_archive_above_posts`
**Function**: `extrachill_filter_bar()`

Uses the universal filter bar component system to provide:
- **Sort Dropdown**: 4-option sorting (recent, oldest, random, popular by view count)
- Child term dropdown filtering (categories with children + location children)
- Artist filtering (Song Meanings + Music History categories)
- Search input (`s`)

The filter bar component is reusable across the platform - extended by the extrachill-community plugin for forum filtering via `inc/core/filter-bar.php`.

## Sorting System

**Location**: `/inc/archives/archive-custom-sorting.php`

### URL Parameters

| Parameter | Values | Effect |
|-----------|--------|--------|
| `sort` | `recent`, `oldest`, `random`, `popular` | Sort order |
| `artist` | artist-slug | Filter by artist taxonomy (Song Meanings + Music History)

### Sort Dropdown

```html
<select id="post-sorting" name="post_sorting">
    <option value="recent">Sort by Recent</option>
    <option value="oldest">Sort by Oldest</option>
    <option value="random">Sort by Random</option>
    <option value="popular">Sort by Most Popular</option>
</select>
```

**Persistence**: Selected value persists via JavaScript reading URL parameters

### Sort Options

All four options available in a single dropdown for unified user experience:

- **recent**: Default WordPress ordering (newest first) - default selected option
- **oldest**: Chronological ordering (oldest first)
- **random**: Random post order using `orderby` rand
- **popular**: Sort by view count using `ec_post_views` meta key with `meta_value_num` orderby (descending order)

### Query Modification

```php
function extrachill_sort_posts( $query ) {
    if ( is_admin() || ! $query->is_main_query() ) {
        return;
    }

    if ( ! is_archive() && ! get_query_var( 'extrachill_blog_archive' ) ) {
        return;
    }

    $sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : '';

    switch ( $sort ) {
        case 'oldest':
            $query->set( 'orderby', 'date' );
            $query->set( 'order', 'ASC' );
            break;
        case 'random':
            $query->set( 'orderby', 'rand' );
            break;
        case 'popular':
            $query->set( 'meta_key', 'ec_post_views' );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'order', 'DESC' );
            break;
        default:
            break;
    }

    $artist = get_query_var( 'artist' );
    if ( ! empty( $artist ) ) {
        $query->set( 'artist', $artist );
    }
}
add_action( 'pre_get_posts', 'extrachill_sort_posts' );
```

## Artist Filtering

**Categories**: Song Meanings, Music History

### Artist Filter Dropdown

**Implemented in**: `/inc/components/filter-bar-defaults.php` (via `extrachill_filter_bar_items`)

- Dropdown `name`: `artist`
- Query var: `artist`
- Only added on `is_category( 'song-meanings' )` and `is_category( 'music-history' )`
- Options constrained to artist terms attached to posts in the current category

## Child Terms Dropdown

**Implemented in**: `/inc/components/filter-bar-defaults.php` (via `extrachill_filter_bar_items`)

Displays child categories/terms for hierarchical taxonomies:
- Category archives: child categories
- Location taxonomy archives: child locations

## URL Preservation

Sorting and filtering preserve existing URL parameters:

```php
$links_html = paginate_links(array(
    'base' => $base_url,
    'format' => $format,
    'add_args' => $_GET  // Preserve query parameters
));
```

## Blog Archive Functionality

**Query Var**: `extrachill_blog_archive`
**Purpose**: Provides blog archive functionality without requiring custom page templates (replaces legacy /all/ template)
**CSS Loading**: Archive styles load when `get_query_var('extrachill_blog_archive')` returns true
**Template**: Uses standard archive template with query modifications

## Archive Types

### Category Archives

URL: `/category-slug/`
Template: Archive template via router
Breadcrumb: Home › Parent Category › Current Category

### Tag Archives

URL: `/tag/tag-slug/`
Template: Archive template via router
Breadcrumb: Home › Current Tag

### Taxonomy Archives

URLs:
- `/location/location-slug/`
- `/festival/festival-slug/`
- `/artist/artist-slug/`
- `/venue/venue-slug/`

Breadcrumb: Home › Taxonomy Name › Current Term

### Author Archives

URL: `/author/username/`
Template: Archive template via router

### Date Archives

URLs:
- `/2024/` (year)
- `/2024/01/` (month)
- `/2024/01/15/` (day)

Template: Archive template via router

## Post Cards

**Location**: `/inc/archives/post-card.php`
**Usage**: Displays individual post cards in archive loops

## Filter Bar Hooks

```php
// Filter bar displays automatically via extrachill_archive_above_posts
// (theme hooks extrachill_filter_bar() to this action)
do_action( 'extrachill_archive_above_posts' );
```

## Pagination

Archive pages use theme pagination:

```php
extrachill_pagination();
```

**Features**:
- Post count display
- Previous/Next navigation
- URL parameter preservation
- Page number links

## Using Archive Features

**Sort Archives**:
```
/category-slug/?sort=oldest
/category-slug/?sort=random
/category-slug/?sort=popular
/artist/artist-name/?sort=recent (default)
```

**Filter by Artist**:
```
/song-meanings/?artist=artist-slug
```

**Combine Parameters**:
```
/category-slug/?artist=artist-slug&sort=popular
/song-meanings/?artist=artist-slug&sort=oldest
```

## Plugin Integration

```php
// Add custom markup inside the filter bar
add_action( 'extrachill_filter_bar_end', function() {
    if ( is_tax( 'venue' ) ) {
        $term = get_queried_object();
        echo '<div class="venue-nav">';
        echo '<a href="/venues/' . esc_attr( $term->slug ) . '/events/" class="button-2">View Events</a>';
        echo '</div>';
    }
} );

// Add content after author bio
add_action( 'extrachill_after_author_bio', function( $author_id ) {
    echo '<div class="author-social-links">';
    echo '</div>';
}, 10, 1 );

// Modify query
add_action( 'pre_get_posts', function( $query ) {
    if ( $query->is_main_query() && is_category( 'special' ) ) {
        $query->set( 'posts_per_page', 20 );
    }
} );
```


## Performance Considerations

- Artist filtering queries only posts in current category
- `hide_empty` prevents displaying empty terms
- Query modifications run via `pre_get_posts` (WordPress best practice)
- URL parameters preserved for pagination/filtering compatibility
- Popular sorting uses `ec_post_views` meta key with `meta_value_num` orderby
- Artist profile queries use `switch_to_blog()` with proper restoration in `try/finally` block
