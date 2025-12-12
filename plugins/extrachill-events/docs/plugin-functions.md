# Plugin Functions

Global functions provided by the ExtraChill Events plugin.

## extrachill_events()

**Purpose:** Get singleton instance of ExtraChillEvents class

**Parameters:** None

**Return Value:** ExtraChillEvents instance

**Usage:**
```php
$plugin = extrachill_events();
```

**Access Integrations:**
```php
$integrations = extrachill_events()->get_integrations();

if (isset($integrations['datamachine_events'])) {
    // datamachine-events integration is active
}
```

**Description:**
Convenience function providing global access to the plugin's singleton instance. Useful for accessing plugin properties and methods from outside the plugin.

---

## ec_events_render_homepage()

**Purpose:** Render homepage content for events.extrachill.com

**Hook:** `extrachill_homepage_content`

**Parameters:** None

**Return Value:** None (includes template file)

**Usage:**
```php
add_action('extrachill_homepage_content', 'ec_events_render_homepage');

function ec_events_render_homepage() {
    include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
```

**When Called:**
Theme's homepage content hook

**Template Rendered:**
`inc/templates/homepage.php` - Displays static page content with calendar block support

---

## ec_events_override_archive_template()

**Purpose:** Override archive template on events.extrachill.com

**Hook:** `extrachill_template_archive`

**Parameters:**
- `$template` (string): Default template path from theme

**Return Value:**
- Plugin template path for blog ID 7
- Unchanged template path otherwise

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

**When Called:**
Theme's universal routing system when determining archive template (taxonomy, post type, date, author archives)

**Template Rendered:**
`inc/templates/archive.php` - Displays calendar block with context-aware filtering

---

## ec_events_redirect_post_type_archive()

**Purpose:** Redirect /events/ post type archive to homepage for SEO consolidation

**Hook:** `template_redirect`

**Parameters:** None

**Return Value:** None (performs redirect or returns early)

**Usage:**
```php
add_action('template_redirect', 'ec_events_redirect_post_type_archive');

function ec_events_redirect_post_type_archive() {
    if (get_current_blog_id() !== 7) {
        return;
    }
    
    if (is_post_type_archive('datamachine_events')) {
        wp_redirect(home_url(), 301);
        exit;
    }
}
```

**When Called:**
WordPress `template_redirect` action, before template rendering

**Redirect Behavior:**
- **URL:** `/events/` → Homepage
- **Status:** 301 Permanent Redirect
- **Blog ID:** Only on events.extrachill.com (blog ID 7)

**Why Redirect:**
- Prevents duplicate content between `/events/` and homepage
- Consolidates link equity to single URL
- Homepage already displays full event calendar

---

## ec_events_breadcrumb_root()

**Purpose:** Customize breadcrumb root for events site

**Hook:** `extrachill_breadcrumbs_root`

**Parameters:**
- `$root_link` (string): Default root breadcrumb link HTML from theme

**Return Value:**
- Modified root link with events context for events site
- Unchanged root link otherwise

**Usage:**
```php
add_filter('extrachill_breadcrumbs_root', 'ec_events_breadcrumb_root');

function ec_events_breadcrumb_root($root_link) {
    if (is_front_page()) {
        $main_site_url = ec_get_site_url('main');
        return '<a href="' . esc_url($main_site_url) . '">Extra Chill</a>';
    }

    $main_site_url = ec_get_site_url('main');
    return '<a href="' . esc_url($main_site_url) . '">Extra Chill</a> › <a href="' . esc_url(home_url()) . '">Events Calendar</a>';
}
```

**Output:**
- **Homepage:** `Extra Chill`
- **Other Pages:** `Extra Chill › Events Calendar`

---

## ec_events_breadcrumb_trail_homepage()

**Purpose:** Override breadcrumb trail for homepage

**Hook:** `extrachill_breadcrumbs_override_trail`

**Parameters:**
- `$custom_trail` (string|false): Existing custom trail from other filters

**Return Value:**
- Custom trail for homepage on events site
- Unchanged trail otherwise

**Usage:**
```php
add_filter('extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_homepage');

function ec_events_breadcrumb_trail_homepage($custom_trail) {
    if (is_front_page()) {
        return '<span class="network-dropdown-target">Events Calendar</span>';
    }

    return $custom_trail;
}
```

**Output:**
`Extra Chill › Events Calendar` (root + trail)

---

## ec_events_breadcrumb_trail_archives()

