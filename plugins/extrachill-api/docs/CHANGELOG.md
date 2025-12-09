# Changelog

All notable changes to the ExtraChill API plugin are documented here. This file is the single source of truth for release history.

## 0.2.4

### Added
- **Link Text Tracking**: Enhanced link click analytics to capture link text at time of click
  - Added optional `link_text` parameter to `POST /analytics/link-click` endpoint
  - Updated `extrachill_link_click_recorded` action hook to include link text
  - Improves analytics granularity for link page performance tracking

### Changed
- **Dynamic Blog ID Handling**: Replaced hardcoded blog IDs with dynamic function calls
  - Documentation sync controller now uses `ec_get_blog_id( 'docs' )` instead of hardcoded ID 10
  - Docs info endpoint now uses `ec_get_blog_id( 'main' )` instead of hardcoded ID 1
  - Added proper error handling when blog IDs are not available
  - Improves maintainability and multisite compatibility
- **TOC Anchor Linking**: Added automatic header ID generation for documentation content
  - New `add_header_ids()` method in docs sync controller generates unique IDs for h2-h6 headers
  - Enables table of contents anchor linking in rendered documentation
  - Handles duplicate header text with incremental numbering

## 0.2.3

### Added
- **About Page Content in Docs Info**: Added `about` field to `/docs-info` response containing About page metadata from main site
  - New `extrachill_api_docs_info_collect_about()` function retrieves About page from blog ID 1
  - Response includes page ID, slug, title, URL, and processed content
  - Proper error handling with 500 response if About page not found

### Fixed
- **Artist Socials Icon Class Enrichment**: Fixed potential error when rendering social link icons
  - Added stricter validation to skip social links without required `id` field
  - Prevents calling `get_icon_class()` on invalid or malformed social link entries
  - Improves overall reliability of social link rendering

### Changed
- **Taxonomy Data Collection Performance**: Refactored `extrachill_api_docs_info_collect_post_types()` for improved efficiency
  - Consolidated separate queries for all terms and assigned terms into single efficient query
  - Now calculates term post counts directly in the query using GROUP BY and HAVING clause
  - Simplified response structure to include term post count inline
  - Reduced code complexity by ~50 lines while maintaining functionality

## 0.2.2

### Changed
- **Documentation Reorganization**: Consolidated and restructured all endpoint documentation
  - Moved from scattered route markdown files to organized feature-area structure
  - Updated `docs/routes/` with comprehensive endpoint documentation for all 36 endpoints
  - Improved documentation formatting and organization by feature category
- **AGENTS.md Updates**: Enhanced technical documentation with complete admin, user management, and docs endpoint specifications
- **README.md Updates**: Corrected endpoint count (36 total across 13 feature categories) and improved feature descriptions

### Removed
- Legacy documentation files replaced by centralized, organized structure

## 0.2.0

### Added
- **Documentation Sync System**: New endpoint for syncing markdown documentation to WordPress posts
  - `POST /wp-json/extrachill/v1/sync/doc` - Sync documentation content with hash-based change detection
  - Controller class `ExtraChill_Docs_Sync_Controller` for handling documentation synchronization
  - Markdown to HTML conversion using Parsedown library
  - Platform taxonomy management for organizing documentation
- **Link Page ID Generation**: Persistent ID assignment system for link page elements
  - New utility functions in `inc/utils/id-generator.php` for generating unique IDs
  - Enhanced artist links and socials endpoints to use persistent ID assignment
  - Counter-based ID generation with format `{link_page_id}-{type}-{index}`
  - Automatic ID synchronization to prevent conflicts

### Changed
- **Artist Links Endpoint**: Enhanced with persistent ID generation for sections, links, and socials
- **Artist Socials Endpoint**: Updated sanitization to include link page ID context
- **Dependencies**: Added `erusev/parsedown: ^1.7` for markdown processing

## 0.2.1

### Added
- **Artist Creation Endpoint**: `POST /wp-json/extrachill/v1/artists` for creating new artist profiles
  - Supports `name`, `bio`, `local_city`, and `genre` fields
  - Automatic user-artist relationship linking via BuddyPress integration
  - Permission checks using `ec_can_create_artist_profiles()` function
- **Artist Profile Enhancements**: Extended artist data model with new fields
  - Added `local_city` and `genre` support in GET/PUT `/artists/{id}` endpoints
  - Enhanced PUT handler to support `profile_image_id` and `header_image_id` updates
  - Updated response builder to include new metadata fields
- **Roster Management System**: Complete artist team management under `/artists/{id}/roster` namespace
  - `GET /artists/{id}/roster` - List roster members and pending invites
  - `POST /artists/{id}/roster` - Invite new members (nested version of legacy endpoint)
  - `DELETE /artists/{id}/roster/{user_id}` - Remove roster members
  - `DELETE /artists/{id}/roster/invites/{invite_id}` - Cancel pending invites
  - Enhanced invite handler to support both legacy and nested route formats

