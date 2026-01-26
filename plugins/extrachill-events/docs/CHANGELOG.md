# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [0.5.2] - 2026-01-26

### Changed
- Style Farm Friends promo as taxonomy badge

## [0.5.1] - 2026-01-26

### Fixed
- "More at Venue" section not displaying on single event posts (added venue to allowed taxonomies)

## [0.5.0] - 2026-01-26

### Changed
- Add priority events feature with 3-tier sorting hierarchy

## [0.4.3] - 2026-01-25

- Style updates for event grid text display

## [0.4.2] - 2026-01-25

### Added
- Farm Friends membership promo for Music Farm events

### Refactored
- Modularize DataMachine Events integration into single-responsibility files

## [0.4.1] - 2026-01-24

### Fixed
- Add missing category property to WP Abilities API registration for 5 abilities

## [0.3.3] - 2026-01-10

### Added
- Event submission block `systemPrompt` attribute with editor UI, server `data-system-prompt` output, and frontend submission wiring (`blocks/event-submission/block.json`, `blocks/event-submission/src/edit.js`, `blocks/event-submission/render.php`, `blocks/event-submission/view.js`)

### Changed
- Event submission block no longer requires a Flow ID to submit; `flow_id` is only sent when configured (`blocks/event-submission/render.php`, `blocks/event-submission/src/edit.js`, `blocks/event-submission/view.js`)
- Reorganized block inspector controls into dedicated panels (Submission Settings, AI Processing, Advanced) and removed missing Flow ID warnings (`blocks/event-submission/src/edit.js`, `blocks/event-submission/render.php`, `blocks/event-submission/view.js`)

## [0.3.2] - 2026-01-08

### Added
- Homepage location badges system (`inc/home/actions.php`, `inc/home/location-badges.php`) displaying location taxonomy badges with upcoming event counts above the calendar
- `extrachill_events_home_before_calendar` action hook in homepage template for flexible content injection before the calendar block
- Hook-based homepage component registration via `extrachill_events_location_badges()` callback

### Changed
- WeeklyRoundupHandler refactored to ExecutionContext API pattern:
  - `executeFetch()` method signature updated from `(int $pipeline_id, array $config, ?string $flow_step_id, int $flow_id, ?string $job_id)` to `(array $config, ExecutionContext $context)`
  - Replaced `$this->log()` calls with `$context->log()`
  - Replaced manual storage context building with `$context->getFileContext()`
  - Replaced `$this->store_engine_data()` with `$context->storeEngineData()`

### Removed
- Private `store_engine_data()` method from WeeklyRoundupHandler (replaced by ExecutionContext API)

## [0.3.1] - 2026-01-06

### Added
- Added `extrachill_archive_below_description` action hook to the unified archive template for flexible content injection below the header.

## [0.3.0] - 2026-01-05

### Added
- Enhanced Venue and Promoter taxonomy archive headers with dynamic metadata (address, website URL)
- Custom styling for taxonomy archive headers in `calendar.css`

### Changed
- Refactored archive template to support detailed venue and promoter metadata display
- Expanded `calendar.css` with structured layout for taxonomy headers, descriptions, and meta information
- Updated calendar style enqueuing to include taxonomy archive pages on the events site

## [0.2.9] - 2026-01-04

### Added
- Schema breadcrumb integration (`ec_events_schema_breadcrumb_items`) to align SEO metadata with visual breadcrumbs on events.extrachill.com

### Changed
- Updated network scale documentation to reflect 11 active sites in the Extra Chill Platform
- Improved "Back to Events Calendar" label consistency across visual and schema breadcrumbs

## [0.2.8] - 2025-12-30

### Fixed
- Fixed character encoding in breadcrumb back-to-home label ("← Back to Events Calendar")

### Changed
- Refined documentation in CLAUDE.md and README.md regarding build process output and production ZIP structure
- Updated version information across documentation files to reflect 0.2.8

## [0.2.7] - 2025-12-21

### Added
- Venue taxonomy support in badge styling system (venue-badge, venue-{slug} classes)
- Comprehensive weekly roundup handler documentation (`docs/handlers/weekly-roundup.md`)
- Dynamic taxonomy menu item filtering via `allow_event_taxonomy_menu_items()` filter

