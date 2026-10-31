# Theme Filters

WordPress filters the plugin hooks into from the ExtraChill theme.

## Template Routing Hooks

### extrachill_homepage_content

**Purpose:** Render homepage content for the events site

**Type:** action

**Usage (conceptual):**
```php
add_action( 'extrachill_homepage_content', 'ec_events_render_homepage' );
```

**When Fired:**
When the theme renders the front page content container.

---

### extrachill_template_archive

**Purpose:** Override theme's archive template

**Parameters:**
- `$template` (string): Default template path from theme

**Return Value:**
Plugin template path for blog ID 7, unchanged otherwise

**Usage:**
```php
add_filter('extrachill_template_archive', 'ec_events_override_archive_template');

function ec_events_override_archive_template($template) {
    if (get_current_blog_id() === ec_get_blog_id('events')) {
        return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/archive.php';
    }
    return $template;
}
```

**When Fired:**
Theme's universal routing system when determining archive template (taxonomy, post type, date, author archives)

## Breadcrumb Filters

### extrachill_breadcrumbs_root

**Purpose:** Customize root breadcrumb link

**Parameters:**
- `$root_link` (string): Default root breadcrumb HTML from theme

**Return Value:**
Modified root link HTML with events context

**Usage:**
```php
add_filter('extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root');

function ec_events_breadcrumb_root($root_link) {
    if (get_current_blog_id() !== 7) {
        return $root_link;
    }
    
    if (is_front_page()) {
        return '<a href="https://extrachill.com">Extra Chill</a>';
    }
    
    return '<a href="https://extrachill.com">Extra Chill</a> › <a href="' . esc_url(home_url()) . '">Events</a>';
}
```

**When Fired:**
Theme's breadcrumb function when rendering root breadcrumb link

---

### extrachill_breadcrumbs_override_trail

**Purpose:** Customize breadcrumb trail based on page context

**Parameters:**
- `$custom_trail` (string|false): Existing custom trail from other filters

**Return Value:**
Custom trail HTML for specific page types, unchanged otherwise

**Usage:**
```php
add_filter('extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_single');

function ec_events_breadcrumb_trail_single($custom_trail) {
    if (get_current_blog_id() !== 7) {
        return $custom_trail;
    }
    
    if (is_singular('data_machine_events')) {
        return '<span class="breadcrumb-title">' . get_the_title() . '</span>';
    }
    
    return $custom_trail;
}
```

**When Fired:**
Theme's breadcrumb function when rendering breadcrumb trail

**Multiple Hooks:**
Three functions hook this filter:
1. `ec_events_breadcrumb_trail_homepage()` - Homepage trail
2. `ec_events_breadcrumb_trail_archives()` - Archive trail
3. `ec_events_breadcrumb_trail_single()` - Single event trail

---

### extrachill_back_to_home_label

**Purpose:** Modify back-to-home link text

**Parameters:**
- `$label` (string): Default back-to-home link label from theme
- `$url` (string): Back-to-home link URL

**Return Value:**
Modified label for event pages, unchanged for homepage

**Usage:**
```php
add_filter('extrachill_back_to_home_label', 'ec_events_back_to_home_label', 10, 2);

function ec_events_back_to_home_label($label, $url) {
    if (get_current_blog_id() !== 7) {
        return $label;
    }
    
    if (is_front_page()) {
        return $label;
    }
    
    return '← Back to Events';
}
```

**When Fired:**
Theme's breadcrumb function when rendering back-to-home link

## Post Meta Filter

### extrachill_post_meta

**Purpose:** Remove theme's post meta output for data_machine_events post type

**Parameters:**
- `$default_meta` (string): Default post meta HTML from theme
- `$post_id` (int): Post ID
- `$post_type` (string): Post type

**Return Value:**
Empty string for `data_machine_events`, unchanged for other post types

**Usage:**
```php
add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);

public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
    if ($post_type === 'data_machine_events') {
        return '';
    }
    return $default_meta;
}
```

**When Fired:**
Theme's post meta function before outputting post meta HTML

## Related Posts Filters

### extrachill_related_posts_taxonomies

**Purpose:** Specify which taxonomies to use for related events

**Parameters:**
- `$taxonomies` (array): Default taxonomies from theme
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Return Value:**
Array of taxonomy slugs: `array('venue', 'location')`

**Usage:**
```php
add_filter('extrachill_related_posts_taxonomies', array($this, 'filter_event_taxonomies'), 10, 3);

public function filter_event_taxonomies($taxonomies, $post_id, $post_type) {
    if (get_current_blog_id() === ec_get_blog_id('events') && $post_type === 'data_machine_events') {
        return array('venue', 'location');
    }
    return $taxonomies;
}
```

**When Fired:**
Theme's related posts function when determining which taxonomies to query

---

### extrachill_related_posts_allowed_taxonomies

**Purpose:** Whitelist location taxonomy for related events

**Parameters:**
- `$allowed` (array): Default allowed taxonomies
- `$post_type` (string): Current post type

**Return Value:**
Array with location taxonomy added to whitelist

**Usage:**
```php
add_filter('extrachill_related_posts_allowed_taxonomies', array($this, 'allow_event_taxonomies'), 10, 2);

public function allow_event_taxonomies($allowed, $post_type) {
    if ($post_type === 'data_machine_events') {
        return array_merge($allowed, array('location'));
    }
    return $allowed;
}
```

**When Fired:**
Theme's related posts function when validating allowed taxonomies

---

### extrachill_related_posts_query_args

**Purpose:** Modify query to show only upcoming events

**Parameters:**
- `$query_args` (array): Default query arguments
- `$taxonomy` (string): Current taxonomy being queried
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Return Value:**
Modified query args with event-specific filters (upcoming only, ordered by date)

**Usage:**
```php
add_filter('extrachill_related_posts_query_args', array($this, 'filter_event_query_args'), 10, 4);

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
```

**When Fired:**
Theme's related posts function when building WP_Query arguments

---

### extrachill_related_posts_tax_query

**Purpose:** Exclude same venue when showing location-based related events

**Parameters:**
- `$tax_query` (array): Tax query array
- `$taxonomy` (string): Current taxonomy being queried
- `$term_id` (int): Current term ID
- `$post_id` (int): Current event post ID
- `$post_type` (string): Current post type

**Return Value:**
Modified tax query with venue exclusion for location queries

**Usage:**
```php
add_filter('extrachill_related_posts_tax_query', array($this, 'exclude_venue_from_location'), 10, 5);

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
```

**When Fired:**
Theme's related posts function when building tax_query array for location-based queries

## Theme Requirements

For all filters to work, the ExtraChill theme must:

1. **Provide template routing hooks:**
   - `extrachill_homepage_content`
   - `extrachill_template_archive`

2. **Provide breadcrumb filters:**
   - `extrachill_breadcrumbs_root`
   - `extrachill_breadcrumbs_override_trail`
   - `extrachill_back_to_home_label`

3. **Provide post meta filter:**
   - `extrachill_post_meta`

4. **Provide related posts filters:**
   - `extrachill_related_posts_taxonomies`
   - `extrachill_related_posts_allowed_taxonomies`
   - `extrachill_related_posts_query_args`
   - `extrachill_related_posts_tax_query`

5. **Provide functions:**
   - `extrachill_breadcrumbs()` - Breadcrumb rendering
   - `extrachill_display_related_posts()` - Related posts display
   - `extrachill_share_button()` - Share button rendering
   - `extrachill_post_meta()` - Post meta rendering
