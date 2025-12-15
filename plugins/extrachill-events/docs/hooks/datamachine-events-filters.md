# datamachine-events Filters

WordPress filters the plugin hooks into from the datamachine-events plugin.

## Badge Filters

### datamachine_events_badge_wrapper_classes

**Purpose:** Add theme-compatible wrapper class to badge container

**Parameters:**
- `$wrapper_classes` (array): Default wrapper classes from datamachine-events
- `$post_id` (int): Event post ID

**Return Value:**
Enhanced wrapper classes with theme compatibility (`taxonomy-badges` added)

**Usage:**
```php
add_filter('datamachine_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);

public function add_wrapper_classes($wrapper_classes, $post_id) {
    $wrapper_classes[] = 'taxonomy-badges';
    return $wrapper_classes;
}
```

**When Fired:**
datamachine-events taxonomy badge renderer when building wrapper div classes

**Result:**
```html
<div class="datamachine-taxonomy-badges taxonomy-badges">
```

---

### datamachine_events_badge_classes

**Purpose:** Map festival/location taxonomies to theme badge classes

**Parameters:**
- `$badge_classes` (array): Default badge classes from datamachine-events
- `$taxonomy_slug` (string): Taxonomy name (festival, venue, location, etc.)
- `$term` (WP_Term): Term object
- `$post_id` (int): Event post ID

**Return Value:**
Enhanced badge classes with taxonomy-specific styling

**Usage:**
```php
add_filter('datamachine_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);

public function add_badge_classes($badge_classes, $taxonomy_slug, $term, $post_id) {
    $badge_classes[] = 'taxonomy-badge';
    
    switch ($taxonomy_slug) {
        case 'festival':
            $badge_classes[] = 'festival-badge';
            $badge_classes[] = 'festival-' . esc_attr($term->slug);
            break;
            
        case 'location':
            $badge_classes[] = 'location-badge';
            $badge_classes[] = 'location-' . esc_attr($term->slug);
            break;
    }
    
    return $badge_classes;
}
```

**When Fired:**
datamachine-events taxonomy badge renderer when building individual badge classes

**Result:**
```html
<span class="datamachine-taxonomy-badge taxonomy-badge festival-badge festival-bonnaroo">
```

---

### datamachine_events_excluded_taxonomies

**Purpose:** Control taxonomy visibility for badges vs filter modal

This integration uses the `$context` parameter (passed by datamachine-events as `'badge'` or `'modal'`) to keep the calendar UI focused:
- **Badge context (`'badge'`)**: exclude `artist` taxonomy from event badges.
- **Modal context (`'modal'`)**: exclude everything except `location`, so the taxonomy filter modal only shows location.

**Parameters:**
- `$excluded` (array): Array of taxonomy slugs to exclude
- `$context` (string): Context identifier: `'badge'` or `'modal'`

**Return Value:**
Enhanced exclusion array scoped by context

**Usage:**
```php
add_filter('datamachine_events_excluded_taxonomies', array($this, 'exclude_taxonomies'), 10, 2);

public function exclude_taxonomies($excluded, $context = '') {
    $excluded[] = 'artist';

    if ($context !== 'modal') {
        return array_values(array_unique($excluded));
    }

    $taxonomies = get_object_taxonomies(\DataMachineEvents\Core\Event_Post_Type::POST_TYPE, 'names');
    foreach ($taxonomies as $taxonomy_slug) {
        if ($taxonomy_slug === 'location') {
            continue;
        }

        $excluded[] = $taxonomy_slug;
    }

    return array_values(array_unique($excluded));
}
```

**When Fired:**
- datamachine-events badge renderer (context: `'badge'`)
- datamachine-events filter modal / filters endpoint (context: `'modal'`)

## Button Filters

### datamachine_events_modal_button_classes

**Purpose:** Add theme button classes to modal buttons

**Parameters:**
- `$classes` (array): Default button classes from datamachine-events
- `$button_type` (string): Button type ('primary' or 'secondary')

**Return Value:**
Enhanced button classes with theme styling

**Usage:**
```php
add_filter('datamachine_events_modal_button_classes', array($this, 'add_modal_button_classes'), 10, 2);

public function add_modal_button_classes($classes, $button_type) {
    switch ($button_type) {
        case 'primary':
            $classes[] = 'button-1';
            $classes[] = 'button-large';
            break;
        case 'secondary':
            $classes[] = 'button-3';
            $classes[] = 'button-medium';
            break;
    }
    return $classes;
}
```