### Changed
- Taxonomy registration refactored from simple initialization to hook-based system with proper timing
  - `on_registered_post_type()` - Registers taxonomies when datamachine_events post type registers
  - `on_registered_taxonomy()` - Ensures taxonomies register when event taxonomies initialize
  - `register_event_taxonomies()` changed from private to public for external use
  - `get_event_taxonomies()` centralized taxonomy list method (location, artist, festival, venue)
- Code formatting and namespace improvements for better WordPress standards compliance
- Documentation expanded across CLAUDE.md, README.md, and integration guides

## [0.2.6] - 2025-12-20

### Added
- Taxonomy registration for datamachine_events post type (location, artist, festival, venue) with dynamic hooks (`registered_post_type`, `registered_taxonomy`, `init`)
- Added EXTRACHILL_EVENTS_PLUGIN_BASENAME constant for proper textdomain path handling
- Added `init_datamachine_handlers()` public method for flexible handler initialization

### Changed
- **Textdomain Loading**: Moved from 'plugins_loaded' to 'init' hook with proper path handling via `dirname(EXTRACHILL_EVENTS_PLUGIN_BASENAME)`
- **Text Domain**: Updated to use 'extrachill-events' (was 'datamachine-events') for proper translation loading
- **Taxonomy Registration**: Refactored from static registration to dynamic hooks ensuring proper timing and flexibility
  - Changed from private to public method `register_event_taxonomies()`
  - Added `on_registered_post_type()` hook handler
  - Added `on_registered_taxonomy()` hook handler
  - Added `get_event_taxonomies()` centralized list (location, artist, festival, venue)
- **Handler Initialization**: Changed from constructor call to public `init_datamachine_handlers()` method on 'init' hook (priority 20) for conditional loading
- **Badge Classes**: Added venue taxonomy support (venue-badge, venue-{slug}) for venue-specific styling

## [0.2.5] - 2025-12-17

### Changed
- Modified archive template to use WordPress `single_term_title()` instead of DataMachineEvents `Archive_Title` class for taxonomy archive titles, maintaining "Live Music Calendar" branding

## [0.2.4] - 2025-12-17

### Changed
- Rebranded "Events Calendar" to "Live Music Calendar" across homepage and archive titles
- Added archive title display on taxonomy archive pages using DataMachineEvents Archive_Title class
- Added datamachine_events_archive_title filter to customize archive titles with "Live Music Calendar" branding

## [0.2.3] - 2025-12-16

### Added
- Slide title support with accent underline on first weekly roundup slide
- Day-specific color coding for slide headers (unique colors for each day of week)
- Automatic text wrapping for event titles and slide titles to prevent overflow
- Time-based event sorting within daily slides
- Title configuration field in WeeklyRoundupSettings

### Changed
- Enhanced slide height calculations to account for wrapped text and titles
- Improved event metadata display with bullet separators instead of dashes
- Refined slide layout spacing and typography hierarchy

## [0.2.2] - 2025-12-16

### Changed
- WeeklyRoundupHandler: Removed unused DataPacket import and dependency
- Simplified executeFetch return logic by removing $this->emptyResponse() and $this->successResponse() calls, returning arrays directly
- Changed response structure from DataPacket to direct array with 'content' instead of 'body'
- Updated array syntax from array() to [] for consistency

## [0.2.1] - 2025-12-16

### Added
- Dynamic weekday-based date ranges for weekly roundup automation
- `resolve_next_weekday_range()` method for automatic date calculation

### Changed
- WeeklyRoundupSettings: Replaced date pickers with weekday select dropdowns
- Improved location options query to filter by actual upcoming events
- Added fully qualified class names for better namespace compliance
- Enhanced configuration validation and sanitization

### Removed
- `date_range_start` and `date_range_end` configuration fields (replaced with `week_start_day` and `week_end_day`)

## [0.2.0] - 2025-12-14

### Added
- Weekly roundup automation handlers for Data Machine integration (SlideGenerator, WeeklyRoundupHandler, RoundupPublishHandler, RoundupPublishSettings) - enables automated post generation with carousel images
- Comprehensive CLAUDE.md documentation file with plugin architecture, features, and integration details
- API documentation for REST endpoints (`docs/api/rest-endpoints.md`)
- Event submission block documentation (`docs/blocks/event-submission.md`)