**Purpose:** Override breadcrumb trail for archive pages

**Hook:** `extrachill_breadcrumbs_override_trail`

**Parameters:**
- `$custom_trail` (string|false): Custom breadcrumb trail from other filters

**Return Value:**
- Custom trail for archives on events site
- Unchanged trail otherwise

**Usage:**
```php
add_filter('extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_archives');

function ec_events_breadcrumb_trail_archives($custom_trail) {
    if (is_tax()) {
        $term = get_queried_object();
        if ($term && isset($term->name)) {
            return '<span>' . esc_html($term->name) . '</span>';
        }
    }

    if (is_post_type_archive('datamachine_events')) {
        return '<span class="network-dropdown-target">Events Calendar</span>';
    }

    return $custom_trail;
}
```

**Output:**
- **Taxonomy:** `Extra Chill › Events Calendar › [Term Name]`
- **Post Type:** `Extra Chill › Events Calendar`

---

## ec_events_breadcrumb_trail_single()

**Purpose:** Override breadcrumb trail for single event posts

**Hook:** `extrachill_breadcrumbs_override_trail`

**Parameters:**
- `$custom_trail` (string|false): Custom breadcrumb trail from other filters

**Return Value:**
- Custom trail for single events on events site
- Unchanged trail otherwise

**Usage:**
```php
add_filter('extrachill_breadcrumbs_override_trail', 'ec_events_breadcrumb_trail_single');

function ec_events_breadcrumb_trail_single($custom_trail) {
    if (is_singular('datamachine_events')) {
        return '<span class="breadcrumb-title">' . get_the_title() . '</span>';
    }

    return $custom_trail;
}
```

**Output:**
`Extra Chill › Events Calendar › [Event Title]`

---

## ec_events_back_to_home_label()

**Purpose:** Override back-to-home link label for event pages

**Hook:** `extrachill_back_to_home_label`

**Parameters:**
- `$label` (string): Default back-to-home link label from theme
- `$url` (string): Back-to-home link URL

**Return Value:**
- Modified label for event pages on events site
- Unchanged label for homepage or other sites

**Usage:**
```php
add_filter('extrachill_back_to_home_label', 'ec_events_back_to_home_label', 10, 2);

function ec_events_back_to_home_label($label, $url) {
    if (is_front_page()) {
        return $label;
    }

    return '← Back to Events Calendar';
}
```

**Output:**
- **Event Pages:** `← Back to Events Calendar`
- **Homepage:** Default theme label (e.g., `← Back to Extra Chill`)

---

## extrachill_events_register_blocks()

**Purpose:** Register event-submission block from build directory

**Hook:** `init`

**Parameters:** None

**Return Value:** None

**Usage:**
```php
add_action('init', 'extrachill_events_register_blocks');

function extrachill_events_register_blocks() {
    register_block_type(EXTRACHILL_EVENTS_PLUGIN_DIR . 'build/event-submission');
}
```

**When Called:**
WordPress initialization

**Block Registered:**
`extrachill/event-submission` - Frontend event submission form block

---

## extrachill_events_secondary_header_items()

**Purpose:** Add submit event link to secondary header

**Hook:** `extrachill_secondary_header_items`

**Parameters:**
- `$items` (array): Current secondary header items

**Return Value:** array

**Usage:**
```php
add_filter('extrachill_secondary_header_items', 'extrachill_events_secondary_header_items');

function extrachill_events_secondary_header_items($items) {
    $items[] = array(
        'url' => home_url('/submit/'),
        'label' => __('Submit Event', 'extrachill-events'),
        'priority' => 10,
    );
    return $items;
}
```

**Output:**
Adds "Submit Event" link to secondary header navigation

## Function Organization

### Main Plugin File
- `extrachill_events()`
- `extrachill_events_register_blocks()`
- `ec_events_render_homepage()`
- `ec_events_override_archive_template()`
- `ec_events_redirect_post_type_archive()`

### Breadcrumb Integration File
- `ec_events_override_breadcrumbs()`
- `ec_events_breadcrumb_root()`
- `ec_events_breadcrumb_trail_homepage()`
- `ec_events_breadcrumb_trail_archives()`
- `ec_events_breadcrumb_trail_single()`
- `ec_events_back_to_home_label()`

### Navigation Integration File
- `extrachill_events_secondary_header_items()`

## Naming Convention

All global functions use `ec_events_` or `extrachill_events_` prefix to avoid naming conflicts with other plugins.
