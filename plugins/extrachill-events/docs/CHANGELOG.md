# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0/).

## [0.2.0] - 2025-12-14

### Added
- Weekly roundup automation handlers for Data Machine integration (SlideGenerator, WeeklyRoundupHandler, RoundupPublishHandler, RoundupPublishSettings) - enables automated post generation with carousel images
- Comprehensive AGENTS.md documentation file with plugin architecture, features, and integration details
- API documentation for REST endpoints (`docs/api/rest-endpoints.md`)
- Event submission block documentation (`docs/blocks/event-submission.md`)

### Changed
- Updated README.md with improved feature descriptions, build commands, and development workflow
- Enhanced documentation for build process, plugin classes, functions, and constants
- Updated .gitignore to remove CLAUDE.md, AGENTS.md, build.sh, docs/.docs-sync.json

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
