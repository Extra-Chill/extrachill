# ExtraChill Events

WordPress plugin providing seamless integration between Extra Chill and Data Machine Events. 

This plugin is part of the Extra Chill Platform, a WordPress multisite network serving music communities across 9 active sites.

## Plugin Information

- **Name**: ExtraChill Events
- **Version**: 0.2.8
- **Text Domain**: `extrachill-events`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4
- **Network**: false
- **Requires Plugins**: datamachine, datamachine-events

## events.extrachill.com Integration

This plugin provides homepage template override for **events.extrachill.com** (site #7 in the multisite network), creating a dedicated calendar-focused event hub powered by Data Machine and datamachine-events.

### Homepage Content Rendering Architecture

**Content Rendering Pattern**:
- Uses `extrachill_homepage_content` action from theme's homepage system
- Dynamic blog ID detection using `ec_get_blog_id('events')` for maintainability
- Blog ID comparison ensures rendering only applies to events.extrachill.com
- Includes plugin template for complete homepage content control

**Implementation**:
```php
add_action( 'extrachill_homepage_content', 'ec_events_render_homepage' );

function ec_events_render_homepage() {
    include EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/homepage.php';
}
```

### Data Machine Calendar Integration

**Homepage Template** (`inc/templates/homepage.php`):
- Displays content from WordPress static homepage (Settings → Reading → "A static page")
- Renders homepage post content via `apply_filters('the_content', $homepage->post_content)`
- Allows adding datamachine-events calendar block (or any blocks) via WordPress editor
- Full-width container with `get_header()` and `get_footer()` for complete page control
- DM Events calendar block handles all filtering, pagination, and event display logic

**Setup Process**:
1. Create a page in WordPress admin (e.g., "Events Calendar")
2. Add datamachine-events calendar block to the page via block editor
3. Set as static homepage: Settings → Reading → "A static page" → Front page: "Events Calendar"

**Required Plugins** (site-activated on events.extrachill.com):
- **Data Machine** - Event automation and content pipeline
- **datamachine-events** - Calendar block and event post type
- **extrachill-events** - Homepage template override (this plugin)

### Multisite Network Architecture

**events.extrachill.com Setup**:
1. Create site in Network Admin → Sites → Add New
2. Site URL: events.extrachill.com
3. Site Title: "ExtraChill Events"
4. Activate Data Machine, datamachine-events, and extrachill-events on the new site

**Data Flow**:
- Data Machine handles event import and automation on events.extrachill.com
- datamachine-events calendar block provides full event display and filtering
- Main site (extrachill.com) focuses on content automation without event processing
- Events site serves as centralized event hub for entire network

**Benefits**:
- Clean separation: main site for content, events site for calendar
- Centralized event management via Data Machine pipelines
- Single source of truth for all network events
- Optimized performance: no event pipeline complexity on main site

## Architecture

### Plugin Loading Pattern
- **Direct `require_once` Pattern**: All plugin classes loaded via manual `require_once` statements (NO PSR-4 autoloading)
- **Class-Based Structure**: Object-oriented plugin architecture with singleton pattern
- **Composer Autoloader**: ONLY for development dependencies (PHPUnit, PHPCS)
- **Manual Includes**: Integration classes loaded via direct `require_once` in main plugin file
- **Textdomain Loading**: Loaded on 'init' hook with proper path handling via `load_plugin_textdomain('extrachill-events', false, dirname(EXTRACHILL_EVENTS_PLUGIN_BASENAME) . '/languages')`
- **Handler Initialization**: `init_datamachine_handlers()` public method registered on 'init' hook (priority 20) for conditional weekly roundup handler loading

### Core Classes
- **ExtraChillEvents** (`extrachill-events.php`) - Main singleton class managing initialization, template overrides, SEO redirects, and handler initialization via public `init_datamachine_handlers()` method
- **DataMachineEventsIntegration** (`inc/core/datamachine-events-integration.php`) - datamachine-events integration with badge/button styling, CSS enqueuing, post meta management, and dynamic taxonomy registration via `registered_post_type` and `registered_taxonomy` hooks

## Key Features

### Homepage Content Rendering
**events.extrachill.com Integration**:
- **Blog ID Targeting**: Only applies to events.extrachill.com using `ec_get_blog_id('events')`
- **Static Page Display**: Renders content from Settings → Reading → "A static page" with full block editor support
- **Calendar Block Support**: Supports datamachine-events calendar block and any other blocks via WordPress editor
- **Breadcrumb Integration**: Displays theme breadcrumbs via `extrachill_breadcrumbs()` function

### Archive Template Override
**Unified Archive System**:
- **Single Template Approach**: One template handles all archive types (taxonomy, post type, date, author)
- **Calendar Block Rendering**: Displays datamachine-events calendar block via `do_blocks('<!-- wp:datamachine-events/calendar /-->')`
- **Context-Aware Filtering**: Calendar block automatically detects archive context and filters events accordingly
- **Taxonomy Archives**: Handles festival, venue, and location taxonomy archives
- **Post Type Archives**: Manages datamachine_events post type archive (redirects to homepage)
- **SEO Optimization**: /events/ post type archive redirects to homepage (301 permanent)

### Event Submission Block
**Frontend Event Collection**:
- **Block Registration**: `extrachill/event-submission` block with configurable attributes
- **Form Fields**: Contact info (conditional), event details, flyer upload, Cloudflare Turnstile
- **REST API Integration**: Submissions routed to `/wp-json/extrachill/v1/event-submissions` endpoint
- **Data Machine Flows**: Processed through specified flow IDs for automated handling
- **User Experience**: Responsive design with conditional fields and real-time validation

### Navigation Integration
**Secondary Header Enhancement**:
- **Submit Event Link**: Added via `extrachill_secondary_header_items` filter
- **Dynamic Routing**: Links to `/submit/` page for event submissions
- **Internationalization**: Translatable link text with priority-based ordering

### Weekly Roundup Automation
**Automated Instagram Carousel Generation**:
- **Two-Step Pipeline**: Fetch handler generates images, Publish handler creates posts
- **WeeklyRoundupHandler**: Queries events by date range and location, generates carousel images
- **RoundupPublishHandler**: Creates WordPress posts with uploaded images and captions
- **SlideGenerator**: GD-based image rendering (1080x1350px Instagram format)
- **Configuration**: Week start/end days, location filter, optional title
- **Image Generation**: Automatic slide distribution, weekday color coding, event grouping
- **Engine Data Flow**: Images and metadata passed between fetch and publish steps
- **Post Creation**: Images uploaded to media library, rendered as blocks in post content
- **Data Machine Integration**: Registered as fetch and publish handlers on plugin initialization
- **Documentation**: See `/docs/handlers/weekly-roundup.md` for complete handler reference

### Build Process
**NPM-Based Compilation**:
- **@wordpress/scripts**: Standardized block development tooling (v27.1.0)
- **Build Scripts**: `npm run build` compiles blocks to production assets
- **File Structure**: Source files in `blocks/event-submission/src/`, output to `build/event-submission/`
- **Production Integration**: `./build.sh` creates optimized ZIP with compiled blocks

### DM Events Integration
**Complete Integration Implementation**:
- **Badge Styling Integration**: Maps datamachine-events taxonomy badges to ExtraChill's badge class structure via datamachine_events_badge_wrapper_classes and datamachine_events_badge_classes filters
- **Festival-Specific Colors**: Enables custom festival colors (Bonnaroo, Coachella) from theme's badge-colors.css
- **Location Styling**: Converts venue taxonomies to location styling for regional color coding
- **Taxonomy Exclusion**: Excludes artist taxonomy from badge display via datamachine_events_excluded_taxonomies filter (prevents redundant display)
- **Button Styling Integration**: Maps datamachine-events modal, ticket, and more info buttons to theme's button styling classes
- **Share Button Integration**: Renders share button alongside ticket button in flexbox container via datamachine_events_action_buttons hook (events.extrachill.com only)
- **Breadcrumb Override**: Replaces datamachine-events breadcrumbs with theme's breadcrumb system via datamachine_events_breadcrumbs filter
- **Related Events Display**: Shows related events by festival and venue taxonomies using theme's related posts function
- **CSS Integration**: Enqueues theme's single-post.css, sidebar.css, and plugin's single-event.css for event pages
- **Post Meta Management**: Hides post meta display for datamachine_events post type
- **Promoter Badge Skipping**: Skips promoter badges when promoter name matches venue name

### Taxonomy Mapping System
**Badge Class Structure**:
- **festival** → `taxonomy-badge festival-badge festival-{slug}` (e.g., `festival-bonnaroo`)
- **location** → `taxonomy-badge location-badge location-{slug}` (e.g., `location-charleston`)
- **other taxonomies** → Uses plugin's default styling with base `taxonomy-badge` class

**Integration Hooks**:
- **datamachine_events_badge_wrapper_classes** - Adds `taxonomy-badges` wrapper class for theme styling
- **datamachine_events_badge_classes** - Adds `taxonomy-badge` base class and festival/location/venue-specific classes
- **datamachine_events_excluded_taxonomies** - Excludes artist taxonomy from badge display
- **datamachine_events_modal_button_classes** - Adds theme button classes to modal buttons (primary/secondary)
- **datamachine_events_ticket_button_classes** - Adds theme button classes to ticket purchase button
- **datamachine_events_more_info_button_classes** - Adds theme button classes to more info buttons
- **extrachill_post_meta** - Hides post meta for datamachine_events post type
- **extrachill_taxonomy_badges_skip_term** - Skips promoter badges matching venue name

### Single Event Template Integration
**Integration Flow**:
1. **Badge Enhancement**: datamachine-events renders badges → extrachill-events filters add theme classes → theme CSS applies styling
2. **Breadcrumb Override**: datamachine-events calls breadcrumb filter → extrachill-events returns theme breadcrumbs via `ec_events_override_breadcrumbs()`
3. **Share Button Integration**: datamachine-events fires action buttons hook → extrachill-events renders share button with `button-large` styling (events.extrachill.com only)
4. **Related Events**: datamachine-events fires related events hook → extrachill-events displays related events by festival/venue taxonomy
5. **CSS Integration**: Plugin enqueues theme styles (single-post.css, sidebar.css) and plugin styles (single-event.css)
6. **Post Meta Management**: Hides post meta for datamachine_events post type via `extrachill_post_meta` filter
7. **Promoter Badge Skipping**: Skips promoter badges when promoter name matches venue name

### Template Override System
**Homepage Override** (events.extrachill.com only):
- **Filter Hook**: `extrachill_template_homepage` (theme's universal routing system)
- **Blog ID Check**: Only applies to blog ID 7
- **Content Display**: Renders static page content from Settings → Reading → "A static page"
- **Implementation**: `ec_events_override_homepage_template()` function

**Archive Override** (events.extrachill.com only):
- **Filter Hook**: `extrachill_template_archive` (all archive types)
- **Unified Template**: Handles taxonomy, post type, date, and author archives
- **Calendar Rendering**: Displays calendar via `do_blocks('<!-- wp:datamachine-events/calendar /-->')`
- **SEO Redirect**: `/events/` post type archive redirects to homepage (301 permanent)
- **Implementation**: `ec_events_override_archive_template()` and `ec_events_redirect_post_type_archive()` functions

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

**Breadcrumb Root**:
- **Site-Specific**: Only applies to events.extrachill.com (blog ID 7)
- **Homepage**: "Extra Chill" (links to extrachill.com)
- **Other Pages**: "Extra Chill → Events" (Events links to homepage)
- **Implementation**: `ec_events_breadcrumb_root()` filters `extrachill_breadcrumbs_root`

**Breadcrumb Trails**:
- **Homepage**: "Extra Chill › Events"
- **Taxonomy Archives**: "Extra Chill › Events › [Term Name]"
- **Post Type Archives**: "Extra Chill › Events"
- **Single Events**: "Extra Chill › Events › [Event Title]"
- **Implementation**: `ec_events_breadcrumb_trail_homepage()`, `ec_events_breadcrumb_trail_archives()`, `ec_events_breadcrumb_trail_single()` filter `extrachill_breadcrumbs_override_trail`

**Back-to-Home Label**:
- **Event Pages**: "← Back to Events"
- **Homepage**: "← Back to Extra Chill" (links to main site)
- **Implementation**: `ec_events_back_to_home_label()` filters `extrachill_back_to_home_label`

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

**Asset Files**:
- **Location**: `assets/css/`
- **calendar.css** - Homepage calendar enhancements (minimal placeholder)
- **single-event.css** - Event info grid card treatment and action buttons flexbox container

**Conditional Loading**:
- **Single Events**: Loads theme's single-post.css, sidebar.css, and plugin's single-event.css via `enqueue_single_post_styles()`
- **Homepage Calendar**: Loads calendar.css on events.extrachill.com homepage via `enqueue_calendar_styles()`
- **Cache Busting**: All styles use `filemtime()` for automatic cache invalidation
- **Dependencies**: Plugin styles depend on `extrachill-style` for CSS custom properties

**Single Event Styling**:
- **.event-info-grid**: Card treatment with background, border, border-radius, padding, box-shadow
- **.event-action-buttons**: Flexbox container for ticket and share buttons with 1rem gap
- **Responsive**: Side-by-side buttons on desktop, stacked on mobile (max-width: 768px)
- **CSS Custom Properties**: Uses theme variables (--background-color, --border-color, --card-shadow)

### CSS Integration Strategy
**Badge Enhancement Process**:
1. datamachine-events renders badge with default classes (dm-taxonomy-badges, dm-taxonomy-badge)
2. Integration filters add ExtraChill-compatible classes via datamachine_events_badge_wrapper_classes and datamachine_events_badge_classes
3. Theme's badge-colors.css automatically applies custom styling
4. Maintains dual compatibility (plugin + theme styles)

**CSS Class Hierarchy**:
```css
/* datamachine-events default wrapper with added theme class */
.datamachine-taxonomy-badges.taxonomy-badges .taxonomy-badge.festival-badge.festival-bonnaroo {
    /* Custom Bonnaroo festival colors from theme */
}

/* datamachine-events default badge with added theme classes */
.datamachine-taxonomy-badge.taxonomy-badge.location-badge.location-charleston {
    /* Custom Charleston location colors from theme */
}
```

### Integration Code Examples

**Badge Class Enhancement**:
```php
// Add wrapper class for theme styling
public function add_wrapper_classes($wrapper_classes, $post_id) {
    $wrapper_classes[] = 'taxonomy-badges';
    return $wrapper_classes;
}

// Add individual badge classes for festival/location/venue styling
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
        case 'venue':
            $badge_classes[] = 'venue-badge';
            $badge_classes[] = 'venue-' . esc_attr($term->slug);
            break;
    }

    return $badge_classes;
}

// Exclude venue and artist taxonomies from badge display
public function exclude_taxonomies($excluded) {
    $excluded[] = 'venue';
    $excluded[] = 'artist';
    return $excluded;
}
```

**Breadcrumb Override**:
```php
public function override_breadcrumbs($breadcrumbs, $post_id) {
    if (function_exists('display_breadcrumbs')) {
        ob_start();
        display_breadcrumbs();
        return ob_get_clean();
    }
    return $breadcrumbs;
}
```

**Related Events Display**:
```php
public function display_related_events($event_id) {
    if (get_current_blog_id() !== 7) {
        return;
    }

    if (function_exists('extrachill_display_related_posts')) {
        extrachill_display_related_posts('festival', $event_id);
        extrachill_display_related_posts('venue', $event_id);
    }
}
```

**Theme Hook Bridging**:
```php
public function before_single_event() {
    do_action('extrachill_before_body_content');
}

public function after_single_event() {
    do_action('extrachill_after_body_content');
}
```

**Share Button Integration**:
```php
public function render_share_button($post_id, $ticket_url) {
    if (get_current_blog_id() !== 7) {
        return;
    }

    if (!function_exists('extrachill_share_button')) {
        return;
    }

    extrachill_share_button(array(
        'share_url' => get_permalink($post_id),
        'share_title' => get_the_title($post_id),
        'button_size' => 'button-large'
    ));
}
```

**Post Meta Hiding**:
```php
public function hide_post_meta_for_events($default_meta, $post_id, $post_type) {
    if ($post_type === 'datamachine_events') {
        return '';
    }
    return $default_meta;
}
```

## Development Standards

### Code Organization
- **Direct Includes Pattern**: All classes loaded via manual `require_once` statements (NO PSR-4 autoloading)
- **Development Dependencies Only**: Composer autoloader ONLY for PHPUnit and PHPCS
- **Single Responsibility**: Each integration class handles one specific plugin
- **WordPress Standards**: Full compliance with WordPress plugin development guidelines
- **Security Implementation**: Proper escaping, nonce verification, and input sanitization

### Build System
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Auto-Detection**: Script auto-detects plugin type from `Plugin Name:` header
- **Production Build**: Creates `/build/extrachill-events.zip` file (non-versioned; unzip when directory access is needed)
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
- **Required Plugins**: Data Machine, datamachine-events (enforced via WordPress native plugin dependency system)
- **Theme Requirements**: ExtraChill theme with badge-colors.css, breadcrumb functions, share button function
- **Auto-Detection**: DataMachineEventsIntegration loads conditionally if datamachine-events is active
- **Extensible**: Ready for additional event plugin integrations

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
- **Production Package**: `/build/extrachill-events.zip` file (unzip when directory access is needed)
- **File Exclusions**: Development files, vendor/, .git/, build tools
- **Structure Validation**: Ensures plugin integrity before packaging

## Integration Guidelines

### Adding New Event Plugin Support
1. **Create Integration Class**: New class in `inc/core/` following DataMachineEventsIntegration pattern
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
- **Replacement Function**: Replaces previous datamachine-events integration from theme

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