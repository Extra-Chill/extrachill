# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.6.1] - 2026-03-18

### Fixed
- rename --url to --events-url to avoid WP-CLI --url conflict

## [0.6.0] - 2026-03-18

### Added
- add subcommand for venues CLI

## [0.5.0] - 2026-03-18

### Added
- add qualify subcommand to venues CLI

## [0.4.0] - 2026-03-18

### Added
- add venue discovery CLI command
- add move-link command for reordering links within/across sections
- add save-styles and save-settings CLI commands for artist link pages
- add save-links, add-link, and remove-link CLI commands for artist link pages

## [0.3.0] - 2026-03-17

### Added
- add artist CLI commands wrapping abilities

### Changed
- add fuzzy title matching to redirects import command

## [0.2.3] - 2026-03-17

### Changed
- add build/ to gitignore
- add NetworkAwareTrait for multisite-aware CLI commands

### Fixed
- fix PHPCS lint issues across all command files

## [0.2.2] - 2026-03-16

### Added
- add `wp extrachill events audit-locations` and `wp extrachill events fix-locations` wrappers for event location reconciliation
- add `homeboy.json` component config for clean Homeboy registration and deployment

### Changed
- add event location reconciliation CLI commands

## [0.2.1] - 2026-03-13

### Added
- Add QR code generation command under wp extrachill tools qr.

### Changed
- Support moderation policies in user CLI
- Add user ban commands to extrachill CLI
- Add wp extrachill analytics summary command

## [0.2.0] - 2026-03-05

### Added
- Add `wp extrachill tools qr generate` command for QR PNG generation from URLs.
- Register new `extrachill tools qr` command namespace.
- Document QR CLI usage in README.

## [0.1.0] - 2026-03-03

### Added
- Initial release with `wp extrachill analytics 404` command group.
- Added `wp extrachill seo redirects` command group.
- Added WP-CLI plugin bootstrap and command registration architecture.
