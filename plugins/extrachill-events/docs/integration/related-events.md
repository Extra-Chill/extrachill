# Related Events Integration

The related events system displays upcoming events by venue and location taxonomies on single event pages.

## How It Works

The plugin filters the theme's `extrachill_display_related_posts()` function to show events at the same venue or in the same location.

### Display Order
1. **Related by Venue**: Shows upcoming events at the same venue
2. **Related by Location**: Shows upcoming events in the same location (different venues)

### Blog ID Targeting
Only applies when:
```php
get_current_blog_id() === ec_get_blog_id('events')  // events.extrachill.com
```

## Filter Integration

### 1. extrachill_related_posts_taxonomies

**Purpose:** Specify which taxonomies to use for related events

**Filter Parameters:**
- `$taxonomies` (array): Default taxonomies from theme
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Return Value:**
Array of taxonomy slugs: `array('venue', 'location')`

**Implementation:**
```php
public function filter_event_taxonomies($taxonomies, $post_id, $post_type) {
    if (get_current_blog_id() === ec_get_blog_id('events') && $post_type === 'data_machine_events') {
        return array('venue', 'location');
    }
    return $taxonomies;
}
add_filter('extrachill_related_posts_taxonomies', array($this, 'filter_event_taxonomies'), 10, 3);
```

### 2. extrachill_related_posts_allowed_taxonomies

**Purpose:** Whitelist location taxonomy for related events

**Filter Parameters:**
- `$allowed` (array): Default allowed taxonomies
- `$post_type` (string): Current post type

**Return Value:**
Array with location taxonomy added to whitelist

**Implementation:**
```php
public function allow_event_taxonomies($allowed, $post_type) {
    if ($post_type === 'data_machine_events') {
        return array_merge($allowed, array('location'));
    }
    return $allowed;
}
add_filter('extrachill_related_posts_allowed_taxonomies', array($this, 'allow_event_taxonomies'), 10, 2);
```

### 3. extrachill_related_posts_query_args

**Purpose:** Modify query to show only upcoming events

**Filter Parameters:**
- `$query_args` (array): Default query arguments
- `$taxonomy` (string): Current taxonomy being queried
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Query Modifications:**
- **Post Type**: `data_machine_events`
- **Meta Query**: Events with `_datamachine_event_datetime >= current_time('mysql')`
- **Order By**: `_datamachine_event_datetime` ascending (soonest first)

**Implementation:**
```php
public function filter_event_query_args($query_args, $taxonomy, $post_id, $post_type) {
    if (get_current_blog_id() !== 7 || $post_type !== 'data_machine_events') {
        return $query_args;
    }
    
    $query_args['post_type'] = 'data_machine_events';
    
    $query_args['meta_query'] = array(
        array(
            'key'     => '_datamachine_event_datetime',
            'value'   => current_time('mysql'),
            'compare' => '>=',
            'type'    => 'DATETIME',
        ),
    );
    
    $query_args['meta_key'] = '_datamachine_event_datetime';
    $query_args['orderby']  = 'meta_value';
    $query_args['order']    = 'ASC';
    
    return $query_args;
}
add_filter('extrachill_related_posts_query_args', array($this, 'filter_event_query_args'), 10, 4);
```

### 4. extrachill_related_posts_tax_query

**Purpose:** Exclude same venue when showing location-based related events

**Filter Parameters:**
- `$tax_query` (array): Tax query array
- `$taxonomy` (string): Current taxonomy being queried
- `$term_id` (int): Current term ID
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Logic:**
When displaying location-based related events, exclude events at the same venue to provide variety.

