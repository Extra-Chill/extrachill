# Search System

Multisite search functionality with cross-site result integration.

## Search Architecture

**Theme search template**: `inc/archives/archive.php` (search routes here by default via `extrachill_template_search`)
**Search header**: `inc/archives/archive-header.php` (the same template used for archives; includes an `is_search()` title case)
**Search styling**: `assets/css/search.css` (loaded when `is_search()` is true)

The `extrachill-search` plugin can override the template by returning its own path from `extrachill_template_search`.

## Search Components

### Search Form

**Location**: `/inc/core/templates/searchform.php`
**Function**: `extrachill_search_form()`

Used in:
- Header search overlay (`inc/header/header-search.php`)
- Search pages
- No results templates

### Search Header

**Location**: `inc/archives/archive-header.php`
**Hook**: `extrachill_search_header`
**Default handler**: `extrachill_default_search_header()` in `inc/core/actions.php` (includes `archive-header.php`)

Displays:
- Search query (via the `is_search()` title case)
- Optional actions area via `extrachill_archive_header_actions`

### Site Badges

Rendered by the extrachill-search plugin when multisite results are available to identify each network source.

## Multisite Search

Multisite search behavior is owned by the `extrachill-search` plugin.

- The theme exposes `extrachill_template_search` so the plugin can swap in its own template.
- The theme will also render cross-site taxonomies correctly for results that include an `_origin_site_id` property (see `inc/core/templates/taxonomy-badges.php`).

## Search Result Display

Search uses the archive template loop (`inc/archives/archive.php`) and renders:

- `do_action( 'extrachill_search_header' )` (defaults to `archive-header.php`)
- `do_action( 'extrachill_archive_above_posts' )` (filter bar hooks typically live here)
- `get_template_part( 'inc/archives/post-card' )` to render each result

Multisite/forum results formatting is primarily handled by the `extrachill-search` plugin (the theme just renders the post objects it is given).

## Search URL Structure

```
/?s=search+term
```

**Query Variable**: `s` (WordPress standard)

## Search Assets

**CSS**: `assets/css/search.css`
**Loading**: `extrachill_enqueue_search_styles()` in `inc/core/assets.php`
**Condition**: `is_search()`

## Theme Responsibilities

The theme:
- provides the archive/search header UI (`inc/archives/archive-header.php` via `extrachill_search_header`)
- provides base templates and hooks (`extrachill_template_search`, `extrachill_archive_header_actions`)
- renders the post objects it receives (local WP query or plugin-provided results)

## Multisite Search Details

Implementation details for cross-site searching live in `extrachill-search`. This theme intentionally does not duplicate that logic.

## Search Result Metadata

### Standard Post Results

- Title
- Excerpt
- Author
- Date
- Categories/Tags
- Featured image
- View count

### Forum Post Results

- Topic/reply title
- Author (standard author metadata)
- Post date
- Contextual excerpt
- Permalink
- Site badge (identifies as community result)

## No Results Handling

**Template**: `/inc/core/templates/no-results.php`

Displays when search returns zero results:
- "No results found" message
- Search form for a new search

## Search Pagination

Search results use standard pagination:

```php
extrachill_pagination();
```

**Features**:
- Result count: "Viewing results 1-10 of 156"
- Previous/Next navigation
- Page numbers
- Search query preservation in URLs

## Plugin Integration

### Override Search Template

```php
add_filter( 'extrachill_template_search', function( $template ) {
    return MY_PLUGIN_DIR . '/templates/custom-search.php';
} );
```

### Modify Search Query

```php
add_action( 'pre_get_posts', function( $query ) {
    if ( $query->is_search() && $query->is_main_query() ) {
        $query->set( 'posts_per_page', 20 );
        $query->set( 'post_type', array( 'post', 'page' ) );
    }
} );
```

### Add Search Filter

```php
add_action( 'extrachill_search_header', function() {
    echo '<div class="search-filter">...</div>';
}, 15 );
```

## Graceful Degradation

Theme search works normally without `extrachill-search`.

- Without plugin: WordPress main query provides local search results.
- With plugin: plugin can override `extrachill_template_search` (and/or populate the main query) to provide multisite results.

## Search Form Accessibility

Search form markup uses standard WordPress form semantics with explicit labels/ARIA attributes.
## Performance

- Plugin-level optimization via extrachill-search
- Direct database queries with `switch_to_blog()`
- WordPress core multisite caching (blog ID mapping is centralized via `ec_get_blog_id()`)
- Efficient cross-site query patterns
- Pagination limits result sets
- Conditional asset loading (`search.css` only on search pages)