### Changed
- Updated README.md with improved feature descriptions, build commands, and development workflow
- Enhanced documentation for build process, plugin classes, functions, and constants
- Updated .gitignore to remove CLAUDE.md, CLAUDE.md, build.sh, docs/.docs-sync.json

## [0.1.7] - 2025-12-11

### Changed
- Replaced hardcoded blog ID 7 with ec_get_blog_id('events') for better multisite maintainability
- Updated breadcrumb labels to "Events Calendar" for improved user clarity
- Improved breadcrumb system with dynamic site URLs and network dropdown integration
- Updated event submission form CSS to use theme variables instead of hardcoded values
- Removed unnecessary blog ID checks in related events filter for broader compatibility

## [0.1.6] - 2025-12-05

### Added
- Added navigation integration with "Submit Event" link in secondary header
- Added promoter badge skipping when promoter name matches venue name

### Changed
- Improved event submission form error handling
- Converted homepage rendering from template override to content hook
- Moved share.css enqueue responsibility to theme

### Removed
- Removed taxonomy dependencies configuration
- Removed duplicate CLAUDE.md documentation file

## [0.1.5] - 2025-12-02

### Added
- Added block registration function for event-submission block from build directory
- Added user info display in event submission form for logged-in users

### Changed
- Migrated related events icons from FontAwesome to theme's ec_icon() system
- Enhanced event submission form UX by conditionally showing contact fields only for non-logged-in users
- Restructured event submission form grid layout

### Removed
- Removed FontAwesome-specific CSS rules for related events icons

## [0.1.4] - 2025-11-30

### Added
- Added Event Submission Block (`extrachill/event-submission`) for frontend event submissions routed through Data Machine flows
- Added block editor interface with configurable headline, description, Flow ID, success message, and button label
- Added frontend form with fields: contact name, email, event title, date, time, venue, city, lineup, ticket link, additional details, and flyer upload
- Added Cloudflare Turnstile integration for spam protection via `ec_enqueue_turnstile_script()` and `ec_render_turnstile_widget()` functions
- Added REST API submission handler with FormData support for file uploads
- Added package.json with `@wordpress/scripts` build tooling for block compilation
- Added responsive form styling using CSS custom properties from theme

## [0.1.3] - 2025-11-29

### Added
- Added taxonomy dependency configuration for cascading venue/location filters in calendar block
- Added proper semantic HTML5 structure to homepage template with article and header elements
- Added contextual prepositions to related events headers ("More at" for venues, "More in" for locations)

### Changed
- Refactored related events CSS to use theme's existing grid layout instead of custom implementation
- Updated homepage template to include proper page title and improved content hierarchy
- Improved date/time handling in related events to use WordPress timezone settings
- Enhanced event data field consistency by using `startDate`/`startTime` schema
- Simplified CSS by leveraging theme's existing `related-tax-grid` and `related-tax-card` classes

### Fixed
- Fixed date parsing in related events to properly handle timezone conversion
- Improved button positioning in related events cards for better visual hierarchy

## [0.1.2] - 2025-11-26

### Added
- Added `assets/css/related-events.css` for dedicated related events styling.
- Added taxonomy badges to related events cards using `Taxonomy_Badges::render_taxonomy_badges`.
- Added "More Info" button to related events cards.
- Added date and time icons to related events metadata.

### Changed
- Refactored related events HTML structure to use `ec-related-event-card` components.
- Updated related events thumbnail size from `medium` to `medium_large`.
- Changed related events date formatting to separate date (`D, M j, Y`) and time (`g:i A`).
- Moved asset enqueue logic from theme to plugin in `inc/single-event/related-events.php`.

## [0.1.1] - 2025-11-26

### Added
- Added `inc/single-event/related-events.php` to handle related events display.
- Integrated with `extrachill` theme's `extrachill_override_related_posts_display` hook to replace default related posts with `datamachine-events` calendar cards.
- Added logic to exclude the current event's venue from the related events query to encourage discovery.

## [0.1.0] - 2023-10-25

### Added
- Initial release.
- Integration with `datamachine-events` plugin.
- Homepage and Archive template overrides for events subdomain.
- Breadcrumb system.
- Share button functionality.