### Changed
- **Artist Permissions Endpoint**: Updated `manage_url` to remove artist_id parameter
  - Changed from `/?artist_id=X` to base `/manage-link-page/` URL
  - Updated documentation to reflect the URL simplification
- **Dynamic Blog ID Handling**: Replaced hardcoded blog ID with dynamic function
  - Changed `switch_to_blog(4)` to `ec_get_blog_id('artist')` in user-artist relationships
  - Added proper error handling when artist blog ID is not available

## 0.1.9

### Added
- **User Management APIs**: Complete user profile and relationship management system
  - `GET /wp-json/extrachill/v1/users/{id}` - User profile data with permission-based field access
  - `GET/POST/DELETE /wp-json/extrachill/v1/users/{id}/artists` - User-artist relationship management
  - Enhanced `GET /wp-json/extrachill/v1/users/search` with admin and mentions contexts
- **Admin Management APIs**: Administrative tools for platform management
  - `POST /wp-json/extrachill/v1/admin/ad-free-license/grant` - Grant ad-free licenses by username/email
  - `DELETE /wp-json/extrachill/v1/admin/ad-free-license/{user_id}` - Revoke ad-free licenses
  - `POST /wp-json/extrachill/v1/admin/team-members/sync` - Sync team member status across network
  - `PUT /wp-json/extrachill/v1/admin/team-members/{user_id}` - Manual team member status management
- **Route Reorganization**: Moved user search from community to users namespace with enhancements

### Changed
- **Documentation**: Centralized changelog in docs/CHANGELOG.md, removed from README.md

## 0.1.7

### Added
- **Stripe Connect Endpoints** (Conceptual/Untested - Foundation for Payment Integration)
  - `POST /wp-json/extrachill/v1/shop/stripe-connect/account` - Create/manage connected Stripe account
  - `GET /wp-json/extrachill/v1/shop/stripe-connect/status` - Retrieve account connection status and verification state
  - `POST /wp-json/extrachill/v1/shop/stripe-connect/onboarding-link` - Generate Stripe onboarding URL for account setup
  - `POST /wp-json/extrachill/v1/shop/stripe-connect/dashboard-link` - Generate Stripe dashboard access link
  - Account creation, status checking, and dashboard link generation
  - User meta storage for Stripe account IDs (`_stripe_connect_account_id`, `_stripe_connect_status`)
  - Permission callbacks requiring logged-in user with artist status
  - Integration with extrachill-shop plugin for account management functions
- **Comprehensive Route Documentation**: Added 14 new markdown documentation files under `docs/routes/`
  - Detailed endpoint documentation for all route categories
  - Request/response examples and parameter specifications
  - Supports API discovery and developer reference

### Changed
- **Artist Links Endpoint Cleanup**: Removed `weekly_notifications_enabled` from settings boolean fields
  - This field is no longer supported in link page settings sanitization
  - Any existing data using this field will be ignored on updates

### Technical Notes
- Stripe Connect endpoints are **conceptual and untested** - intended as foundation for future shop payment integration
- Endpoints depend on `extrachill_shop_user_is_artist()`, `extrachill_shop_create_stripe_account()`, `extrachill_shop_get_account_status()`, and related shop plugin functions
- Functions will return appropriate errors if shop plugin or Stripe integration is not available
- Requires extended testing and security review before production use

## 0.1.6

### Added
- **Artist Analytics Endpoint**: `GET /wp-json/extrachill/v1/artists/{id}/analytics` for retrieving link page analytics with configurable date range
  - Replaces legacy `/analytics/link-page` endpoint with artist-centric routing
  - Supports `date_range` parameter (default: 30 days) for flexible analytics queries
  - Uses filter hook `extrachill_get_link_page_analytics` for analytics data retrieval
- **Shop Products Endpoints**: Complete WooCommerce product CRUD operations for artists
  - `GET /wp-json/extrachill/v1/shop/products` - List user's artist products
  - `POST /wp-json/extrachill/v1/shop/products` - Create product linked to artist
  - `GET /wp-json/extrachill/v1/shop/products/{id}` - Get single product
  - `PUT /wp-json/extrachill/v1/shop/products/{id}` - Update product (partial updates)
  - `DELETE /wp-json/extrachill/v1/shop/products/{id}` - Delete product (trash)
  - Products created on Blog ID 3 (shop.extrachill.com) and linked to artist profiles via meta
  - Comprehensive image and gallery management support
  - Stock quantity and sale price support
- **Artist Links Endpoint Enhancements**:
  - Added support for `socials` field in PUT requests (array of {type, url} objects)
  - Added support for `background_image_id` and `profile_image_id` in PUT requests
  - New settings fields: `overlay_enabled`, `google_tag_manager_id`, `profile_image_shape`

### Changed
- **Artist Links Endpoint Refactoring**: Now uses canonical `ec_get_link_page_data()` function
  - Removed internal `extrachill_api_build_links_response()` function (delegated to canonical source)
  - Simplifies data transformation and ensures single source of truth
  - Endpoint now returns data directly from `ec_get_link_page_data()` for GET and PUT requests
- Updated permissions model for shop products (requires artist status for create/update/delete)

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
