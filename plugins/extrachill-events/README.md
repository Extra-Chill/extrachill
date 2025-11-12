# ExtraChill Events

WordPress plugin providing seamless integration between ExtraChill themes and popular event plugins. Replaces previous datamachine-events integration with a flexible architecture supporting multiple event management plugins within the ExtraChill ecosystem.

## Features

- **Homepage Template Override**: Custom homepage template for events.extrachill.com (blog ID 7) displaying static page content with datamachine-events calendar block support
- **Archive Template Override**: Unified archive template for all taxonomy and post type archives on events.extrachill.com
- **DM Events Integration**: Complete integration with badge styling, taxonomy exclusion, button styling, share button rendering, breadcrumb override, related events display, and theme hook bridging
- **Breadcrumb Integration**: Custom breadcrumb system with "Extra Chill → Events" root for events.extrachill.com
- **CSS Integration**: Automatic enqueuing of theme and plugin styles (single-post.css, sidebar.css, single-event.css) for single events and calendar pages
- **Post Meta Management**: Hides post meta for datamachine_events post type
- **SEO Optimization**: Redirects /events/ post type archive to homepage for SEO consolidation

## Installation

### Multisite Network Setup
1. Create new site in Network Admin: **Sites → Add New**
   - Site URL: `events.extrachill.com`
   - Site Title: "ExtraChill Events"
2. Activate required plugins on the new site:
   - **Data Machine** - Event automation and content pipeline
   - **datamachine-events** - Calendar block and event post type
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
2. Add datamachine-events calendar block to the page via block editor
3. Set as static homepage: **Settings → Reading → Front page: "Events Calendar"**

## Supported Plugins

### DM Events (Complete Integration)
**Integration Features**:
- **Badge Styling**: Maps taxonomy badges to ExtraChill's badge class structure via datamachine_events_badge_wrapper_classes and datamachine_events_badge_classes filters
  - Festival badges: `taxonomy-badge festival-badge festival-{slug}`
  - Location badges: `taxonomy-badge location-badge location-{slug}`
  - Other taxonomies: Base `taxonomy-badge` class
- **Taxonomy Exclusion**: Excludes venue and artist taxonomies from badge display (venue has 9 meta fields displayed separately, artist prevents redundant display)
- **Button Styling**: Maps modal buttons (primary/secondary) and ticket buttons to theme's button styling classes
- **Share Button Integration**: Renders share button alongside ticket button in flexbox container (events.extrachill.com only)
- **Breadcrumb Override**: Replaces datamachine-events breadcrumbs with theme's `display_breadcrumbs()` function via datamachine_events_breadcrumbs filter
- **Related Events Display**: Shows related events by festival and venue taxonomies using theme's `extrachill_display_related_posts()` function (events.extrachill.com only)
- **Theme Hook Bridging**: Bridges datamachine_events_before_single_event and datamachine_events_after_single_event to theme's extrachill_before_body_content and extrachill_after_body_content hooks
- **Post Meta Hiding**: Removes post meta display for datamachine_events post type
- **CSS Integration**: Enqueues theme's single-post.css, sidebar.css, and plugin's single-event.css for event pages

**Integration Hooks**:
- `datamachine_events_badge_wrapper_classes` - Adds theme wrapper classes
- `datamachine_events_badge_classes` - Adds festival/location-specific badge classes
- `datamachine_events_excluded_taxonomies` - Excludes venue and artist taxonomies from badge display
- `datamachine_events_modal_button_classes` - Adds theme button classes to modal buttons
- `datamachine_events_ticket_button_classes` - Adds theme button classes to ticket button
- `datamachine_events_breadcrumbs` - Overrides with theme breadcrumbs
- `datamachine_events_related_events` - Displays related events by taxonomy
- `datamachine_events_before_single_event` - Bridges to theme before content hook
- `datamachine_events_after_single_event` - Bridges to theme after content hook
- `datamachine_events_action_buttons` - Renders share button alongside ticket button

## Template Overrides

### Homepage Template (`inc/templates/homepage.php`)
- Displays content from WordPress static homepage (Settings → Reading → "A static page")
- Renders homepage post content via `apply_filters('the_content', $homepage->post_content)`
- Supports datamachine-events calendar block and any other blocks via WordPress editor
- Includes breadcrumb display and full-width container

### Archive Template (`inc/templates/archive.php`)
- Unified template for all archive pages on events.extrachill.com
- Displays datamachine-events calendar block with automatic taxonomy filtering
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
- Data Machine and datamachine-events plugins (for full functionality)

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

# Output: Only /build/extrachill-events.zip file (unzip when directory access needed)
```

## Architecture

### Plugin Loading Pattern
- **Direct `require_once` Pattern**: All plugin classes loaded via manual `require_once` statements (NO PSR-4 autoloading)
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Composer Autoloader**: ONLY for development dependencies (PHPUnit, PHPCS)

### Core Classes
- **ExtraChillEvents**: Main plugin class managing initialization, template overrides, and SEO redirects
- **DataMachineEventsIntegration**: Complete datamachine-events integration with badge/button styling, share button, breadcrumb override, related events, and theme hook bridging
- **Breadcrumb Integration**: Five-function breadcrumb system with custom root and trail overrides

### CSS Assets
- **Asset Directory**: `assets/css/`
- **Files**:
  - `calendar.css` - Homepage calendar enhancements (minimal placeholder)
  - `single-event.css` - Event info grid card treatment and action buttons flexbox container
- **Conditional Loading**:
  - Single events: theme's single-post.css, sidebar.css, and plugin's single-event.css
  - Homepage calendar: calendar.css (events.extrachill.com only)
- **Cache Busting**: Automatic via filemtime()
- **CSS Custom Properties**: Uses theme variables (--background-color, --border-color, --card-shadow)

## License

GPL v2 or later