**When Fired:**
datamachine-events modal renderer when building button HTML

**Result:**
```html
<button class="button button-primary button-1 button-large">Confirm</button>
<button class="button button-secondary button-3 button-medium">Cancel</button>
```

---

### datamachine_events_ticket_button_classes

**Purpose:** Add theme button classes to ticket purchase button

**Parameters:**
- `$classes` (array): Default button classes from datamachine-events

**Return Value:**
Enhanced button classes with theme styling

**Usage:**
```php
add_filter('datamachine_events_ticket_button_classes', array($this, 'add_ticket_button_classes'), 10, 1);

public function add_ticket_button_classes($classes) {
    $classes[] = 'button-1';
    $classes[] = 'button-large';
    return $classes;
}
```

**When Fired:**
datamachine-events single event template when rendering ticket button

**Result:**
```html
<a href="[ticket-url]" class="datamachine-ticket-button button-1 button-large">Buy Tickets</a>
```

## Breadcrumb Filter

### datamachine_events_breadcrumbs

**Purpose:** Override datamachine-events breadcrumbs with theme breadcrumb system

**Parameters:**
- `$breadcrumbs` (string|null): Plugin's default breadcrumb HTML
- `$post_id` (int): Event post ID

**Return Value:**
Theme breadcrumb HTML

**Usage:**
```php
add_filter('datamachine_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);

public function override_breadcrumbs($breadcrumbs, $post_id) {
    ob_start();
    extrachill_breadcrumbs();
    return ob_get_clean();
}
```

**When Fired:**
datamachine-events single event template when rendering breadcrumbs

**Result:**
Replaces plugin breadcrumbs with theme's `extrachill_breadcrumbs()` output for consistent styling

## Action Hook

### datamachine_events_action_buttons

**Purpose:** Render share button in event action buttons container

**Parameters:**
- `$post_id` (int): Event post ID
- `$ticket_url` (string): Ticket URL (may be empty)

**Usage:**
```php
add_action('datamachine_events_action_buttons', array($this, 'render_share_button'), 10, 2);

public function render_share_button($post_id, $ticket_url) {
    if (get_current_blog_id() !== 7) {
        return;
    }
    
    extrachill_share_button(array(
        'share_url' => get_permalink($post_id),
        'share_title' => get_the_title($post_id),
        'button_size' => 'button-large'
    ));
}
```

**When Fired:**
datamachine-events single event template inside action buttons container, after ticket button

**Result:**
```html
<div class="event-action-buttons">
    <a href="[ticket-url]" class="button-1 button-large">Buy Tickets</a>
    <!-- Share button rendered here by this action -->
</div>
```

## datamachine-events Plugin Requirements

For all filters and actions to work, the datamachine-events plugin must:

1. **Provide badge filters:**
   - `datamachine_events_badge_wrapper_classes`
   - `datamachine_events_badge_classes`
   - `datamachine_events_excluded_taxonomies`

2. **Provide button filters:**
   - `datamachine_events_modal_button_classes`
   - `datamachine_events_ticket_button_classes`

3. **Provide breadcrumb filter:**
   - `datamachine_events_breadcrumbs`

4. **Provide action hook:**
   - `datamachine_events_action_buttons`

5. **Provide classes for detection:**
   - `DataMachineEvents\Core\Taxonomy_Badges` - Badge rendering class
   - `DataMachineEvents\Core\Breadcrumbs` - Breadcrumb rendering class

## Conditional Loading

The plugin only hooks into badge and breadcrumb filters if the corresponding classes exist:

### Badge Integration
```php
if (class_exists('DataMachineEvents\Core\Taxonomy_Badges')) {
    add_filter('datamachine_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
    add_filter('datamachine_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
    add_filter('datamachine_events_excluded_taxonomies', array($this, 'exclude_venue_taxonomy'));
}
```

### Breadcrumb Integration
```php
if (class_exists('DataMachineEvents\Core\Breadcrumbs')) {
    add_filter('datamachine_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);
}
```

This prevents errors if datamachine-events is deactivated or missing these classes.
