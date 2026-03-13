# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
