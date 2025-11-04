# ExtraChill Events

WordPress plugin providing seamless integration between ExtraChill themes and popular event plugins. Replaces previous dm-events integration with a flexible architecture supporting multiple event management plugins within the ExtraChill ecosystem.

## Features

- **Homepage Template Override**: Custom homepage template for events.extrachill.com (blog ID 7) displaying static page content with dm-events calendar block support
- **Archive Template Override**: Unified archive template for all taxonomy and post type archives on events.extrachill.com
- **DM Events Integration**: Complete integration with badge styling, breadcrumb override, related events display, and theme hook bridging
- **Breadcrumb Integration**: Custom breadcrumb system with "Extra Chill → Events" root for events.extrachill.com
- **CSS Integration**: Automatic enqueuing of theme and plugin styles for single events and calendar pages
- **Post Meta Management**: Hides post meta for dm_events post type
- **SEO Optimization**: Redirects /events/ post type archive to homepage for SEO consolidation

## Installation

### Multisite Network Setup
1. Create new site in Network Admin: **Sites → Add New**
   - Site URL: `events.extrachill.com`
   - Site Title: "ExtraChill Events"
2. Activate required plugins on the new site:
   - **Data Machine** - Event automation and content pipeline
   - **dm-events** - Calendar block and event post type
   - **extrachill-events** - Homepage and archive template overrides

### Plugin Installation
1. Navigate to plugin directory and create production build:
    ```bash
    cd extrachill-plugins/extrachill-events
    ./build.sh
    ```
2. Upload the generated ZIP from `/build` directory via WordPress admin: **Plugins → Add New → Upload Plugin**
3. Activate the plugin on events.extrachill.com
4. Plugin automatically detects and integrates with supported event plugins

### Homepage Setup
1. Create a page in WordPress admin (e.g., "Events Calendar")
2. Add dm-events calendar block to the page via block editor
3. Set as static homepage: **Settings → Reading → Front page: "Events Calendar"**

## Supported Plugins

### DM Events (Complete Integration)
**Integration Features**:
- **Badge Styling**: Maps taxonomy badges to ExtraChill's badge class structure via dm_events_badge_wrapper_classes and dm_events_badge_classes filters
  - Festival badges: `taxonomy-badge festival-badge festival-{slug}`
  - Location badges: `taxonomy-badge location-badge location-{slug}`
  - Other taxonomies: Base `taxonomy-badge` class
- **Breadcrumb Override**: Replaces dm-events breadcrumbs with theme's `display_breadcrumbs()` function via dm_events_breadcrumbs filter
- **Related Events Display**: Shows related events by festival and venue taxonomies using theme's `extrachill_display_related_posts()` function (events.extrachill.com only)
- **Theme Hook Bridging**: Bridges dm_events_before_single_event and dm_events_after_single_event to theme's extrachill_before_body_content and extrachill_after_body_content hooks
- **Post Meta Hiding**: Removes post meta display for dm_events post type
- **CSS Integration**: Enqueues theme's single-post.css and plugin's single-event.css for event pages

**Integration Hooks**:
- `dm_events_badge_wrapper_classes` - Adds theme wrapper classes
- `dm_events_badge_classes` - Adds festival/location-specific badge classes
- `dm_events_breadcrumbs` - Overrides with theme breadcrumbs
- `dm_events_related_events` - Displays related events by taxonomy
- `dm_events_before_single_event` - Bridges to theme before content hook
- `dm_events_after_single_event` - Bridges to theme after content hook

## Template Overrides

### Homepage Template (`inc/templates/homepage.php`)
- Displays content from WordPress static homepage (Settings → Reading → "A static page")
- Renders homepage post content via `apply_filters('the_content', $homepage->post_content)`
- Supports dm-events calendar block and any other blocks via WordPress editor
- Includes breadcrumb display and full-width container

### Archive Template (`inc/templates/archive.php`)
- Unified template for all archive pages on events.extrachill.com
- Displays dm-events calendar block with automatic taxonomy filtering
- Used for taxonomy archives (/festival/bonnaroo/, /venue/ryman/), post type archives, and date archives
- Includes breadcrumb display and full-width container

## Breadcrumb System

### Custom Breadcrumb Integration (`inc/core/breadcrumb-integration.php`)
- **Root Breadcrumb**: "Extra Chill → Events" for events.extrachill.com (blog ID 7)
- **Homepage Trail**: Shows "Events" on homepage, full trail on other pages
- **Archive Trails**: Context-aware breadcrumbs for taxonomy and post type archives
- **Back-to-Home Label**: Customizes back-to-home link text on event pages ("Back to Events" instead of "Back to Extra Chill")

## Requirements

- WordPress 5.0+
- PHP 7.4+
- ExtraChill theme (for styling integration)
- Multisite network setup
- Data Machine and dm-events plugins (for full functionality)

## Development

```bash
# Navigate to plugin directory
cd extrachill-plugins/extrachill-events

# Install dependencies
composer install

# Run tests
composer test

# Code linting
composer run lint:php

# Build production package
./build.sh

# Output: /build/extrachill-events/ directory and /build/extrachill-events.zip file
```

## Architecture

### Plugin Loading Pattern
- **Direct `require_once` Pattern**: All plugin classes loaded via manual `require_once` statements (no PSR-4 autoloading)
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Composer Autoloader**: ONLY for development dependencies (PHPUnit, PHPCS)
- **PSR-4 Configuration**: Exists in composer.json but unused (platform-wide pattern)

### Core Classes
- **ExtraChillEvents**: Main plugin class (singleton), handles initialization and integration management
- **DmEventsIntegration**: Complete dm-events integration with badge styling, breadcrumb override, related events display, and theme hook bridging
- **Breadcrumb Integration**: Custom breadcrumb system for events.extrachill.com

### CSS Assets
- **Asset Directory**: `assets/css/`
- **Files**: `calendar.css` (homepage calendar enhancements), `single-event.css` (single event page card treatment)
- **Integration**: Theme CSS custom properties, conditional loading, automatic cache busting via filemtime()

## License

GPL v2 or later