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

**Location**: `/inc/archives/archive-filter-bar.php`
**Hook**: `extrachill_archive_above_posts`
**Function**: `extrachill_archive_filter_bar()`

Provides:
- **Sort Dropdown**: 4-option sorting (recent, oldest, random, popular by view count)
- Child term dropdown filtering
- Artist filtering (category-specific)
- `extrachill_archive_filter_bar` action hook for plugin integration (buttons appear on right side)

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

```php
function extrachill_artist_filter_dropdown() {
    $current_artist = get_query_var( 'artist' );
    $category_id    = get_queried_object_id();
    $archive_link   = get_category_link( $category_id );

    $artists = get_terms( array(
        'taxonomy'   => 'artist',
        'orderby'    => 'name',
        'order'      => 'ASC',
        'hide_empty' => true,
        'object_ids' => get_posts( array(
            'post_type'   => 'post',
            'post_status' => 'publish',
            'category'    => $category_id,
            'numberposts' => -1,
            'fields'      => 'ids',
        ) ),
    ) );

    if ( empty( $artists ) || is_wp_error( $artists ) ) {
        return;
    }

    echo '<div id="artist-filters">';
    echo '<select id="artist-filter-dropdown" onchange="window.location.href=this.value;">';

    $selected = empty( $current_artist ) ? ' selected' : '';
    echo '<option value="' . esc_url( $archive_link ) . '"' . $selected . '>All Artists</option>';

    foreach ( $artists as $artist ) {
        $artist_url = add_query_arg( 'artist', $artist->slug, $archive_link );
        $selected   = ( $artist->slug === $current_artist ) ? ' selected' : '';
        echo '<option value="' . esc_url( $artist_url ) . '"' . $selected . '>' . esc_html( $artist->name ) . '</option>';
    }

    echo '</select></div>';
}
```

**Display Logic**:
```php
if ( is_category( 'song-meanings' ) || is_category( 'music-history' ) ) {
    extrachill_artist_filter_dropdown();
}
```

## Child Terms Dropdown

**Location**: `/inc/archives/archive-child-terms-dropdown.php`
**Function**: `extrachill_child_terms_dropdown_html()`

Displays child categories/terms for hierarchical taxonomies.

## Artist Profile Integration

**Location**: `/inc/archives/artist-profile-link.php`

Connects artist taxonomy archives with artist profile pages on artist.extrachill.com.

### How It Works

1. **Query Function**: `extrachill_get_artist_profile_by_slug( $slug )`
   - Switches to blog ID 4 (artist.extrachill.com)
   - Queries for `artist_profile` post type matching artist slug
   - Returns array with `id` and `permalink` if found, false otherwise
   - Uses `try/finally` to ensure blog is restored

2. **Display Function**: `extrachill_display_artist_profile_button()`
   - Hooked to `extrachill_archive_filter_bar` action
   - Only runs on artist taxonomy archives (`is_tax( 'artist' )`)
   - Queries for matching artist profile
   - Displays "View Artist Profile" button if match found
   - Button styled with `button-2 button-medium` classes
   - Floats right in filter bar with `float: right` styling

### Example Output

```html
<div class="artist-profile-link-container" style="float: right; margin-left: 1em;">
    <a href="https://artist.extrachill.com/artist-profile/slug/"
       class="button-2 button-medium"
       rel="noopener">
        View Artist Profile
    </a>
</div>
```

### Integration Requirements

- Artist taxonomy term must exist on main site
- Matching artist profile must be published on artist.extrachill.com (blog ID 4)
- Artist profile slug must match artist taxonomy slug exactly

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
// Before filter bar
do_action( 'extrachill_archive_before_filter_bar' );

// Filter bar displays automatically via extrachill_archive_above_posts
extrachill_archive_filter_bar();

// After filter bar
do_action( 'extrachill_archive_after_filter_bar' );
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
/artist/artist-name/?sort=recent
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
// Add custom navigation button to filter bar
add_action( 'extrachill_archive_filter_bar', function() {
    if ( is_tax( 'venue' ) ) {
        $term = get_queried_object();
        echo '<div class="venue-nav" style="float: right; margin-left: 1em;">';
        echo '<a href="/venues/' . $term->slug . '/events/" class="button-2">View Events</a>';
        echo '</div>';
    }
} );

// Add content after author bio
add_action( 'extrachill_after_author_bio', function( $author_id ) {
    echo '<div class="author-social-links">';
    // Display author social media links
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