**Implementation:**
```php
public function exclude_venue_from_location($tax_query, $taxonomy, $term_id, $post_id, $post_type) {
    if (get_current_blog_id() !== 7 || $post_type !== 'data_machine_events' || $taxonomy !== 'location') {
        return $tax_query;
    }
    
    $venue_terms = get_the_terms($post_id, 'venue');
    if (!$venue_terms || is_wp_error($venue_terms)) {
        return $tax_query;
    }
    
    $venue_term_ids = wp_list_pluck($venue_terms, 'term_id');
    
    $tax_query[] = array(
        'taxonomy' => 'venue',
        'field'    => 'term_id',
        'terms'    => $venue_term_ids,
        'operator' => 'NOT IN',
    );
    
    return $tax_query;
}
add_filter('extrachill_related_posts_tax_query', array($this, 'exclude_venue_from_location'), 10, 5);
```

## Display Logic

### Related by Venue Section

**Heading:** "More Events at [Venue Name]"

**Query:**
- Taxonomy: `venue`
- Term: Current event's venue
- Filter: Upcoming events only (`event_datetime >= now`)
- Order: Soonest first (ascending by event_datetime)
- Excludes: Current event

**Example:**
Viewing "Pearl Jam at Wrigley Field" shows other upcoming events at Wrigley Field.

### Related by Location Section

**Heading:** "More Events in [Location Name]"

**Query:**
- Taxonomy: `location`
- Term: Current event's location
- Filter: Upcoming events only (`event_datetime >= now`)
- Order: Soonest first (ascending by event_datetime)
- Excludes: Current event AND same venue events

**Example:**
Viewing "Pearl Jam at Wrigley Field" shows upcoming events in Chicago at different venues.

## Why Exclude Same Venue from Location

### Problem Without Exclusion
If Wrigley Field has 5 upcoming events:
1. **Related by Venue** shows all 5 Wrigley Field events
2. **Related by Location** shows same 5 Wrigley Field events (duplicate)

### Solution With Exclusion
1. **Related by Venue** shows 5 Wrigley Field events
2. **Related by Location** shows events at other Chicago venues (variety)

### User Benefit
Users see:
- Events at the same venue
- Events at other venues in the same location
- Maximum variety in recommendations

## Theme Function Integration

The theme must provide:
```php
function extrachill_display_related_posts($taxonomy, $post_id)
```

This function:
- Queries related posts by taxonomy
- Displays related posts in consistent layout
- Applies filters for customization
- Handles "no results" state

### Function Usage in Template
```php
// Called by theme or plugin
extrachill_display_related_posts('venue', get_the_ID());
extrachill_display_related_posts('location', get_the_ID());
```

## Event Datetime Meta

### Meta Key
```
_datamachine_event_datetime
```

### Format
MySQL DATETIME format: `YYYY-MM-DD HH:MM:SS`

**Example:** `2024-06-15 19:00:00`

### Comparison
```php
$query_args['meta_query'] = array(
    array(
        'key'     => '_datamachine_event_datetime',
        'value'   => current_time('mysql'),  // Current datetime
        'compare' => '>=',                    // Greater than or equal to now
        'type'    => 'DATETIME',
    ),
);
```

Only events with `event_datetime >= now` display as related events (no past events).

## Display Order

### Sort Order
Events sort by `_datamachine_event_datetime` ascending (soonest first).

**Example Order:**
1. Tonight's event
2. Tomorrow's event
3. Next week's event
4. Next month's event

### Why Ascending Order
Users want to know about upcoming events chronologically, with soonest events appearing first.

## Example Output

### Single Event Page: "Pearl Jam at Wrigley Field"

**Event Details:**
- Venue: Wrigley Field
- Location: Chicago
- Date: June 15, 2024

**Related Events Display:**

#### Related by Venue
**Heading:** "More Events at Wrigley Field"

1. Cubs vs Cardinals - June 18, 2024
2. Billy Joel Concert - June 22, 2024
3. Cubs vs Brewers - June 25, 2024

#### Related by Location
**Heading:** "More Events in Chicago"

1. Lollapalooza at Grant Park - August 1, 2024
2. Phish at United Center - August 5, 2024
3. Dead & Company at Soldier Field - August 10, 2024

Notice how Wrigley Field events don't appear in "Related by Location" because they're already shown in "Related by Venue".
