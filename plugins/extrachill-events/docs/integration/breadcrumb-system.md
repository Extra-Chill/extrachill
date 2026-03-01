# Breadcrumb System

The breadcrumb system provides custom navigation breadcrumbs for events.extrachill.com with "Extra Chill → Events" hierarchy.

## Five Core Functions

### 1. ec_events_breadcrumb_root()
Customizes root breadcrumb link based on page context.

**Filter Hook:**
```
extrachill_breadcrumbs_root
```

**Output:**
- **Homepage**: `<a href="https://extrachill.com">Extra Chill</a>`
- **Other Pages**: `<a href="https://extrachill.com">Extra Chill</a> › <a href="[site-url]">Events</a>`

**Blog ID Check:**
Only applies when `get_current_blog_id() === ec_get_blog_id('events')` (events.extrachill.com)

### 2. ec_events_breadcrumb_trail_homepage()
Overrides breadcrumb trail for homepage.

**Filter Hook:**
```
extrachill_breadcrumbs_override_trail
```

**Output:**
```html
<span>Events</span>
```

**Full Breadcrumb (with root):**
```
Extra Chill › Events
```

**Blog ID Check:**
Only applies when `get_current_blog_id() === ec_get_blog_id('events')` AND `is_front_page()`

### 3. ec_events_breadcrumb_trail_archives()
Overrides breadcrumb trail for archive pages.

**Filter Hook:**
```
extrachill_breadcrumbs_override_trail
```

**Output Patterns:**

**Taxonomy Archives:**
```html
<span>[Term Name]</span>
```
Full breadcrumb: `Extra Chill › Events › [Term Name]`

**Post Type Archive:**
```html
<span>Events</span>
```
Full breadcrumb: `Extra Chill › Events`

**Blog ID Check:**
Only applies when `get_current_blog_id() === ec_get_blog_id('events')`

### 4. ec_events_breadcrumb_trail_single()
Overrides breadcrumb trail for single event posts.

**Filter Hook:**
```
extrachill_breadcrumbs_override_trail
```

**Output:**
```html
<span class="breadcrumb-title">[Event Title]</span>
```

**Full Breadcrumb:**
```
Extra Chill › Events › [Event Title]
```

**Blog ID Check:**
Only applies when `get_current_blog_id() === ec_get_blog_id('events')` AND `is_singular('data_machine_events')`

### 5. ec_events_back_to_home_label()
Modifies back-to-home link label.

**Filter Hook:**
```
extrachill_back_to_home_label
```

**Output:**
- **Event Pages**: `← Back to Events`
- **Homepage**: Default theme label (`← Back to Extra Chill`)

**Blog ID Check:**
Only applies when `get_current_blog_id() === ec_get_blog_id('events')` AND NOT `is_front_page()`

## Breadcrumb Examples

### Homepage
```
Extra Chill › Events
```

**HTML Output:**
```html
<nav class="breadcrumbs">
    <a href="https://extrachill.com">Extra Chill</a> › 
    <span>Events</span>
</nav>
```

### Taxonomy Archive (Festival: Bonnaroo)
```
Extra Chill › Events › Bonnaroo
```

**HTML Output:**
```html
<nav class="breadcrumbs">
    <a href="https://extrachill.com">Extra Chill</a> › 
    <a href="https://events.extrachill.com">Events</a> › 
    <span>Bonnaroo</span>
</nav>
```

### Single Event
```
Extra Chill › Events › Pearl Jam at Wrigley Field
```

**HTML Output:**
```html
<nav class="breadcrumbs">
    <a href="https://extrachill.com">Extra Chill</a> › 
    <a href="https://events.extrachill.com">Events</a> › 
    <span class="breadcrumb-title">Pearl Jam at Wrigley Field</span>
</nav>
```

## Filter Integration

### extrachill_breadcrumbs_root

**Purpose:** Customize root breadcrumb link

**Parameters:**
- `$root_link` (string): Default root breadcrumb HTML from theme

**Return Value:**
Modified root link HTML with events context

**Function:**
```php
function ec_events_breadcrumb_root( $root_link ) {
    if ( get_current_blog_id() !== 7 ) {
        return $root_link;
    }
    
    if ( is_front_page() ) {
        return '<a href="https://extrachill.com">Extra Chill</a>';
    }
    
    return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url( home_url() ) . '">Events</a>';
}
add_filter( 'extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root' );
```

### extrachill_breadcrumbs_override_trail

**Purpose:** Customize breadcrumb trail based on page context

**Parameters:**
- `$custom_trail` (string|false): Existing custom trail from other filters

**Return Value:**
Custom trail HTML for specific page types, unchanged otherwise

**Functions:**
Three functions hook into this filter:
1. `ec_events_breadcrumb_trail_homepage()` - Homepage trail
2. `ec_events_breadcrumb_trail_archives()` - Archive trail
3. `ec_events_breadcrumb_trail_single()` - Single event trail

**Priority:** All use default priority (10)

### extrachill_back_to_home_label

**Purpose:** Modify back-to-home link text

**Parameters:**
- `$label` (string): Default back-to-home link label from theme
- `$url` (string): Back-to-home link URL

**Return Value:**
Modified label for event pages, unchanged for homepage

**Function:**
```php
function ec_events_back_to_home_label( $label, $url ) {
    if ( get_current_blog_id() !== 7 ) {
        return $label;
    }
    
    if ( is_front_page() ) {
        return $label;
    }
    
    return '← Back to Events';
}
add_filter( 'extrachill_back_to_home_label', 'ec_events_back_to_home_label', 10, 2 );
```

## Navigation Flow

### From Main Site to Events
1. User on extrachill.com
2. Clicks "Events" navigation link
3. Arrives at events.extrachill.com homepage
4. Breadcrumb shows: `Extra Chill › Events`

### From Events to Taxonomy
1. User on events.extrachill.com homepage
2. Clicks festival badge or filter
3. Arrives at taxonomy archive (e.g., `/festival/bonnaroo/`)
4. Breadcrumb shows: `Extra Chill › Events › Bonnaroo`

### From Taxonomy to Single Event
1. User on taxonomy archive
2. Clicks event title
3. Arrives at single event page
4. Breadcrumb shows: `Extra Chill › Events › [Event Title]`

### Back Navigation
- **From Single Event**: "← Back to Events" → Returns to homepage
- **From Taxonomy Archive**: "← Back to Events" → Returns to homepage
- **From Homepage**: "← Back to Extra Chill" → Returns to main site

## Theme Requirements

The ExtraChill theme must provide:

### Breadcrumb Function
```php
function extrachill_breadcrumbs()
```

This function must:
- Render breadcrumb navigation
- Support `extrachill_breadcrumbs_root` filter
- Support `extrachill_breadcrumbs_override_trail` filter
- Support `extrachill_back_to_home_label` filter

### CSS Classes
```css
.breadcrumbs {
    /* Breadcrumb container styling */
}

.breadcrumb-title {
    /* Current page title styling */
}
```

## Blog ID Targeting

All breadcrumb functions check for blog ID 7:
```php
if ( get_current_blog_id() !== 7 ) {
    return $default_value;
}
```

This ensures breadcrumb customizations only apply to events.extrachill.com.
