# ExtraChill Events

WordPress plugin providing seamless integration between ExtraChill themes and popular event plugins. Replaces previous dm-events integration with a flexible architecture supporting multiple event management plugins within the ExtraChill ecosystem.

## Plugin Information

- **Name**: ExtraChill Events
- **Version**: 1.0.0
- **Text Domain**: `extrachill-events`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4
- **Network**: false

## events.extrachill.com Integration

This plugin provides homepage template override for **events.extrachill.com** (site #7 in the multisite network), creating a dedicated calendar-focused event hub powered by Data Machine and dm-events.

### Homepage Template Override Architecture

**Template Override Pattern** (follows extrachill-chat plugin pattern):
- Uses `extrachill_template_homepage` filter from theme's universal routing system
- Hardcoded blog ID for performance (events.extrachill.com = site #7)
- Blog ID comparison ensures override only applies to events.extrachill.com
- Returns plugin template path for complete homepage control

**Implementation**:
```php
add_filter( 'extrachill_template_homepage', 'ec_events_override_homepage_template' );

function ec_events_override_homepage_template( $template ) {
    // Only override on events.extrachill.com (blog ID 7)
    $events_blog_id = 7; // events.extrachill.com
    if ( get_current_blog_id() === $events_blog_id ) {
        return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
    }
    return $template;
}
```

### Data Machine Calendar Integration

**Homepage Template** (`inc/templates/homepage.php`):
- Displays content from WordPress static homepage (Settings → Reading → "A static page")
- Renders homepage post content via `apply_filters('the_content', $homepage->post_content)`
- Allows adding dm-events calendar block (or any blocks) via WordPress editor
- Full-width container with `get_header()` and `get_footer()` for complete page control
- DM Events calendar block handles all filtering, pagination, and event display logic

**Setup Process**:
1. Create a page in WordPress admin (e.g., "Events Calendar")
2. Add dm-events calendar block to the page via block editor
3. Set as static homepage: Settings → Reading → "A static page" → Front page: "Events Calendar"

**Required Plugins** (site-activated on events.extrachill.com):
- **Data Machine** - Event automation and content pipeline
- **dm-events** - Calendar block and event post type
- **extrachill-events** - Homepage template override (this plugin)

### Multisite Network Architecture

**events.extrachill.com Setup**:
1. Create site in Network Admin → Sites → Add New
2. Site URL: events.extrachill.com
3. Site Title: "ExtraChill Events"
4. Activate Data Machine, dm-events, and extrachill-events on the new site

**Data Flow**:
- Data Machine handles event import and automation on events.extrachill.com
- dm-events calendar block provides full event display and filtering
- Main site (extrachill.com) focuses on content automation without event processing
- Events site serves as centralized event hub for entire network

**Benefits**:
- Clean separation: main site for content, events site for calendar
- Centralized event management via Data Machine pipelines
- Single source of truth for all network events
- Optimized performance: no event pipeline complexity on main site

## Architecture

### Plugin Loading Pattern
- **Direct `require_once` Pattern**: All plugin classes loaded via manual `require_once` statements
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Composer Autoloader**: ONLY used for development dependencies (PHPUnit, PHPCS)
- **PSR-4 Configuration**: Exists in composer.json but unused for plugin code (historical artifact following platform-wide pattern)
- **Manual Includes**: All integration classes loaded via direct `require_once` statements in main plugin file

### Core Classes
- **ExtraChillEvents**: Main plugin class (singleton), handles initialization and integration management (`extrachill-events.php`)
- **DmEventsIntegration**: Complete dm-events integration with badge styling, breadcrumb override, related events display, theme hook bridging, CSS enqueuing, and post meta management (`inc/core/dm-events-integration.php`)
- **Breadcrumb Integration**: Custom breadcrumb system for events.extrachill.com with root override and trail customization (`inc/core/breadcrumb-integration.php`)

**Note**: This plugin replaces the previous dm-events integration that was removed from the theme.

## Key Features

### Homepage Template Override
**events.extrachill.com Integration**:
- **Blog ID Targeting**: Only applies to events.extrachill.com (blog ID 7) in multisite network
- **Static Page Display**: Renders WordPress static homepage content with full block editor support
- **Calendar Block Support**: Enables dm-events calendar block placement via WordPress editor
- **Breadcrumb Integration**: Includes theme breadcrumb display with custom "Events" root

### Archive Template Override
**Unified Archive System**:
- **Single Template Approach**: One archive template handles all archive types (taxonomy, post type, date, author)
- **Context Detection**: Template automatically detects archive context (is_tax(), is_post_type_archive(), etc.)
- **Taxonomy Archives**: Handles festival, venue, and location taxonomy archives with context-aware filtering
- **Post Type Archives**: Manages dm_events post type archive pages (rarely seen due to homepage redirect)
- **Calendar Block Rendering**: Displays dm-events calendar with automatic taxonomy filtering based on archive context
- **Context-Aware Display**: Calendar block detects archive context and filters events accordingly
- **SEO Optimization**: /events/ post type archive redirects to homepage (301) for URL consolidation

### DM Events Integration
**Complete Integration Implementation**:
- **Badge Styling Integration**: Maps dm-events taxonomy badges to ExtraChill's badge class structure via dm_events_badge_wrapper_classes and dm_events_badge_classes filters
- **Festival-Specific Colors**: Enables custom festival colors (Bonnaroo, Coachella) from theme's badge-colors.css
- **Location Styling**: Converts venue taxonomies to location styling for regional color coding
- **Taxonomy Exclusion**: Excludes venue and artist taxonomies from badge display via dm_events_excluded_taxonomies filter (venue has 9 meta fields displayed separately, artist prevents redundant display)
- **Button Styling Integration**: Maps dm-events modal and ticket buttons to theme's button styling classes via dm_events_modal_button_classes and dm_events_ticket_button_classes filters
- **Share Button Integration**: Renders share button alongside ticket button in flexbox container via dm_events_action_buttons hook (events.extrachill.com only)
- **Breadcrumb Override**: Replaces dm-events breadcrumbs with theme's breadcrumb system via dm_events_breadcrumbs filter
- **Related Events Display**: Shows related events by festival and venue taxonomies using theme's related posts function on events.extrachill.com (blog ID 7)
- **Theme Hook Bridging**: Bridges dm_events_before_single_event and dm_events_after_single_event to theme's extrachill_before_body_content and extrachill_after_body_content hooks
- **CSS Integration**: Enqueues theme's single-post.css, sidebar.css, and plugin's single-event.css for event pages
- **Post Meta Management**: Hides post meta display for dm_events post type
- **Backward Compatibility**: Preserves original plugin classes while adding theme enhancements

### Taxonomy Mapping System
**Badge Class Structure**:
- **festival** → `taxonomy-badge festival-badge festival-{slug}` (e.g., `festival-bonnaroo`)
- **location** → `taxonomy-badge location-badge location-{slug}` (e.g., `location-charleston`)
- **other taxonomies** → Uses plugin's default styling with base `taxonomy-badge` class

**Integration Hooks**:
- **dm_events_badge_wrapper_classes** - Adds `taxonomy-badges` wrapper class for theme styling
- **dm_events_badge_classes** - Adds `taxonomy-badge` base class and festival/location-specific classes
- **dm_events_excluded_taxonomies** - Excludes venue and artist taxonomies from badge display
- **dm_events_modal_button_classes** - Adds theme button classes to modal buttons (primary/secondary)
- **dm_events_ticket_button_classes** - Adds theme button classes to ticket purchase button
- **dm_events_breadcrumbs** - Overrides breadcrumbs with theme's `display_breadcrumbs()` function
- **dm_events_related_events** - Displays related events using theme's `extrachill_display_related_posts()` function
- **dm_events_before_single_event** - Bridges to theme's `extrachill_before_body_content` hook
- **dm_events_after_single_event** - Bridges to theme's `extrachill_after_body_content` hook
- **dm_events_action_buttons** - Renders share button alongside ticket button in flexbox container (events.extrachill.com only)

### Single Event Template Integration
**Integration Flow** (without template override):
1. **Badge Enhancement**: dm-events renders badges → extrachill-events filters add theme classes → theme CSS applies styling
2. **Breadcrumb Override**: dm-events calls breadcrumb filter → extrachill-events returns theme breadcrumbs
3. **Theme Hooks**: dm-events fires template hooks → extrachill-events bridges to theme hooks for notices/content injection
4. **Share Button Integration**: dm-events fires action buttons hook → extrachill-events renders share button with `button-2 button-large` styling in flexbox container (events.extrachill.com only)
5. **Related Events**: dm-events fires related events hook → extrachill-events displays related events by taxonomy (events.extrachill.com only)
6. **CSS Integration**: Plugin enqueues theme and custom styles for single event pages including flexbox action button container
7. **Post Meta Management**: Hides post meta for dm_events post type
8. **Complete Integration**: Works seamlessly without modifying dm-events template files

### Template Override System
**Homepage Override** (events.extrachill.com only):
- **Filter Hook**: Uses `extrachill_template_homepage` filter from theme's universal routing system
- **Blog ID Check**: Only applies to blog ID 7 (events.extrachill.com)
- **Content Display**: Renders static homepage content with full block editor support
- **Calendar Integration**: Supports dm-events calendar block placement

**Archive Override** (events.extrachill.com only):
- **Filter Hook**: Uses `extrachill_template_archive` filter for all archive types
- **Unified Template**: Single template handles taxonomy, post type, date, and author archives
- **Calendar Rendering**: Displays dm-events calendar block with automatic filtering
- **Context Detection**: Calendar block detects archive context and filters events accordingly
- **SEO Redirect**: /events/ post type archive redirects to homepage (301 permanent redirect)

### Breadcrumb Integration System

**Five Core Functions** (`inc/core/breadcrumb-integration.php`):
1. **`ec_events_breadcrumb_root()`** - Customizes root breadcrumb link
2. **`ec_events_breadcrumb_trail_homepage()`** - Homepage-specific trail override
3. **`ec_events_breadcrumb_trail_archives()`** - Archive page trail customization
4. **`ec_events_breadcrumb_trail_single()`** - Single event page trail customization
5. **`ec_events_back_to_home_label()`** - Back-to-home link text modification

**Filter Hooks Used**:
- **`extrachill_breadcrumbs_root`** - Modifies root breadcrumb structure
- **`extrachill_breadcrumbs_override_trail`** - Customizes breadcrumb trails
- **`extrachill_back_to_home_label`** - Changes back-to-home link text

**Custom Breadcrumb Root**:
- **Site-Specific**: Only applies to events.extrachill.com (blog ID 7)
- **Root Structure**: "Extra Chill → Events" prefix for all pages
- **Homepage Display**: Shows just "Events" on homepage, full trail elsewhere
- **Implementation**: `ec_events_breadcrumb_root()` filters `extrachill_breadcrumbs_root`

**Context-Aware Trails**:
- **Taxonomy Archives**: "Extra Chill → Events → [Term Name]"
- **Post Type Archives**: "Extra Chill → Events" (rarely seen due to redirect to homepage)
- **Single Events**: "Extra Chill → Events → [Event Title]" (via dm-events integration)
- **Implementation**: `ec_events_breadcrumb_trail_archives()` and `ec_events_breadcrumb_trail_single()` filter `extrachill_breadcrumbs_override_trail`

**Back-to-Home Label Customization**:
- **Context**: Changes "Back to Extra Chill" to "Back to Events" on event pages
- **Scope**: Only applies to non-homepage pages on events.extrachill.com (blog ID 7)
- **Implementation**: `ec_events_back_to_home_label()` filters `extrachill_back_to_home_label`
- **Homepage Exception**: Homepage retains "Back to Extra Chill" to link to main site

## Technical Implementation

### Plugin Architecture
- **Singleton Pattern**: Single instance management via `ExtraChillEvents::get_instance()`
- **Integration Detection**: Automatic detection of active event plugins via class existence checks
- **Modular Loading**: Dynamic integration loading based on available plugins
- **Future-Proof**: Extensible architecture for additional event plugin integrations

### WordPress Integration
- **Hook System**: Proper WordPress plugin initialization with activation/deactivation hooks
- **Text Domain Loading**: Translation support via `load_plugin_textdomain()`
- **Rewrite Rules**: Flush rewrite rules on activation/deactivation
- **Dependency Management**: Composer autoloader with fallback manual includes

### CSS Asset Management

**Asset Directory Structure**:
- **Location**: `assets/css/`
- **Files**:
  - `calendar.css` - Homepage calendar enhancements (minimal placeholder for future enhancements)
  - `single-event.css` - Single event page styling with .event-info-grid card treatment and .event-action-buttons flexbox container

**Conditional Loading** (`inc/core/dm-events-integration.php`):
- **Single Events**: `enqueue_single_post_styles()` - Loads theme's single-post.css, sidebar.css, and plugin's single-event.css on `is_singular('dm_events')`
- **Homepage Calendar**: `enqueue_calendar_styles()` - Loads calendar.css on events.extrachill.com homepage (`is_front_page()` + blog ID 7)
- **Cache Busting**: All styles use `filemtime()` for automatic cache invalidation
- **Dependencies**: Plugin styles depend on `extrachill-style` for CSS custom properties

**Single Event Styling** (`assets/css/single-event.css`):
- **Card Treatment**: .event-info-grid receives background, border, border-radius, padding, box-shadow
- **Action Buttons Container**: .event-action-buttons flexbox container centers ticket and share buttons below event info
- **Flexbox Layout**: Side-by-side buttons with 1rem gap, stacks vertically on mobile (max-width: 768px)
- **CSS Custom Properties**: Uses theme variables (--background-color, --border-color, --card-shadow)
- **Responsive Design**: Mobile-optimized padding, border-radius, and button layout adjustments

**Calendar Styling** (`assets/css/calendar.css`):
- **Placeholder Status**: Minimal file for future calendar enhancements
- **Integration Ready**: Structure prepared for calendar-specific styling as needed

### CSS Integration Strategy
**Badge Enhancement Process**:
1. dm-events renders badge with default classes (dm-taxonomy-badges, dm-taxonomy-badge)
2. Integration filters add ExtraChill-compatible classes via dm_events_badge_wrapper_classes and dm_events_badge_classes
3. Theme's badge-colors.css automatically applies custom styling
4. Maintains dual compatibility (plugin + theme styles)

**CSS Class Hierarchy**:
```css
/* dm-events default wrapper with added theme class */
.dm-taxonomy-badges.taxonomy-badges .taxonomy-badge.festival-badge.festival-bonnaroo {
    /* Custom Bonnaroo festival colors from theme */
}

/* dm-events default badge with added theme classes */
.dm-taxonomy-badge.taxonomy-badge.location-badge.location-charleston {
    /* Custom Charleston location colors from theme */
}
```

### Integration Code Examples

**Badge Class Enhancement** (`inc/core/dm-events-integration.php`):
```php
// Add wrapper class for theme styling
add_filter('dm_events_badge_wrapper_classes', array($this, 'add_wrapper_classes'), 10, 2);
public function add_wrapper_classes($wrapper_classes, $post_id) {
    $wrapper_classes[] = 'taxonomy-badges';
    return $wrapper_classes;
}

// Add individual badge classes for festival/location styling
add_filter('dm_events_badge_classes', array($this, 'add_badge_classes'), 10, 4);
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

// Exclude venue and artist taxonomies from badge display
add_filter('dm_events_excluded_taxonomies', array($this, 'exclude_venue_taxonomy'));
public function exclude_venue_taxonomy($excluded) {
    $excluded[] = 'venue';   // Venue has 9 meta fields displayed separately
    $excluded[] = 'artist';  // Prevents redundant display with artist metadata
    return $excluded;
}
```

**Breadcrumb Override**:
```php
add_filter('dm_events_breadcrumbs', array($this, 'override_breadcrumbs'), 10, 2);
public function override_breadcrumbs($breadcrumbs, $post_id) {
    if (function_exists('display_breadcrumbs')) {
        ob_start();
        display_breadcrumbs();
        return ob_get_clean();
    }
    return $breadcrumbs;
}
```

**Related Events Display** (events.extrachill.com only):
```php
add_action('dm_events_related_events', array($this, 'display_related_events'), 10, 1);
public function display_related_events($event_id) {
    if (get_current_blog_id() !== 7) return;

    if (function_exists('extrachill_display_related_posts')) {
        extrachill_display_related_posts('festival', $event_id);
        extrachill_display_related_posts('venue', $event_id);
    }
}
```

**Theme Hook Bridging**:
```php
add_action('dm_events_before_single_event', array($this, 'before_single_event'));
public function before_single_event() {
    do_action('extrachill_before_body_content');
}

add_action('dm_events_after_single_event', array($this, 'after_single_event'));
public function after_single_event() {
    do_action('extrachill_after_body_content');
}
```

**Share Button Integration** (events.extrachill.com only):
```php
add_action('dm_events_action_buttons', array($this, 'render_share_button'), 10, 2);
public function render_share_button($post_id, $ticket_url) {
    // Only display on events.extrachill.com
    if (get_current_blog_id() !== 7) {
        return;
    }

    // Check if share button function exists
    if (!function_exists('extrachill_share_button')) {
        return;
    }

    // Share button with event URL and title, large size to match ticket button
    extrachill_share_button(array(
        'share_url' => get_permalink($post_id),
        'share_title' => get_the_title($post_id),
        'button_size' => 'button-large'
    ));
}
```

**Post Meta Hiding**:
```php
add_filter('extrachill_post_meta', array($this, 'hide_post_meta_for_events'), 10, 3);
public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
    if ($post_type === 'dm_events') {
        return '';
    }
    return $default_meta;
}
```

**CSS Integration**:
```php
// Enqueue single event styles
add_action('wp_enqueue_scripts', array($this, 'enqueue_single_post_styles'));
public function enqueue_single_post_styles() {
    if (!is_singular('dm_events')) {
        return;
    }
    // Enqueues theme's single-post.css and plugin's single-event.css
}

// Enqueue calendar styles for homepage
add_action('wp_enqueue_scripts', array($this, 'enqueue_calendar_styles'));
public function enqueue_calendar_styles() {
    if (get_current_blog_id() !== 7 || !is_front_page()) {
        return;
    }
    // Enqueues plugin's calendar.css
}
```

## Development Standards

### Code Organization
- **Direct Includes Pattern**: All classes loaded via manual `require_once` statements (no PSR-4 autoloading for plugin code)
- **Composer Autoload Pattern**: PSR-4 configuration exists in composer.json but is unused (historical artifact following platform-wide pattern documented in parent CLAUDE.md files)
- **Development Dependencies Only**: Composer autoloader ONLY used for PHPUnit and PHPCS
- **Single Responsibility**: Each integration class handles one specific plugin
- **WordPress Standards**: Full compliance with WordPress plugin development guidelines
- **Security Implementation**: Proper escaping, nonce verification, and input sanitization

### Build System
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Auto-Detection**: Script auto-detects plugin type from `Plugin Name:` header
- **Production Build**: Creates `/build/extrachill-events/` directory and `/build/extrachill-events.zip` file (non-versioned)
- **Version Extraction**: Automatically reads version from plugin header for validation
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Composer Integration**: Production builds use `composer install --no-dev`, restores dev dependencies after

## Dependencies

### PHP Requirements
- **PHP**: 7.4+
- **WordPress**: 5.0+
- **Composer**: For dependency management and autoloading

### Development Dependencies
- **PHP CodeSniffer**: WordPress coding standards compliance
- **PHPUnit**: Unit testing framework
- **WPCS**: WordPress Coding Standards ruleset

### Plugin Dependencies
- **Required for Full Functionality**: Data Machine and dm-events plugins (for events.extrachill.com)
- **Optional Integration**: DM Events plugin (auto-detected if active)
- **Theme Compatibility**: Works with any ExtraChill theme containing badge-colors.css
- **Extensible Architecture**: Ready for additional event plugin integrations

## Common Development Commands

### Building and Testing
```bash
# Install dependencies
composer install

# Create production build
./build.sh

# Run PHP linting
composer run lint:php

# Fix PHP coding standards
composer run lint:fix

# Run tests
composer run test
```

### Build Output
- **Production Package**: `/build/extrachill-events/` directory and `/build/extrachill-events.zip` file
- **File Exclusions**: Development files, vendor/, .git/, build tools
- **Structure Validation**: Ensures plugin integrity before packaging

## Integration Guidelines

### Adding New Event Plugin Support
1. **Create Integration Class**: New class in `inc/core/` following DmEventsIntegration pattern
2. **Detection Logic**: Add class existence check in `ExtraChillEvents::init_integrations()`
3. **Hook Integration**: Implement appropriate filter/action hooks for the target plugin
4. **Badge Mapping**: Map plugin's taxonomy structure to ExtraChill's badge classes
5. **Template Integration**: Add CSS enqueuing and post meta management as needed
6. **Testing**: Verify integration with both plugins active and inactive
7. **Documentation**: Update plugin compatibility documentation

### Theme Integration Requirements
- **Badge Colors CSS**: Theme must include badge-colors.css with festival/location styling
- **Breadcrumb Function**: Optional `display_breadcrumbs()` function for breadcrumb override
- **CSS Compatibility**: Ensure `.taxonomy-badges` and `.taxonomy-badge` base classes exist

## Current Limitations

### Supported Plugins
- **Current Support**: DM Events plugin with complete integration
- **Extensible Architecture**: Ready for additional event plugin integrations
- **Replacement Function**: Replaces previous dm-events integration from theme

### Integration Scope
- **Visual Integration Focus**: Primarily handles badge styling and breadcrumb consistency
- **Theme Dependent**: Requires ExtraChill themes with appropriate CSS structure
- **Flexible Mapping**: Extensible taxonomy mapping system for various event plugins
- **Non-Intrusive**: Enhances plugins without modifying core functionality

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com