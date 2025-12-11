# Archive Template Override

The archive template provides unified handling for all archive types (taxonomy, post type, date, author) by rendering the datamachine-events calendar block with automatic context-aware filtering.

## How It Works

The plugin uses the `extrachill_template_archive` filter to replace the theme's archive templates on events.extrachill.com (blog ID 7).

### Template Path
```
inc/templates/archive.php
```

### Blog ID Check
Only applies when:
```php
get_current_blog_id() === ec_get_blog_id('events')  // events.extrachill.com
```

## Archive Types Handled

### Taxonomy Archives
Festival, venue, and location taxonomy archive pages. The calendar block automatically filters events by the active taxonomy term.

**Examples:**
- `/festival/bonnaroo/` - Shows only Bonnaroo events
- `/venue/red-rocks/` - Shows only Red Rocks events
- `/location/charleston/` - Shows only Charleston events

### Post Type Archive
The `/events/` post type archive is **redirected** to the homepage (301 permanent redirect) for SEO consolidation. See SEO Redirect section below.

### Date Archives
Date-based event archives (if applicable to datamachine_events post type).

### Author Archives
Author-based event archives (if applicable to datamachine_events post type).

## Template Structure

### Elements Rendered
1. **Theme Header**: `get_header()` renders standard site header
2. **Breadcrumbs**: `extrachill_breadcrumbs()` displays navigation breadcrumbs
3. **Calendar Block**: `do_blocks('<!-- wp:datamachine-events/calendar /-->')` renders calendar
4. **Theme Footer**: `get_footer()` renders standard site footer

### HTML Output
```html
<!-- Theme header -->
<!-- Breadcrumbs: "Extra Chill › Events › [Term Name]" -->
<div class="events-calendar-container full-width-content">
    <!-- datamachine-events calendar block with automatic filtering -->
</div>
<!-- Theme footer -->
```

## Context-Aware Filtering

The datamachine-events calendar block automatically detects the archive context and filters events accordingly:

### Taxonomy Archive Detection
When viewing `/festival/bonnaroo/`, the calendar block:
1. Detects it's on a taxonomy archive page
2. Identifies the current term (Bonnaroo)
3. Filters events to show only those tagged with Bonnaroo
4. Displays filtered calendar view

### How It Works
The calendar block uses WordPress conditional tags:
```php
if ( is_tax('festival') ) {
    $term = get_queried_object();
    // Filter events by $term
}
```

No manual configuration needed - the block handles filtering automatically based on URL context.

## Calendar Block Rendering

### Function Used
```php
do_blocks('<!-- wp:datamachine-events/calendar /-->')
```

This programmatically renders the calendar block without requiring the block editor.

### Why This Approach
- **Consistent Display**: Same calendar block appears on all archive pages
- **Automatic Filtering**: Block detects context and filters appropriately
- **No Editor Required**: Template renders block programmatically
- **Full Block Features**: Pagination, filtering, and AJAX work automatically

## CSS Classes

### Container Class
```css
.events-calendar-container.full-width-content
```

Used for styling the archive calendar container. The `full-width-content` class removes sidebar constraints.

## Template Override Filter

### Filter Name
```
extrachill_template_archive
```

### Parameters
- `$template` (string): Default template path from theme

### Return Value
Plugin template path for blog ID 7, unchanged template path otherwise.

### Implementation
```php
function ec_events_override_archive_template( $template ) {
    if ( get_current_blog_id() === ec_get_blog_id('events') ) {
        return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/archive.php';
    }
    return $template;
}
add_filter( 'extrachill_template_archive', 'ec_events_override_archive_template' );
```

## SEO Redirect

### Post Type Archive Redirect
The `/events/` post type archive redirects to the homepage with a 301 permanent redirect.

### Why This Redirect
- **SEO Consolidation**: Prevents duplicate content between `/events/` and homepage
- **Link Equity**: Consolidates search engine ranking signals to single URL
- **Canonical URL**: Homepage serves as canonical events URL
- **User Experience**: Homepage already displays full event calendar

### Implementation
```php
function ec_events_redirect_post_type_archive() {
    if ( get_current_blog_id() !== 7 ) {
        return;
    }
    
    if ( is_post_type_archive( 'datamachine_events' ) ) {
        wp_redirect( home_url(), 301 );
        exit;
    }
}
add_action( 'template_redirect', 'ec_events_redirect_post_type_archive' );
```

### HTTP Status Code
**301 Permanent Redirect** signals to search engines that:
- `/events/` has permanently moved to homepage
- Transfer link equity to homepage
- Update search results to show homepage instead

## Breadcrumb Context

Breadcrumbs adjust based on archive type:

### Taxonomy Archives
```
Extra Chill › Events › [Term Name]
```

### Post Type Archive
```
Extra Chill › Events
```
(Note: This is redirected to homepage, so breadcrumb rarely displays)

See Breadcrumb System documentation for customization details.
