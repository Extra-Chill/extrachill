# Plugin Classes

Object-oriented classes provided by the ExtraChill Events plugin.

## ExtraChillEvents

**Namespace:** Global

**File:** `extrachill-events.php`

**Purpose:** Main singleton class managing plugin initialization, template overrides, and integration loading

### Class Properties

#### $instance (static)
**Type:** ExtraChillEvents|null

**Visibility:** private static

**Purpose:** Singleton instance storage

---

#### $integrations
**Type:** array

**Visibility:** private

**Purpose:** Stores loaded integration class instances

**Structure:**
```php
array(
    'datamachine_events' => DataMachineEventsIntegration instance
)
```

### Class Methods

#### get_instance() (static)
**Visibility:** public static

**Parameters:** None

**Return Value:** ExtraChillEvents instance

**Purpose:** Get or create singleton instance

**Usage:**
```php
$plugin = ExtraChillEvents::get_instance();
```

---

#### __construct()
**Visibility:** private

**Purpose:** Initialize plugin hooks, dependencies, and integrations

**Called By:** `get_instance()` when creating first instance

**Actions:**
1. `init_hooks()` - Register WordPress hooks
2. `load_dependencies()` - Load integration files
3. `init_integrations()` - Initialize integration classes

---

#### init_hooks()
**Visibility:** private

**Purpose:** Register WordPress plugin hooks

**Hooks Registered:**
- `plugins_loaded` → `load_textdomain()`
- `register_activation_hook()` → `activate()`
- `register_deactivation_hook()` → `deactivate()`

---

#### load_textdomain()
**Visibility:** public

**Purpose:** Load plugin translation files

**Text Domain:** `datamachine-events`

---

#### load_dependencies()
**Visibility:** private

**Purpose:** Load integration class files via direct includes

**Files Loaded:**
1. `vendor/autoload.php` (if exists, for development dependencies)
2. `inc/core/datamachine-events-integration.php`
3. `inc/core/breadcrumb-integration.php`

**Note:** Composer autoloader exists for development dependencies only (PHPUnit, PHPCS). All plugin code uses direct `require_once` includes.

---

#### init_integrations()
**Visibility:** private

**Purpose:** Initialize event plugin integrations conditionally

**Detection Logic:**
```php
if (class_exists('DataMachineEvents\Core\Taxonomy_Badges')) {
    $this->integrations['datamachine_events'] = new ExtraChillEvents\DataMachineEventsIntegration();
}
```

**Extensible:** Additional event plugin integrations can be added here

---

#### activate()
**Visibility:** public

**Purpose:** Plugin activation hook

**Actions:**
- Flush rewrite rules to ensure custom URLs work

---

#### deactivate()
**Visibility:** public

**Purpose:** Plugin deactivation hook

**Actions:**
- Flush rewrite rules to clean up custom URLs

---

#### get_integrations()
**Visibility:** public

**Parameters:** None

**Return Value:** array of integration instances

**Purpose:** Access loaded integration instances

**Usage:**
```php
$integrations = extrachill_events()->get_integrations();
if (isset($integrations['datamachine_events'])) {
    // Integration loaded
}
```

---

## ExtraChillEvents\DataMachineEventsIntegration

**Namespace:** ExtraChillEvents

**File:** `inc/core/datamachine-events-integration.php`

**Purpose:** Complete datamachine-events integration providing badge/button styling, breadcrumb override, related events, share button, and CSS management

### Class Methods

#### __construct()
**Visibility:** public

**Purpose:** Initialize integration hooks

**Actions:**
- Calls `init_hooks()` to register all filters and actions

---

#### init_hooks()
**Visibility:** private

**Purpose:** Register all WordPress filters and actions

**Conditional Filters (require class existence):**
- Badge filters (require `DataMachineEvents\Core\Taxonomy_Badges`)
- Breadcrumb filter (require `DataMachineEvents\Core\Breadcrumbs`)

**Always Registered:**
- Button filters
- Related posts filters
- Share button action
- Post meta filter
- CSS enqueue actions

---

#### add_wrapper_classes()
**Visibility:** public

**Hook:** `datamachine_events_badge_wrapper_classes`

**Parameters:**
- `$wrapper_classes` (array)
- `$post_id` (int)

**Return Value:** array

**Purpose:** Add `taxonomy-badges` class to badge container

---

#### add_badge_classes()
**Visibility:** public

**Hook:** `datamachine_events_badge_classes`

