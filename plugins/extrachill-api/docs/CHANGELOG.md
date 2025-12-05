# Changelog

All notable changes to the ExtraChill API plugin are documented here. This file is the single source of truth for release history.

## 0.1.3

### Added
- Analytics link-click tracking endpoint (`POST /extrachill/v1/analytics/link-click`) for tracking artist link page clicks with URL normalization
- Artist subscription endpoint (`POST /extrachill/v1/artist/subscribe`) for handling artist update subscriptions

## 0.1.2

### Added
- QR code generator endpoint (`POST /extrachill/v1/tools/qr-code`) moved from extrachill-admin-tools plugin
- Analytics view count tracking endpoint
- Artist platform permissions endpoint
- Band name and rapper name generation endpoints for blocks
- Community image upload and upvote endpoints
- User avatar upload endpoint
- Enhanced event submissions with auto-population for logged-in users and AI vision support
- Comprehensive AGENTS.md documentation file (renamed from CLAUDE.md)

### Changed
- Event submissions now auto-populate user data when logged in, reducing form requirements
- Newsletter subscriptions simplified by removing Turnstile verification requirement
- Updated README.md documentation links to reference AGENTS.md

### Dependencies
- Added `endroid/qr-code: ^6.0` for QR code generation

## 0.1.1

### Added
- `POST /extrachill/v1/event-submissions` endpoint that validates Cloudflare Turnstile tokens, stores optional flyers via Data Machine file storage, and queues the configured flow.
- `extrachill_event_submission` action hook so downstream consumers can react to queued jobs (notifications, analytics, moderation).
- README/CLAUDE documentation describing the new endpoint and hook.

## 0.1.0

### Added
- Initial release with automatic route discovery, community user search, and blocks endpoints (image voting + AI adventure).
