# Changelog

All notable changes to extrachill-cache are documented here.

## [0.3.6] - 2026-09-23

### Fixed
- purge the page cache on code changes and add a purge CLI

## [0.3.5] - 2026-09-22

### Fixed
- grant id-token so the shared release workflow can start

## [0.3.4] - 2026-09-21

### Fixed
- regenerate the drop-in when the plugin version changes

## [0.3.3] - 2026-09-21

### Changed
- adopt the shared Homeboy release train

### Fixed
- partition sunrise domain aliases and detect stale host maps
- add domain-authorized cache purges
- preserve safe browser policy headers
- purge programmatic post changes

## [0.3.2] - 2026-07-18

### Fixed
- ignore generated release artifacts
- exclude standalone harness from PHPUnit discovery
- skip purges for private post transitions

## [0.3.1] - 2026-07-12

### Fixed
- bound page cache growth

## [0.3.0] - 2026-07-10

### Added
- add garbage collection for expired page cache files

## [0.2.0] - 2026-07-05

### Added
- build lean full-page cache plugin to replace Breeze

### Changed
- satisfy PHPCS and PHPStan on the cache drop-in installer
