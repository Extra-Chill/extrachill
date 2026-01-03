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
- Optional archive actions area: `do_action( 'extrachill_archive_header_actions' )`
- Taxonomy description (first page only)
- Author bio (author archives, first page only)
- `extrachill_after_author_bio` action hook (passes `$author_id`)
### Filter Bar

**Component**: `/inc/components/filter-bar.php`, `/inc/components/filter-bar-defaults.php`
**Styles**: `/assets/css/filter-bar.css`
**Hook**: `extrachill_archive_above_posts`
**Function**: `extrachill_filter_bar()`

The theme uses a universal filter bar component for archives and search results.

**Item registration**:
- Core list: `apply_filters( 'extrachill_filter_bar_items', [] )`
- Override output: `apply_filters( 'extrachill_filter_bar_override', '' )`
- Extensibility: `do_action( 'extrachill_filter_bar_start' )`, `do_action( 'extrachill_filter_bar_end' )`

**Item types**:
- `dropdown`: renders a `<select>` and submits the form, or redirects when `redirect => true`
- `search`: renders the text input + submit button

**Default theme behavior** (see `/inc/components/filter-bar-defaults.php`):
- **Search results**: sort dropdown + search input only
- **Blog archive** (`extrachill_blog_archive` query var): category dropdown (redirect URLs)
- **Hierarchical terms**:
  - Category archives with children: child category dropdown (redirect URLs)
  - `location` taxonomy archives with children: child location dropdown (redirect URLs)
- **Artist dropdown**: only on categories `song-meanings` and `music-history`
- **Sort dropdown**: always present on archives
- **Search input**: always present and rendered last/right on archives

## Sorting System

**Location**: `/inc/archives/archive-custom-sorting.php`

Sorting is applied via `pre_get_posts` for the main query when:
- Not in admin
- `is_archive()` OR `get_query_var( 'extrachill_blog_archive' )`

Note: `/inc/archives/archive-custom-sorting.php` does not run for `is_search()`, even though search results use the same archive template.

### URL Parameters

| Parameter | Values | Effect |
|-----------|--------|--------|
| `sort` | `recent`, `oldest`, `random`, `popular` | Sort order for archives / blog archive |
| `artist` | artist-slug | Sets the `artist` query var (only matters where WordPress recognizes it) |
### Sort Dropdown

The theme default sort dropdown is registered by `extrachill_build_sort_dropdown()` (`/inc/components/filter-bar-defaults.php`):

```html
<select id="filter-bar-sort" name="sort">
    <option value="recent">Sort by Recent</option>
    <option value="oldest">Sort by Oldest</option>
    <option value="random">Sort by Random</option>
    <option value="popular">Sort by Popular</option>
</select>
```

### Sort Options

All four options available in a single dropdown for unified user experience:

- **recent**: Default WordPress ordering (newest first) - default selected option
- **oldest**: Chronological ordering (oldest first)
- **random**: Random post order using `orderby` rand
- **popular**: Sort by view count using `ec_post_views` meta key with `meta_value_num` orderby (descending order)

### Query Modification

`/inc/archives/archive-custom-sorting.php` hooks `pre_get_posts` and adjusts the main query according to `$_GET['sort']`.

- `oldest`: `orderby=date`, `order=ASC`
- `random`: `orderby=rand`
- `popular`: `meta_key=ec_post_views`, `orderby=meta_value_num`, `order=DESC`

It also reads `get_query_var( 'artist' )` and calls `$query->set( 'artist', $artist )` when present.

`artist` is a registered custom taxonomy (`/inc/core/custom-taxonomies.php`) with `query_var => true` and `rewrite => [ 'slug' => 'artist' ]`.
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

- The filter bar uses a `GET` form, so selected values are naturally preserved in the URL.
- Redirect-mode dropdowns navigate directly to a term URL (and do not preserve query args unless the option URL includes them).

Pagination query-arg preservation depends on the pagination implementation (see `extrachill_pagination()`).
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

Notes:
- `artist` is a taxonomy archive (`/artist/{slug}/`).
- The filter-bar `?artist={slug}` dropdown is only shown on the `song-meanings` and `music-history` category archives.

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
- URL parameter preservation (depends on `extrachill_pagination()`)
- Page number links

## Using Archive Features

**Sort Archives**:
```
/category-slug/?sort=oldest
/category-slug/?sort=random
/category-slug/?sort=popular
/artist/artist-name/?sort=recent (default)
```

**Artist taxonomy archives**:
```
/artist/artist-slug/
```

**Filter by Artist (only where the theme shows the dropdown)**:
```
/song-meanings/?artist=artist-slug
/music-history/?artist=artist-slug
```

**Combine Parameters (same constraint)**:
```
/song-meanings/?artist=artist-slug&sort=popular
/music-history/?artist=artist-slug&sort=oldest
```

## Extending Archive UI

```php
// Add custom markup inside the filter bar
add_action( 'extrachill_filter_bar_end', function () {
	if ( is_tax( 'venue' ) ) {
		$term = get_queried_object();
		echo '<div class="venue-nav">';
		echo '<a href="/venues/' . esc_attr( $term->slug ) . '/events/" class="button-2">View Events</a>';
		echo '</div>';
	}
} );

// Add custom actions next to the archive title
add_action( 'extrachill_archive_header_actions', function () {
	// Output buttons/links/etc.
} );

// Add content after author bio (author archives, first page only)
add_action( 'extrachill_after_author_bio', function ( $author_id ) {
	// Output author-specific UI.
}, 10, 1 );
```


## Performance Considerations

- Artist filtering queries only posts in current category
- `hide_empty` prevents displaying empty terms
- Query modifications run via `pre_get_posts` (WordPress best practice)
- URL parameters preserved for pagination/filtering compatibility (depends on pagination implementation)
- Popular sorting uses the `ec_post_views` post meta key (`meta_value_num`)
