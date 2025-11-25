# Search System

Multisite search functionality with cross-site result integration.

## Search Template

**Location**: `/inc/archives/search/search.php`
**Filter**: `extrachill_template_search`

Dedicated search results template with multisite support.

## Search Components

### Search Form

**Location**: `/inc/core/templates/searchform.php`
**Function**: `extrachill_search_form()`

Used in:
- Navigation flyout menu
- Search pages
- No results pages (404, empty archives)

### Search Header

**Location**: `/inc/archives/search/search-header.php`
**Hook**: `extrachill_search_header`
**Function**: `extrachill_default_search_header()`

Displays:
- Search query
- Result counts
- Search form

### Site Badges

**Location**: `/inc/archives/search/search-site-badge.php`

Displays site identification for cross-site results (requires extrachill-multisite plugin).

## Multisite Search

**Required Plugin**: extrachill-search (network-activated)

### Cross-Site Search Function

```php
extrachill_multisite_search( $search_query )
```

**Parameters**:
- `$search_query` (string) - Search term

**Returns**: Array of search results from all network sites or specified sites

**Sites Searched**:
- extrachill.com (main site posts)
- community.extrachill.com (forum topics and replies)
- Additional network sites as configured

**Features**:
- Searches all public post types automatically
- Meta query support for advanced filtering (e.g., bbPress metadata)
- Cross-site date sorting and pagination
- Contextual excerpt generation
- Site identification for result badges

## Search Result Display

### Main Site Results

Standard WordPress post loop:

```php
if ( have_posts() ) :
    while ( have_posts() ) : the_post();
        // Display post card
    endwhile;
else :
    // No results message
endif;
```

### Forum Results

Forum posts returned from extrachill-search plugin with metadata for display.

**Post Properties**:
- Standard WordPress post object
- Excerpt for contextual snippets
- Author information
- Post date
- Permalink for topic/reply links

**Display Integration**: Theme formats forum results alongside main site results with site badges for identification

## Search URL Structure

```
/?s=search+term
```

**Query Variable**: `s` (WordPress standard)

## Search Assets

**CSS**: `/assets/css/search.css`
**Loading**: `extrachill_enqueue_search_styles()`
**Condition**: `is_search()`

## Using Search

### Basic Search

```php
// Get search query
$search_query = get_search_query();

// Check if search page
if ( is_search() ) {
    // Search-specific code
}
```

### Custom Search Query

```php
$args = array(
    's' => 'search term',
    'post_type' => 'post',
    'posts_per_page' => 20,
);
$search_query = new WP_Query( $args );
```

### Search Form Display

```php
// In templates
extrachill_search_form();

// Or use WordPress function
get_search_form();
```

## Multisite Search Details

### How It Works

1. User submits search on any network site
2. Theme calls `extrachill_multisite_search()` from extrachill-search plugin
3. Plugin uses `switch_to_blog()` to search each network site
4. Searches all public post types on each site (posts, bbPress topics/replies, etc.)
5. Results merged with cross-site date sorting
6. Plugin generates contextual excerpts
7. Theme displays combined results with site badges

### Site Badge Display

```php
// Display site identification badge
extrachill_search_site_badge( 'Community Forums' );
```

Used to identify which site results came from in multisite search.

### Performance

**Plugin-Level Optimization**:
- Direct database queries via `switch_to_blog()`
- WordPress native blog-id-cache for site resolution
- Efficient cross-site query patterns
- Automatic post type discovery

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
- Search form for new search
- Suggestions for better results

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

Theme functions with graceful fallback when extrachill-search plugin not active:

```php
if ( function_exists( 'extrachill_multisite_search' ) ) {
    $multisite_results = extrachill_multisite_search( $search_query );
} else {
    // Falls back to standard WordPress single-site search
}
```

**Without Plugin**: Single-site WordPress search works normally
**With Plugin**: Cross-site multisite search across all network sites

## Search Form Accessibility

- ARIA labels on search input
- Proper form semantics
- Keyboard navigation support
- Screen reader friendly
- Clear submit button

## Performance

- Plugin-level optimization via extrachill-search
- Direct database queries with `switch_to_blog()`
- WordPress native blog-id-cache for site resolution
- Efficient cross-site query patterns
- Pagination limits result sets
- Conditional asset loading (`search.css` only on search pages)
