# Plugin Constants

WordPress constants defined by the ExtraChill Events plugin.

## EXTRACHILL_EVENTS_VERSION

**Value:** `0.1.0`

**Purpose:** Plugin version number for cache busting and compatibility checks

**Usage:**
```php
define('EXTRACHILL_EVENTS_VERSION', '0.1.0');
```

**Example:**
```php
if (defined('EXTRACHILL_EVENTS_VERSION')) {
    // Plugin is active
    $version = EXTRACHILL_EVENTS_VERSION;
}
```

---

## EXTRACHILL_EVENTS_PLUGIN_DIR

**Value:** Plugin directory absolute path with trailing slash

**Purpose:** Reference plugin directory for file includes and existence checks

**Usage:**
```php
define('EXTRACHILL_EVENTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
```

**Example Path:**
```
/var/www/html/wp-content/plugins/extrachill-events/
```

**Example Usage:**
```php
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-events-integration.php';

$template_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';

$css_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css';
if (file_exists($css_file)) {
    // File exists
}
```

---

## EXTRACHILL_EVENTS_PLUGIN_URL

**Value:** Plugin directory URL with trailing slash

**Purpose:** Reference plugin URL for asset enqueuing (CSS, JS, images)

**Usage:**
```php
define('EXTRACHILL_EVENTS_PLUGIN_URL', plugin_dir_url(__FILE__));
```

**Example URL:**
```
https://events.extrachill.com/wp-content/plugins/extrachill-events/
```

**Example Usage:**
```php
wp_enqueue_style(
    'extrachill-events-single',
    EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
    array('extrachill-style'),
    filemtime(EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css')
);
```

---

## EXTRACHILL_EVENTS_PLUGIN_FILE

**Value:** Plugin main file absolute path

**Purpose:** Reference plugin file for activation/deactivation hooks and plugin metadata

**Usage:**
```php
define('EXTRACHILL_EVENTS_PLUGIN_FILE', __FILE__);
```

**Example Path:**
```
/var/www/html/wp-content/plugins/extrachill-events/extrachill-events.php
```

**Example Usage:**
```php
register_activation_hook(EXTRACHILL_EVENTS_PLUGIN_FILE, array($this, 'activate'));
register_deactivation_hook(EXTRACHILL_EVENTS_PLUGIN_FILE, array($this, 'deactivate'));

// Get plugin metadata
$plugin_data = get_plugin_data(EXTRACHILL_EVENTS_PLUGIN_FILE);
```

## Constant Availability

All constants are defined in the main plugin file (`extrachill-events.php`) and are available immediately after the plugin loads.

### Safe Usage
```php
if (defined('EXTRACHILL_EVENTS_PLUGIN_DIR')) {
    $file_path = EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/integration.php';
}
```

### Direct Usage (in plugin files)
```php
// Safe to use directly within plugin files
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/datamachine-events-integration.php';
```

## WordPress Functions Used

### plugin_dir_path()
**Documentation:** https://developer.wordpress.org/reference/functions/plugin_dir_path/

Returns absolute path to plugin directory with trailing slash.

**Example:**
```php
plugin_dir_path(__FILE__)
// Returns: /var/www/html/wp-content/plugins/extrachill-events/
```

### plugin_dir_url()
**Documentation:** https://developer.wordpress.org/reference/functions/plugin_dir_url/

Returns URL to plugin directory with trailing slash.

**Example:**
```php
plugin_dir_url(__FILE__)
// Returns: https://events.extrachill.com/wp-content/plugins/extrachill-events/
```

## Common Usage Patterns

### Including Files
```php
require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/core/breadcrumb-integration.php';
```

### Template Override
```php
if (get_current_blog_id() === ec_get_blog_id('events')) {
    return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
```

### File Existence Check
```php
$css_file = EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/calendar.css';
if (file_exists($css_file)) {
    // Load CSS
}
```

### Asset Enqueuing
```php
wp_enqueue_style(
    'extrachill-events-calendar',
    EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/calendar.css',
    array('extrachill-style'),
    EXTRACHILL_EVENTS_VERSION
);
```

### Cache Busting with filemtime()
```php
wp_enqueue_style(
    'extrachill-events-single',
    EXTRACHILL_EVENTS_PLUGIN_URL . 'assets/css/single-event.css',
    array('extrachill-style'),
    filemtime(EXTRACHILL_EVENTS_PLUGIN_DIR . 'assets/css/single-event.css')
);
```

Combines URL constant (for browser loading) with DIR constant (for file modification time).
