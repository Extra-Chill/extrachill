# Post Meta Hiding

The post meta hiding system removes theme's default post meta (author, date, categories) from datamachine_events post type.

## Why Hide Post Meta

### Event Meta Handled by datamachine-events
The datamachine-events plugin displays comprehensive event metadata:
- Event date and time
- Venue information (9 metadata fields)
- Location details
- Festival associations
- Artist information
- Ticket links

### Prevent Duplicate Display
Theme's default post meta (author, publish date, categories) is:
- **Redundant**: Event datetime displayed by datamachine-events
- **Irrelevant**: Author/publish date not meaningful for events
- **Cluttering**: Creates visual noise above event details

## Filter Integration

### extrachill_post_meta Filter

**Purpose:** Remove theme's post meta output for datamachine_events post type

**Filter Parameters:**
- `$default_meta` (string): Default post meta HTML from theme
- `$post_id` (int): Post ID
- `$post_type` (string): Post type

**Return Value:**
Empty string for `datamachine_events`, unchanged for other post types

**Implementation:**
```php
public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
    if ($post_type === 'datamachine_events') {
        return '';
    }
    return $default_meta;
}
add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);
```

## Theme Post Meta Function

The ExtraChill theme must provide:
```php
function extrachill_post_meta($post_id = null)
```

This function should:
- Render default post meta (author, date, categories, tags)
- Apply `extrachill_post_meta` filter before output
- Return filtered result

**Expected Implementation:**
```php
function extrachill_post_meta($post_id = null) {
    if (!$post_id) {
        $post_id = get_the_ID();
    }
    
    $post_type = get_post_type($post_id);
    
    // Build default meta HTML
    $meta_html = '<div class="post-meta">';
    $meta_html .= get_the_author_meta('display_name', get_post_field('post_author', $post_id));
    $meta_html .= ' • ' . get_the_date('', $post_id);
    $meta_html .= ' • ' . get_the_category_list(', ', '', $post_id);
    $meta_html .= '</div>';
    
    // Apply filter (allows plugins to modify or remove)
    return apply_filters('extrachill_post_meta', $meta_html, $post_id, $post_type);
}
```

## Example Output

### Standard Post
**Without Filter:**
```html
<div class="post-meta">
    By Chris Huber • June 15, 2024 • Music News, Concert Reviews
</div>
```

**With Filter (not datamachine_events):**
```html
<div class="post-meta">
    By Chris Huber • June 15, 2024 • Music News, Concert Reviews
</div>
```
(Unchanged for standard posts)

### Event Post (datamachine_events)
**Without Filter:**
```html
<div class="post-meta">
    By Admin • June 10, 2024 • Concerts
</div>
```

**With Filter:**
```html

```
(Empty string - no post meta displays)

## What Gets Hidden

### Author Information
- Author display name
- Author archive link
- Author avatar (if theme includes)

### Publish Date
- Post publish date
- Post modified date (if theme includes)

### Taxonomy Terms
- Categories
- Tags
- Custom taxonomies (if theme includes)

### Other Meta
- Comment count (if theme includes)
- Reading time (if theme includes)
- View count (if theme includes)

## Event Meta Display (Not Hidden)

The datamachine-events plugin displays its own metadata structure:

### Event Details
- Event date and time
- Venue name
- Venue address
- Venue city, state, ZIP
- Venue phone
- Venue website
- Location (taxonomy)
- Festival (taxonomy)

### Action Buttons
- Ticket purchase button
- Share button (via this plugin)

### Related Events
- Related by venue
- Related by location

This event-specific metadata remains visible and properly formatted.

## CSS Impact

### Before Filter
```html
<article class="datamachine-event-details">
    <header>
        <h1>Pearl Jam at Wrigley Field</h1>
        <div class="post-meta">By Admin • June 10, 2024 • Concerts</div>
    </header>
    <!-- Event details -->
</article>
```

### After Filter
```html
<article class="datamachine-event-details">
    <header>
        <h1>Pearl Jam at Wrigley Field</h1>
        <!-- No post-meta div -->
    </header>
    <!-- Event details -->
</article>
```

Cleaner header without redundant meta information.

## Post Type Targeting

### Only Affects datamachine_events
```php
if ($post_type === 'datamachine_events') {
    return '';
}
```

Other post types retain default post meta:
- Standard posts (`post`)
- Pages (`page`)
- Custom post types (unless filtered separately)

## Theme Requirements

The theme must:
1. Provide `extrachill_post_meta()` function
2. Apply `extrachill_post_meta` filter with three parameters:
   - `$default_meta` (string)
   - `$post_id` (int)
   - `$post_type` (string)
3. Return filtered result instead of default meta directly

## Why Use Filter Instead of CSS

### CSS Hiding Issues
```css
.datamachine-event-details .post-meta {
    display: none;
}
```

**Problems:**
- HTML still exists in DOM
- Screen readers still announce hidden content
- SEO crawlers may index hidden content
- Accessibility violation

### Filter Benefits
```php
return '';  // No HTML generated
```

**Benefits:**
- No DOM element created
- Screen readers ignore (nothing to announce)
- SEO crawlers see no duplicate meta
- Cleaner HTML output
- Better performance (less HTML parsing)
