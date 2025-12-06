# Changelog

All notable changes to the ExtraChill API plugin are documented here. This file is the single source of truth for release history.

## 0.1.5

### Added
- **Artist API Endpoints**: Complete artist data management system
  - `GET/PUT /wp-json/extrachill/v1/artists/{id}` - Core artist profile data (name, bio, images, link_page_id)
  - `GET/PUT /wp-json/extrachill/v1/artists/{id}/socials` - Social media links management
  - `GET/PUT /wp-json/extrachill/v1/artists/{id}/links` - Link page presentation data (links, CSS variables, settings)
- **Unified Media Upload Endpoint**: `POST/DELETE /wp-json/extrachill/v1/media`
  - Centralized image upload and management for all platform contexts
  - Supports user avatars, artist profiles, link pages, and content embeds
  - Automatic old image cleanup and permission-based access control
  - File validation (JPG, PNG, GIF, WebP; max 5MB)
- Comprehensive API documentation in AGENTS.md for all new endpoints

### Removed
- Legacy `inc/routes/community/upload-image.php` endpoint (replaced by unified media endpoint)
- Legacy `inc/routes/users/avatar-upload.php` endpoint (replaced by unified media endpoint)

### Changed
- Updated roadmap to reflect completed artist API implementation

## 0.1.4

### Added
- Artist roster management endpoints (`POST /extrachill/v1/artist/roster/invite`, `GET /extrachill/v1/artist/subscribers`, `GET /extrachill/v1/artist/subscribers/export`) for managing artist team members and subscribers
- Chat functionality endpoints (`DELETE /extrachill/v1/chat/history`, `POST /extrachill/v1/chat/message`) for AI chat interactions
- Analytics link-page tracking endpoint (`POST /extrachill/v1/analytics/link-page`) for authenticated page analytics
- Comprehensive user documentation under `docs/` covering all API endpoints and components

### Changed
- **BREAKING**: Standardized API response contract across all endpoints
  - Removed `success: true` wrappers from all responses
  - Fire-and-forget endpoints now use semantic keys (`tracked`, `recorded`)
  - Flattened nested `data` wrapper in `artist/permissions` endpoint
  - Errors now use `WP_Error` exclusively (fixed `user-mentions.php` non-standard error)
- Updated 10 JavaScript consumers across 6 plugins to use new response pattern
- JavaScript now checks HTTP status (`response.ok`) instead of `data.success`
- Link click analytics response format changed to `{'tracked': true}` for semantic consistency
- Minor updates to existing route files for consistency and performance

### Migration Notes
JavaScript consumers must update from:
```javascript
.then(data => { if (data.success) { ... } })
```
To:
```javascript
.then(response => {
    if (!response.ok) return response.json().then(err => Promise.reject(err));
    return response.json();
})
.then(data => { /* access data.property directly */ })
```

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