**Parameters:**
- `$badge_classes` (array)
- `$taxonomy_slug` (string)
- `$term` (WP_Term)
- `$post_id` (int)

**Return Value:** array

**Purpose:** Add taxonomy-specific badge classes (festival-*, location-*)

---

#### exclude_venue_taxonomy()
**Visibility:** public

**Hook:** `datamachine_events_excluded_taxonomies`

**Parameters:**
- `$excluded` (array)

**Return Value:** array

**Purpose:** Exclude venue and artist taxonomies from badge display

---

#### add_modal_button_classes()
**Visibility:** public

**Hook:** `datamachine_events_modal_button_classes`

**Parameters:**
- `$classes` (array)
- `$button_type` (string)

**Return Value:** array

**Purpose:** Add theme button classes to modal buttons (primary/secondary)

---

#### add_ticket_button_classes()
**Visibility:** public

**Hook:** `datamachine_events_ticket_button_classes`

**Parameters:**
- `$classes` (array)

**Return Value:** array

**Purpose:** Add theme button classes to ticket purchase button

---

#### override_breadcrumbs()
**Visibility:** public

**Hook:** `datamachine_events_breadcrumbs`

**Parameters:**
- `$breadcrumbs` (string|null)
- `$post_id` (int)

**Return Value:** string

**Purpose:** Replace datamachine-events breadcrumbs with theme breadcrumbs

---

#### filter_event_taxonomies()
**Visibility:** public

**Hook:** `extrachill_related_posts_taxonomies`

**Parameters:**
- `$taxonomies` (array)
- `$post_id` (int)
- `$post_type` (string)

**Return Value:** array

**Purpose:** Use venue and location taxonomies for related events

---

#### allow_event_taxonomies()
**Visibility:** public

**Hook:** `extrachill_related_posts_allowed_taxonomies`

**Parameters:**
- `$allowed` (array)
- `$post_type` (string)

**Return Value:** array

**Purpose:** Whitelist location taxonomy for related events

---

#### filter_event_query_args()
**Visibility:** public

**Hook:** `extrachill_related_posts_query_args`

**Parameters:**
- `$query_args` (array)
- `$taxonomy` (string)
- `$post_id` (int)
- `$post_type` (string)

**Return Value:** array

**Purpose:** Show only upcoming events, ordered by date

---

#### exclude_venue_from_location()
**Visibility:** public

**Hook:** `extrachill_related_posts_tax_query`

**Parameters:**
- `$tax_query` (array)
- `$taxonomy` (string)
- `$term_id` (int)
- `$post_id` (int)
- `$post_type` (string)

**Return Value:** array

**Purpose:** Exclude same venue when showing location-based related events

---

#### hide_post_meta_for_events()
**Visibility:** public

**Hook:** `extrachill_post_meta`

**Parameters:**
- `$default_meta` (string)
- `$post_id` (int)
- `$post_type` (string)

**Return Value:** string

**Purpose:** Hide theme post meta for datamachine_events post type

---

#### enqueue_single_post_styles()
**Visibility:** public

**Hook:** `wp_enqueue_scripts`

**Purpose:** Enqueue CSS for single event pages

**Files Enqueued:**
1. Theme's single-post.css
2. Theme's sidebar.css
3. Plugin's single-event.css
4. Theme's share.css

**Condition:** Only on `is_singular('datamachine_events')`

---

#### enqueue_calendar_styles()
**Visibility:** public

**Hook:** `wp_enqueue_scripts`

**Purpose:** Enqueue calendar CSS for homepage

**Files Enqueued:**
1. Plugin's calendar.css

**Condition:** Only on blog ID 7 AND `is_front_page()`

---

#### render_share_button()
**Visibility:** public

**Hook:** `datamachine_events_action_buttons`

**Parameters:**
- `$post_id` (int)
- `$ticket_url` (string)

**Return Value:** void

**Purpose:** Render share button in action buttons container

**Condition:** Only on blog ID 7

## Class Instantiation

### ExtraChillEvents
```php
// Singleton pattern
$plugin = ExtraChillEvents::get_instance();
```

### DataMachineEventsIntegration
```php
// Instantiated by ExtraChillEvents::init_integrations()
if (class_exists('DataMachineEvents\Core\Taxonomy_Badges')) {
    new ExtraChillEvents\DataMachineEventsIntegration();
}
```

## Class Loading

Both classes are loaded via direct `require_once` statements (NO PSR-4 autoloading):

```php
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-events-integration.php';
```

Composer autoloader exists only for development dependencies (PHPUnit, PHPCS).
