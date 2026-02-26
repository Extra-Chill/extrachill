# Changelog

This file records notable changes in the ExtraChill API plugin.

## Unreleased

### Changed
- Remove obsolete admin REST routes for 404 logger, tag migration, and forum topic migration

### Fixed
- Fix share click tracking: migrate from broken ec_track_event() to Abilities API

## [0.10.11] - 2026-02-12

### Fixed
- Fix profile URLs: rename ec_get_user_profile_url to extrachill_get_user_profile_url

## [0.10.10] - 2026-01-28

### Fixed
- Fix taxonomy counts to use direct WP_Query for reliability

## [0.10.9] - 2026-01-28

### Changed
- Remove hardcoded blog ID fallback in shipping labels endpoint
- Add community taxonomy counts endpoint for cross-site linking
- added text domain
- Version bump to 0.10.8 - Dependency updates and route improvements
- Bump version to 0.9.2 - New auth, shop, and SEO endpoints
- Bump version to 0.9.1 - Activity taxonomy badge color fix
- Bump version to 0.9.0 - SEO audit endpoints
- Bump version to 0.8.6 - Documentation cleanup and activity emitters refactor
- Bump version to 0.8.5 - Festival wire migration tool cleanup
- Bump version to 0.8.4 - Festival wire inline upload migration
- Bump version to 0.8.3 - Festival wire migration and taxonomy sync tools
- Bump version to 0.8.2 - Browser handoff authentication endpoint
- Bump version to 0.8.1 - Enhanced auth/me endpoint with user context data
- Bump version to 0.8.0 - Shop shipping management and Turnstile enhancements
- Bump version to 0.7.2 - Activity enhancements for events and bbPress replies
- Bump version to 0.7.1 - Documentation reorganization and auth parameter enhancement
- Bump version to 0.7.0 - Activity enhancements, Google OAuth, and onboarding system
- Bump version to 0.6.5 - Artist access management API
- Bump version to 0.6.4 - Activity data display fixes
- Bump version to 0.6.3 - Activity system fixes
- Bump version to 0.6.2 - Auth endpoints, catalog cleanup
- Bump version to 0.6.1 - Shop enhancements, new endpoints, and prefix updates
- Bump version to 0.6.0 - Refactor artist endpoints to /artists/{id}/ API structure
- Bump version to 0.5.2 - Remove incorrect WooCommerce hack and orders endpoint
- Bump version to 0.5.1 - Users leaderboard endpoint and WooCommerce utility
- Bump version to 0.5.0 - Authentication system, Stripe webhooks, and stream status
- Bump version to 0.4.0 - Activity system, community drafts, and newsletter campaigns
- Bump version to 0.3.1 - Add contact form submission endpoint
- Bump version to 0.3.0 - Blocks to Blog refactoring and new shop APIs
- Bump version to 0.2.8 - Dynamic blog ID handling and new orders API
- Bump version to 0.2.7 - Remove excerpt support from docs sync
- Bump version to 0.2.6 - Internal link resolution for documentation
- Bump version to 0.2.5 - Pages collection and header ID fixes
- Bump version to 0.2.4 - Dynamic blog IDs and enhanced link tracking
- Bump version to 0.2.3 - Bug fixes and docs info enhancement
- Bump version to 0.2.2 - Documentation reorganization and enhancement
- Bump version to 0.2.1 - Add artist creation and roster management
- Bump version to 0.2.0 - Add documentation sync and ID generation
- Bump version to 0.1.9 - Add user management and admin APIs
- Bump version to 0.1.8 - Reorganize block endpoints under /blocks/ namespace and enhance link page tracking
- Bump version to 0.1.7 - Add Stripe Connect endpoints and comprehensive route documentation
- Bump version to 0.1.6 - Add artist analytics and shop products endpoints
- Bump version to 0.1.5 - Add Artist API and unified media endpoints
- Bump version to 0.1.4 - Add artist roster, chat, and analytics endpoints
- Bump version to 0.1.3 - Add analytics and artist subscription endpoints
- Add analytics link-click tracking and artist subscribe endpoints
- Bump version to 0.1.2 - Add QR code generator, analytics, community features, and enhanced event submissions
- Bump version to 0.1.1
- Initial commit: Scaffold ExtraChill API infrastructure

### Fixed
- Fix activity data display - decode HTML entities in titles and excerpts

## [0.10.7] - 2026-01-08

### Changed

- **Analytics Events + Summary Endpoints**: Updated `inc/routes/analytics/events.php` to use the renamed analytics-provider functions.
  - Requires `extrachill_get_analytics_events()` instead of `ec_get_events()`.
  - Uses `extrachill_count_analytics_events()` when available for pagination totals.
  - Requires `extrachill_get_analytics_event_stats()` instead of `ec_get_event_stats()`.

## [0.10.6] - 2026-01-07

### Added

- **SEO Config Endpoint**: Added `inc/routes/seo/config.php` to expose network-level SEO settings via REST.
  - `GET /wp-json/extrachill/v1/seo/config` returns `default_og_image_id`, `default_og_image_url`, and `indexnow_key`.
  - `PUT /wp-json/extrachill/v1/seo/config` updates `default_og_image_id` and/or `indexnow_key` (both optional).
  - Permission enforced via `manage_network_options`.

### Changed

- **Analytics Meta Endpoint**: Updated `inc/routes/analytics/meta.php` to avoid placeholder blog names.
  - Replaced `"Blog {id}"` fallback with the actual multisite `blogname` via `get_blog_option( $blog_id, 'blogname' )`.

## [0.10.5] - 2026-01-07

### Changed

- **Admin Forum Topic Moves (bbPress Independence)**: Updated `inc/routes/admin/forum-topics.php` to update `_bbp_topic_count`, `_bbp_reply_count`, and `_bbp_last_active_time` using direct `WP_Query`/`get_posts()` logic rather than relying on bbPress helper functions.
- **Newsletter Admin Mode Permission**: Updated `inc/routes/newsletter/subscription.php` (registers `POST /newsletter/subscribe`) admin-mode permission check to allow either multisite `is_super_admin()` or single-site `manage_options`.

## [0.10.4] - 2026-01-06

### Added

- **Analytics Meta Endpoint**: Added an admin-only metadata endpoint used to populate analytics filters.
  - `GET /wp-json/extrachill/v1/analytics/meta`

### Changed

- **Newsletter Subscription Contract**: Updated the subscription endpoint to support bulk subscription and admin-driven list targeting.
  - `POST /wp-json/extrachill/v1/newsletter/subscribe` now accepts an `emails` array (each entry supports `email` and optional `name`).
  - Supports `list_id` admin mode (requires `manage_options`) and `context` public mode.
  - Response now includes `subscribed`, `already_subscribed`, `failed`, and `errors` counts for batch processing.
- **Analytics Events Pagination**: Enhanced events listing to support search and pagination totals.
  - `GET /wp-json/extrachill/v1/analytics/events` now supports `search`.
  - Response now includes `total` (uses `extrachill_count_analytics_events()` when available).

## [0.10.3] - 2026-01-05

### Added

- **Unified Click Tracking**: Consolidated multiple individual tracking routes into a single high-performance endpoint:
  - `POST /wp-json/extrachill/v1/analytics/click` - Now handles `share` clicks and `link_page_link` clicks via a unified `click_type` parameter.
  - Improved URL normalization for all tracked clicks, stripping Google Analytics query parameters while preserving affiliate tags.
  - Centralized error handling for missing tracking functions or invalid destinations.

### Changed

- **Analytics Refactor**:
  - Removed deprecated `link-click.php` and `share.php` route files.
  - Updated `README.md` and `CLAUDE.md` to reflect the streamlined 3-endpoint analytics architecture.
- **Documentation Alignment**: 
  - Updated artist analytics documentation to reference the new unified `click` endpoint.
  - Refined shop documentation to include error response structures for the "Ships Free" label bypass logic.

## [0.10.2] - 2026-01-05

### Added

- **Cross-Site Taxonomy Counts**: New endpoints for retrieving post and product counts across the multisite network:
  - `GET /wp-json/extrachill/v1/blog/taxonomy-counts` - Blog post counts by artist, venue, location, etc.
  - `GET /wp-json/extrachill/v1/events/upcoming-counts` - Upcoming event counts by venue, location, artist, or festival.
  - `GET /wp-json/extrachill/v1/shop/taxonomy-counts` - Product counts by artist.
  - `GET /wp-json/extrachill/v1/wire/taxonomy-counts` - Wire post counts by taxonomy.
- **Shop Enhancements**:
  - Added `ships_free` boolean field to product CRUD and responses.
  - Added `ships_free_only` flag to artist order responses.
  - Implemented automatic blockage of shipping label purchases for orders containing only free-shipping items.

### Changed

- **Documentation**: Updated CLAUDE.md and README.md with new taxonomy count endpoints and reorganized feature categories.

## [0.10.1] - 2026-01-05

### Added

- **SEO Audit Details Endpoint**: New `GET /wp-json/extrachill/v1/seo/audit/details` endpoint for retrieving paginated audit results by category (missing excerpts, alt text, broken links, etc.). Supports CSV export via `export=true` parameter.
- **Admin Management Expansion**: 
  - `GET /wp-json/extrachill/v1/admin/forum-topics` - List and manage bbPress topics across network.
  - `GET /wp-json/extrachill/v1/admin/404-logger` - Monitor 404 errors for SEO management.
  - `GET /wp-json/extrachill/v1/admin/artist-relationships` - Manage user-artist links.
- **Tools Expansion**:
  - `GET /wp-json/extrachill/v1/tools/markdown-export` - Export posts, topics, and events as markdown for AI context or archiving.

### Changed

- **Endpoint Count**: Total endpoint files increased to 66 across 18 feature categories.
- **Documentation**: Updated README.md with comprehensive list of current endpoints and feature categories.

## [0.10.0] - 2026-01-04

### Added

- **Admin Data Listing Endpoints**: New `GET` routes for administrative data management:
  - `GET /wp-json/extrachill/v1/admin/team-members` - List network team members with pagination and search
  - `GET /wp-json/extrachill/v1/admin/artist-access` - List pending/processed artist access requests
  - `GET /wp-json/extrachill/v1/admin/lifetime-membership` - List users with active lifetime memberships
  - `GET /wp-json/extrachill/v1/admin/forum-topics` - List and manage bbPress topics across network
  - `GET /wp-json/extrachill/v1/admin/tags` - Manage and migrate tags network-wide
- **Analytics Expansion**: New tracking endpoints for enhanced platform insights:
  - `POST /wp-json/extrachill/v1/analytics/click` - Unified click tracking (`share`, `link_page_link`)
- **Utility Routes**:
  - `GET /wp-json/extrachill/v1/admin/404-logger` - Log and monitor 404 errors for SEO management
  - `GET /wp-json/extrachill/v1/admin/artist-relationships` - Inspect and manage user-artist links
- **Standardized Pagination**: Admin list endpoints now consistently support `per_page` and `page` parameters with `X-WP-Total` and `X-WP-TotalPages` headers.

### Changed

- **Membership Refactor**: Standardized "Ad-Free License" terminology to "Lifetime Membership" throughout the codebase and documentation.
- **User Search Enhancements**:
  - Added `artist-capable` context for finding users eligible for artist profile management.
  - Added `exclude_artist_id` support to prevent duplicates in roster management.
- **Endpoint Count**: Total endpoints increased to 68 across all feature categories.
- **Documentation**: Updated CLAUDE.md and README.md to reflect the new administrative and analytics infrastructure.

## [0.9.5] - 2026-01-04

### Added

- **Admin Data Listing Endpoints**: Initial implementation of administrative data management routes.
- **Standardized Pagination**: New admin list endpoints support standard `per_page` and `page` parameters.

### Changed

- **Endpoint Count**: Total endpoints increased to 63 across all categories.

## [0.9.4] - 2026-01-02

### Changed

- **Standardized Site Counts**: Updated network documentation to reflect 11 active sites (IDs 1-5, 7-12) across all documentation files.
- **Blog ID Abstraction**: Replaced remaining hardcoded blog ID references in documentation with the mandatory `ec_get_blog_id()` pattern.

## 0.9.3

### Added

- **Markdown Export Endpoint**: New `GET /wp-json/extrachill/v1/tools/markdown-export` for exporting content as markdown
  - Supports standard posts, bbPress topics (with replies), and events
  - Useful for content sharing and AI agent context feeding
  - Requires `league/html-to-markdown` dependency

### Improved

- **Artist Profile Security**: Added trim and empty check validation for artist names in `POST /artists/{id}` handler
- **Multisite Reliability**: Replaced hardcoded blog ID fallbacks with direct `ec_get_blog_id()` calls in shop shipping routes for more robust site resolution

### Technical Notes

- **Dependencies**: Added `league/html-to-markdown: ^5.1` for markdown conversion
- **Auth Documentation**: Standardized authentication terminology across route documentation
- Changes are additive with no breaking modifications to existing data contracts

## 0.9.2

### Added

- **Browser Handoff Authentication**: New `POST /wp-json/extrachill/v1/auth/browser-handoff` for cross-platform authentication flows
- **Taxonomy Synchronization**: Added `POST /wp-json/extrachill/v1/admin/taxonomies/sync` for network-wide taxonomy term management
- **Shop Shipping Management**: Comprehensive fulfillment tools for artist managers
  - `GET/PUT /wp-json/extrachill/v1/shop/shipping-address` - Artist return address configuration
  - `GET/POST /wp-json/extrachill/v1/shop/shipping-labels` - Shippo-powered label purchase and tracking
- **SEO Audit System**: Complete multisite auditing endpoints under `/seo/audit/` namespace
- **Comprehensive Documentation**: Added detailed markdown documentation for all new endpoints in `docs/routes/`

### Improved

- **Artist Data Validation**: Added strict name length validation and trimming in `POST /artists/{id}` handler
- **Blog Resolution**: Removed redundant checks and hardcoded fallbacks in shipping routes for more reliable blog context switching
- **Documentation Alignment**: Updated README.md and CLAUDE.md with new endpoint categories and directory structure

### Technical Notes

- Changes are additive or corrective with no breaking modifications to existing data contracts
- **Dependencies**: New features integrate with `extrachill-users`, `extrachill-shop`, and `extrachill-seo` plugins

## 0.9.1

### Fixed

- **Activity Taxonomy Badge Color Extraction**: Fixed regex pattern in `extrachill_api_activity_get_taxonomy_badge_color_map()` to avoid matching "background-color" properties by using negative lookbehind `(?<!background-)color\s*:\s*([^;]+);i`

### Technical Notes

- **Backward Compatibility**: All changes are fixes with no breaking modifications
- **Activity System**: Improved accuracy of taxonomy badge color extraction for activity cards

## 0.9.0

### Added

- **SEO Audit Endpoints**: Complete multisite SEO auditing system under `/seo/audit/` namespace
  - `POST /wp-json/extrachill/v1/seo/audit` - Start new SEO audit in full or batch mode
  - `POST /wp-json/extrachill/v1/seo/audit/continue` - Continue batch audit processing
  - `GET /wp-json/extrachill/v1/seo/audit/status` - Get current audit progress and results
  - Tracks missing excerpts, alt text, featured images, broken images, and broken internal/external links
  - Batch processing for large networks with progress tracking
  - Super admin permissions required (`manage_network_options`)
  - Integrates with `extrachill-seo` plugin for audit functionality

### Technical Notes

- **New Dependencies**: Requires `extrachill-seo` plugin for audit functions (`ec_seo_*` functions)
- **Endpoint Count**: Increased from 53 to 56 total endpoints
- **Backward Compatibility**: All changes are additive with no breaking modifications

## 0.8.6

### Changed

- **Documentation Cleanup**: Streamlined CLAUDE.md by removing detailed endpoint documentation and reorganizing into summarized endpoint categories for improved maintainability.
- **Activity Emitters Refactor**: Updated post event emission to use `save_post` hook instead of `transition_post_status` for more reliable activity tracking, with corresponding parameter and logic adjustments.

### Technical Notes

- All changes are additive with no breaking modifications.
- **Activity System**: Improved reliability of post activity emission through hook change and parameter updates.

## 0.8.5

### Removed

- **Festival Wire Migration Endpoints**: Removed one-time admin migration tooling after migration completion
  - Deleted `inc/routes/admin/festival-wire-migration.php` (974 lines)
  - Removed all festival wire migration endpoints from the API
  - This temporary admin tool was only needed for one-time content migration between sites
  - Migration has been completed successfully and tooling is no longer required

### Technical Notes

- **Code Cleanup**: Removal of 974 lines of temporary migration code
- **Breaking Change**: Festival wire migration endpoints are no longer available (not intended for long-term use)
- **Endpoint Count**: Reduced from 57 to 53 total endpoints

## 0.8.4

### Enhanced

- **Festival Wire Migration Inline Uploads**: Enhanced migration system to handle inline uploads within post content
  - Extracts and migrates images embedded directly in HTML content (not just Gutenberg blocks)
  - Supports srcset attributes and various URL formats for comprehensive image migration
  - Automatically sets post thumbnail from first inline image when no featured image exists
  - Improved duplicate detection with title/date-based fallback queries
  - Enhanced attachment URL normalization for multisite compatibility

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Migration Safety**: Enhanced duplicate detection prevents data loss during re-migrations
- **Performance**: Efficient URL extraction and attachment ID resolution

## 0.8.3

### Added

- **Festival Wire Migration System**: Complete admin tool for migrating festival_wire posts between sites with full attachment handling
  - `POST /wp-json/extrachill/v1/admin/festival-wire/preflight` - Analyze migration scope and current state
  - `POST /wp-json/extrachill/v1/admin/festival-wire/migrate` - Execute batch migration with attachment preservation
  - `POST /wp-json/extrachill/v1/admin/festival-wire/validate` - Mark migration complete and prevent accidental deletion
  - `POST /wp-json/extrachill/v1/admin/festival-wire/delete` - Remove migrated content from source site
  - Handles featured images, embedded attachments, and Gutenberg block media references
  - Maintains migration state and prevents duplicate migrations

- **Taxonomy Synchronization Tool**: Admin endpoint for syncing shared taxonomies across multisite network
  - `POST /wp-json/extrachill/v1/admin/taxonomies/sync` - Sync taxonomies (location, festival, artist, venue) from main site to target sites
  - Supports hierarchical taxonomy sync with parent-child relationships preserved
  - Provides detailed sync reports with created/skipped/failed term counts
  - Designed for Admin Tools UI integration

- **Authentication Source Tracking**: Enhanced user registration and OAuth flows with source attribution
  - Added `registration_source` parameter to Google OAuth and user registration endpoints
  - Added `registration_method` and `registration_page` parameters for better analytics
  - Enables tracking of registration origins (web app, mobile app, etc.)

### Enhanced

- **Activity System**: Added event card building and taxonomy badge color mapping functionality
  - Improved activity feed presentation with enhanced metadata handling
  - Added taxonomy-based visual styling for activity cards

### Technical Notes

- **New Dependencies**: Festival wire migration requires `ec_get_blog_id()` function availability
- **Admin Permissions**: All new endpoints require `manage_options` capability
- **Migration Safety**: Festival wire migration includes validation steps and state tracking to prevent data loss
- **Taxonomy Sync**: Supports selective syncing of specific taxonomies to targeted sites
- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Endpoint Count**: Increased from 52 to 57 total endpoints

## 0.8.2

### Added

- **Browser Handoff Authentication**: New `POST /wp-json/extrachill/v1/auth/browser-handoff` endpoint for cross-platform authentication flow
  - Generates one-time URLs that set WordPress auth cookies in real browsers and redirect to specified destinations
  - Validates redirect URLs to ensure they target extrachill.com domains only
  - Prevents extrachill.link domain usage for security
  - Requires logged-in user and integrates with extrachill-users plugin token management
  - Includes comprehensive input validation and error handling for redirect URLs
  - Supports absolute URL requirements with proper host validation

### Technical Notes

- **Backward Compatibility**: All existing endpoints maintain current behavior
- **Dependencies**: Requires extrachill-users plugin for `extrachill_users_create_browser_handoff_token()` function
- **Security**: Domain validation prevents unauthorized redirects; token-based one-time URL generation
- **Endpoint Count**: Increased from 51 to 52 total endpoints

## 0.8.1

### Added

- **Auth Me Endpoint Enhancement**: Extended `/auth/me` endpoint with comprehensive user context data
  - Added `artist_ids` array - All artist profile IDs managed by the user
  - Added `latest_artist_id` - Most recently accessed artist profile ID
  - Added `link_page_count` - Total number of link pages created by user
  - Added `can_create_artists` - Boolean permission flag for artist profile creation capability
  - Added `can_manage_shop` - Boolean permission flag for shop management capability
  - Added `shop_product_count` - Total number of shop products listed by user
  - Added `site_urls` object with URLs for community, artist, and shop sections
  - All new fields are conditional with function existence checks for graceful degradation
  - Enables mobile/web clients to build comprehensive navigation without additional API calls

- **Avatar Menu Documentation**: Added `docs/routes/users/avatar-menu.md` documenting deprecation
  - Directs clients to use `/auth/me` endpoint instead
  - Improves developer understanding of migration path

### Technical Notes

- **Backward Compatibility**: All new fields are additive; existing response contract unchanged
- **Graceful Degradation**: New fields only included if their respective helper functions exist
- **Performance**: No database impact; all data derived from existing user metadata and helper functions
- **Dependencies**: Conditional integration with extrachill-artist-platform and extrachill-shop functions

## 0.8.0

### Added

- **Shop Shipping Address Management**: New REST endpoints for artists to configure their return/from-address for label printing
  - `GET /wp-json/extrachill/v1/shop/shipping-address?artist_id=X` - Retrieve artist shipping address with validation status
  - `PUT /wp-json/extrachill/v1/shop/shipping-address` - Update artist shipping address with full address fields (name, street1, street2, city, state, zip, country)
  - Stores address data in post meta `_shipping_address` on artist profile
  - Includes helper functions: `extrachill_api_get_artist_shipping_address()`, `extrachill_api_save_artist_shipping_address()`, `extrachill_api_artist_has_shipping_address()`
  - Permission checks ensure only artist managers can access/modify shipping addresses
  - Gracefully handles missing artist blog ID with fallback to blog ID 4

- **Shop Shipping Labels Integration**: New endpoints for purchasing and managing Shippo shipping labels
  - `POST /wp-json/extrachill/v1/shop/shipping-labels` - Purchase shipping label with automatic cheapest USPS rate selection
  - `GET /wp-json/extrachill/v1/shop/shipping-labels/{order_id}` - Retrieve existing label and tracking information
  - Validates artist has configured shipping address before allowing label purchase
  - Integrates with `extrachill_shop_shippo_create_label()` for label generation
  - Stores label metadata on order: `_artist_label_*`, `_artist_tracking_*`, `_artist_label_data_*`
  - Automatically updates order status to 'completed' with tracking note
  - Sends email confirmation to user who purchased label with tracking number and label URL
  - Supports reprint of existing labels without re-charging

- **Development Turnstile Bypass**: Enhanced security verification for local development
  - Added automatic bypass for Turnstile verification in local environments (`WP_ENVIRONMENT_TYPE === 'local'`)
  - Added `extrachill_bypass_turnstile_verification` filter hook for fine-grained control
  - Applied to both contact form (`POST /contact/submit`) and event submissions (`POST /event-submissions`) endpoints
  - Enables testing and development workflows without requiring valid CAPTCHA tokens
  - Maintains full security in production environments

### Changed

- **Contact Submit Endpoint Enhancement**: Improved Turnstile verification with development bypass
  - Now checks local environment before requiring valid Turnstile token
  - Allows filter-based bypass for testing scenarios
  - Maintains backward compatibility with existing production behavior

- **Event Submissions Endpoint Enhancement**: Improved Turnstile verification with development bypass
  - Now checks local environment before requiring valid Turnstile token
  - Allows filter-based bypass for testing scenarios
  - Maintains backward compatibility with existing production behavior

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **New Dependencies**: Shipping labels endpoint depends on Shippo integration via `extrachill_shop_shippo_create_label()` function
- **Database**: New post meta keys added (`_shipping_address`, `_artist_label_*`, `_artist_tracking_*`, `_artist_label_data_*`)
- **Permissions**: All shipping endpoints use `extrachill_api_shop_user_can_manage_artist()` for access control
- **Email**: Shipping label purchase now triggers email confirmation via `wp_mail()` with order and label details

## 0.7.2

### Added

- **Event Activity Card Enhancement**: Enhanced activity system to include event-specific metadata for datamachine_events posts
  - Extracts event date/time from `_datamachine_event_datetime` post meta and adds to activity cards
  - Parses datetime using WordPress timezone for proper event scheduling context
  - Includes venue name from taxonomy terms in activity card data
  - Improves event discovery through activity feeds with complete event context

- **bbPress Reply Activity Context**: Added parent topic context to reply activity emissions
  - Captures parent topic ID and title when publishing bbPress replies
  - Uses `bbp_get_reply_topic_id()` for reliable topic association
  - Properly decodes HTML entities in topic titles for consistent display
  - Enables better navigation and context awareness in activity feeds for forum discussions

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Activity System**: Enhanced data structure provides richer context for events and discussions
- **Dependencies**: No new dependencies added; uses existing WordPress and bbPress functions

## 0.7.1

### Changed

- **Documentation Reorganization**: Consolidated route documentation structure and reorganized CLAUDE.md directory listings
  - Reorganized `inc/` directory structure in documentation for clarity
  - Updated feature categories from 18 to 20 (added Configuration and reorganized admin/user sections)
  - Refreshed endpoint count documentation to reflect 51 total endpoints

- **Authentication Parameter Enhancement**: Made Turnstile verification optional in user registration
  - Changed `turnstile_response` parameter from required to optional in `POST /auth/register`
  - Enables registration flows without CAPTCHA verification when appropriate
  - Maintains backwards compatibility with existing clients sending Turnstile tokens

### Removed

- **Outdated Authentication Documentation**: Removed legacy auth helper documentation files
  - Deleted `docs/routes/auth/cross-domain-cookie-controls.md`
  - Deleted `docs/routes/auth/extrachill-link-auth.md`
  - Cookie handling is managed by `inc/auth/extrachill-link-auth.php` and no longer requires separate documentation

### Added

- **Comprehensive Route Documentation**: Added dedicated markdown documentation files for previously undocumented endpoints
  - New: `docs/routes/auth/google.md` - Google OAuth authentication endpoint
  - New: `docs/routes/auth/logout.md` - Device token revocation and logout
  - New: `docs/routes/auth/me.md` - Current user data retrieval
  - New: `docs/routes/config/oauth.md` - OAuth provider configuration endpoint
  - New: `docs/routes/shop/orders.md` - Artist order management and fulfillment
  - New: `docs/routes/shop/product-images.md` - Product image upload and deletion
  - New: `docs/routes/users/onboarding.md` - User onboarding flow and status

- **Configuration Endpoints**: New OAuth configuration endpoint routing infrastructure
  - Added `inc/routes/config/oauth.php` for serving OAuth provider settings to mobile apps
  - Supports dynamic OAuth provider configuration discovery without hardcoding client IDs

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking API modifications
- **Documentation**: Updated CLAUDE.md directory structure and README.md endpoint organization for improved discoverability
- **Database**: No database schema changes from v0.7.0
- **Dependencies**: No new dependencies added

## 0.7.0

### Added

- **Activity Feed Taxonomy Filtering**: Enhanced `/activity` endpoint with taxonomy-based filtering using AND logic
  - Added `taxonomies` parameter supporting `category`, `post_tag`, `festival`, `location`, `venue`, `artist`, `promoter` taxonomies
  - Taxonomy terms are stored in activity data and searchable via JSON_SEARCH queries
  - Example: `?taxonomies[venue]=the-fillmore&taxonomies[location]=charleston`

- **Activity Throttling**: Prevented feed clutter by deduplicating repeated activity events
  - Added throttling rules for `post_updated` events (1-hour window)
  - Uses transient-based caching with actor-specific keys
  - Reduces noise in activity feeds for frequently updated content

- **Google OAuth Authentication**: New social login endpoint for Google account integration
  - `POST /wp-json/extrachill/v1/auth/google` - Authenticate users via Google ID tokens
  - Device tracking and token generation support
  - Integrates with existing token infrastructure

- **User Onboarding Endpoint**: Complete onboarding management system
  - `GET /wp-json/extrachill/v1/users/onboarding` - Retrieve onboarding status
  - `POST /wp-json/extrachill/v1/users/onboarding` - Complete onboarding with username and artist/professional flags
  - Supports user profile finalization workflow

### Changed

- **Authentication Endpoints Refactoring**: Simplified user registration flow
  - Removed username, artist, and professional parameters from `/auth/register` endpoint
  - Changed `from_join` parameter from string to boolean
  - Streamlined registration to focus on email/password with post-registration onboarding

- **Auth Me Endpoint Enhancement**: Added onboarding status to user profile data
  - New `onboarding_completed` field in `/auth/me` response
  - Uses `ec_is_onboarding_complete()` function for status determination

- **Activity Feed Documentation**: Updated with taxonomy filtering and throttling details
  - Added comprehensive examples for taxonomy parameter usage
  - Documented throttling behavior and cache-based deduplication

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Performance**: Taxonomy filtering uses efficient JSON_SEARCH queries; throttling prevents database bloat
- **Dependencies**: Google OAuth endpoint requires extrachill-users plugin; onboarding uses ec_ functions

## 0.6.5

### Added

- **Artist Access Management API**: Complete REST API system for artist platform access approval and rejection
  - `GET /wp-json/extrachill/v1/admin/artist-access/{user_id}/approve` - One-click email approval with HMAC token validation
  - `POST /wp-json/extrachill/v1/admin/artist-access/{user_id}/approve` - Admin tools button approval
  - `POST /wp-json/extrachill/v1/admin/artist-access/{user_id}/reject` - Admin tools button rejection
  - Secure HMAC-signed tokens for email approval links (multisite-compatible)
  - Integration with existing admin tools workflow and email notifications

### Changed

- **Activity Feed Documentation**: Updated API response structure documentation
  - Renamed `activities` array to `items` for consistency
  - Added `created_at`, `summary`, `primary_object`, and `secondary_object` fields
  - Updated pagination documentation to reflect `next_cursor` pattern

- **Authentication Endpoints Documentation**: Enhanced response format documentation
  - Added `access_expires_at` and `refresh_expires_at` timestamp fields
  - Added `avatar_url` and `profile_url` to user object in login and refresh responses

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Security**: New artist access endpoints use HMAC token validation for email-based approvals
- **Dependencies**: Artist access endpoints integrate with extrachill-admin-tools plugin functions

## 0.6.4

### Fixed

- **Activity Data Display**: Added HTML entity decoding in activity emitters for proper title and excerpt rendering
  - Applied `html_entity_decode()` to post titles and excerpts in `extrachill_api_activity_emit_post_events()` and `extrachill_api_activity_emit_comment_event()`
  - Ensures special characters display correctly in activity feeds and notifications
  - Prevents HTML entities from appearing as raw text in activity data

### Technical Notes

- **Backward Compatibility**: All changes are fixes with no breaking modifications
- **Activity System**: Enhanced data accuracy for activity feeds and comment events

## 0.6.3

### Fixed

- **Activity Table Creation**: Added failsafe method to ensure activity table exists on plugin boot
  - New `maybe_create_activity_table()` method in singleton class
  - Prevents activity system failures on fresh network activations where activation hooks don't fire
  - Checks table existence with `SHOW TABLES LIKE` query before attempting creation

- **Activity Date Handling**: Fixed UTC timestamp normalization throughout activity storage
  - Replaced `mysql2date()` calls with `gmdate()` for consistent UTC handling
  - Added `extrachill_api_activity_normalize_created_at()` helper function
  - Prevents timezone-related inconsistencies in activity timestamps

### Technical Notes

- **Backward Compatibility**: All changes are fixes with no breaking modifications
- **Activity System**: Enhanced reliability for multisite network activations and timestamp accuracy

## 0.6.2

### Added

- **Auth Logout Endpoint**: `POST /wp-json/extrachill/v1/auth/logout`
  - Revokes refresh tokens for device-specific logout functionality
  - Requires `device_id` parameter (UUID v4) to target specific device sessions
  - Returns success status and confirmation message
  - Depends on extrachill-users plugin for token management

- **Auth Me Endpoint**: `GET /wp-json/extrachill/v1/auth/me`
  - Returns authenticated user profile data including id, username, email, display_name, avatar_url, profile_url, and registered date
  - Applies `extrachill_auth_me_response` filter for extensibility
  - Requires valid authentication token

### Removed

- **Shop Catalog Endpoint**: Removed `GET /wp-json/extrachill/v1/shop/catalog`
  - Public product browsing endpoint with filtering and sorting
  - Functionality replaced by theme's filter bar component
  - Associated documentation file also removed

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Dependencies**: New auth endpoints require extrachill-users plugin for token management

## 0.6.1

### Changed

- **Function Name Prefixes**: Updated function names from `bp_` to `ec_` prefixes throughout shop endpoints for consistency with platform naming conventions

- **Shop Products Enhancements**: Enhanced product management with status management, image ordering, and publish validation
  - Added support for product status transitions (draft, pending, publish)
  - Implemented image ordering functionality for product galleries
  - Added publish validation to ensure products meet requirements before going live

- **Stripe Connect Status Handling**: Improved safer status handling for Stripe Connect integration
  - Enhanced error handling and validation for Stripe account connections
  - Better status reporting and connection state management

### Added

- **Shop Catalog Endpoint**: New `GET /wp-json/extrachill/v1/shop/catalog` endpoint for retrieving public product catalog data

- **Shop Orders Management**: New comprehensive orders endpoint with list, status update, and refunds capabilities
  - `GET /wp-json/extrachill/v1/shop/orders` - List orders with filtering and pagination
  - `PUT /wp-json/extrachill/v1/shop/orders/{id}` - Update order status and tracking
  - `POST /wp-json/extrachill/v1/shop/orders/{id}/refund` - Process order refunds

- **Shop Product Images Endpoint**: New `GET /wp-json/extrachill/v1/shop/products/{id}/images` endpoint for managing product image galleries

- **Documentation File**: Added new documentation file covering shop API integration and usage patterns

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Dependencies**: Shop endpoints continue to integrate with WooCommerce and Stripe Connect systems

## 0.6.0

### Changed

- **Artist API Endpoints**: Refactored all artist endpoints to use RESTful `/artists/{id}/` URL structure
  - Changed `GET /artist/permissions` → `GET /artists/{id}/permissions`
  - Changed `POST /artist/roster/invite` → `GET/POST/DELETE /artists/{id}/roster`
  - Changed `GET /artist/subscribers` → `GET /artists/{id}/subscribers`
  - Changed `POST /artist/subscribe` → `POST /artists/{id}/subscribe`
  - Updated all artist endpoints to consistent `/artists/{id}/` pattern

- **Response Structures**: Updated endpoint responses for better REST compliance
  - Permissions endpoint now returns `can_edit`, `manage_url`, `user_id`
  - Roster endpoint expanded to full CRUD operations with members/invites structure

### Technical Notes

- **Directory Structure**: Renamed `inc/routes/artist/` and `docs/routes/artist/` to `artists/` for consistency
- **Backward Compatibility**: Breaking API changes - clients must update endpoint URLs
- **Code Organization**: Moved route files to match new directory structure

## 0.5.2

### Removed

- **WooCommerce Utility**: Deleted `inc/utils/woocommerce.php` - This hack attempted to force-load WooCommerce on non-shop sites, which is incorrect. WooCommerce is only active on shop.extrachill.com and shop operations should use REST API calls or direct database queries within `switch_to_blog()` context.

- **Orders Endpoint**: Deleted `inc/routes/shop/orders.php` - This endpoint used WooCommerce functions (`wc_get_orders()`) incorrectly. Orders functionality will be re-implemented properly when the Orders tab is added to the artist dashboard.

### Fixed

- **Media Upload Permission Check**: Fixed `product_image` context in `inc/routes/media/upload.php` to use `get_post()` instead of `wc_get_product()` for product validation. This follows the same correct pattern used in `products.php`.

### Technical Notes

- Shop endpoints now consistently use standard WordPress functions (`get_post()`, `WP_Query`, `get_post_meta()`) within `switch_to_blog()` context
- No WooCommerce runtime objects are required since we're working directly with the `product` post type and its meta keys

## 0.5.1

### Added

- **Users Leaderboard Endpoint**: `GET /wp-json/extrachill/v1/users/leaderboard` - Public endpoint for paginated user leaderboards ranked by total points
  - Supports optional badge and rank system integration with graceful degradation
  - Includes user profile data, points, and position information
  - Useful for community engagement and gamification features

### Documentation

- **Authentication Endpoints**: Added comprehensive documentation for login, refresh, and register endpoints
  - Request/response examples and parameter specifications
  - Integration details with extrachill-users plugin

- **Stream Status Endpoint**: Added documentation for live streaming status monitoring
  - Endpoint specifications and usage examples
  - Permission and integration requirements

### Updated

- **Endpoint Count**: Updated README.md and CLAUDE.md to reflect current endpoint totals
- **Directory Structure**: Enhanced CLAUDE.md with complete route directory organization
- **Shop Documentation**: Improved documentation for products and Stripe integration endpoints

### Technical Notes

- **Backward Compatibility**: All changes are additive with no breaking modifications
- **Dependencies**: Leaderboard endpoint optionally integrates with badge/rank systems if available

## 0.5.0

### Added

- **Authentication System Endpoints**: Complete token-based authentication infrastructure
  - `POST /wp-json/extrachill/v1/auth/login` - User login with device tracking and token generation
  - `POST /wp-json/extrachill/v1/auth/refresh` - Token refresh for continued authentication
  - `POST /wp-json/extrachill/v1/auth/register` - User registration with Turnstile verification and automatic token setup
  - Supports device identification, remember me functionality, and cookie-based sessions
  - Integrates with extrachill-users plugin for token management and validation

- **Stripe Webhook Integration**: Payment processing webhook handler
  - `POST /wp-json/extrachill/v1/shop/stripe-webhook` - Centralized Stripe webhook endpoint
  - Delegates processing to extrachill-shop plugin business logic
  - Supports payment completion, failed payments, and subscription events

- **Stream Status Endpoint**: Live streaming status monitoring
  - `GET /wp-json/extrachill/v1/stream/status` - Retrieve current stream status and metadata
  - Integrates with extrachill-stream plugin for status information
  - Supports permission-based access control for stream management

### Changed

- **Route Registration Refactoring**: Standardized endpoint registration across the plugin
  - Updated artist permissions, docs sync, and media upload endpoints to use `extrachill_api_register_routes` action
  - Changed method constants to use `WP_REST_Server::READABLE` and `WP_REST_Server::CREATABLE`
  - Improved consistency with plugin's singleton route discovery pattern

- **Product Permission Fix**: Corrected artist ownership validation in media upload
  - Fixed meta key reference from `_artist_id` to `_artist_profile_id` for product-artist relationships
  - Added proper type casting for artist ID comparisons
  - Improved error handling in multisite product permission checks

### Technical Notes

- **Authentication Flow**: New endpoints enable mobile app authentication and persistent sessions
- **Payment Integration**: Webhook endpoint enables real-time payment processing and order fulfillment
- **Stream Monitoring**: Status endpoint supports live streaming features and audience analytics
- **Dependencies**: Authentication endpoints require extrachill-users plugin; Stripe webhook requires extrachill-shop plugin; Stream status requires extrachill-stream plugin
- **Backward Compatibility**: All existing endpoints maintain their current behavior and contracts

## 0.4.0

### Added

- **Activity Feed System**: Complete activity tracking and retrieval infrastructure
  - `GET /wp-json/extrachill/v1/activity` - Retrieve paginated activity feed with advanced filtering
  - Supports keyset pagination via `cursor` parameter for efficient large result sets
  - Filtering by `blog_id`, `actor_id`, `visibility` (public/private), and activity `types`
  - Visibility controls: public activities visible to all; private activities require `manage_options` capability
  - Database table creation via `extrachill_api_activity_install_table()` on plugin activation
  - Complete activity schema with id, blog_id, actor_id, type, object_type, object_id, timestamp, visibility, data fields
  - Storage layer handles create, read, update, delete operations with proper parameterized queries
  - Emitter system with extensibility hooks for consuming plugins to emit activity events

- **Object Resolver Endpoint**: Unified data resolution for posts, comments, and artists
  - `GET /wp-json/extrachill/v1/object` - Resolve and retrieve data for different object types
  - Supports three object types: `post`, `comment`, `artist`
  - Automatic blog context switching for multisite compatibility
  - Context-aware permission checks:
    - Posts: Requires `edit_post` capability OR post must be published
    - Comments: Requires `edit_comment` capability OR comment must be approved OR user must be comment author
    - Artists: Requires `ec_can_manage_artist()`
  - Returns normalized response format across all object types with consistent structure
  - Graceful error handling for missing or inaccessible objects

- **Community Drafts Management**: bbPress topic and reply draft persistence
  - `GET /wp-json/extrachill/v1/community/drafts` - Retrieve draft by context (topic or reply)
  - `POST /wp-json/extrachill/v1/community/drafts` - Save or update draft content
  - `DELETE /wp-json/extrachill/v1/community/drafts` - Remove saved draft
  - Supports both topic drafts (via `forum_id`) and reply drafts (via `topic_id`)
  - Optional reply context with `reply_to` parameter for nested replies
  - Fallback to unassigned forum drafts for topics via `prefer_unassigned` parameter
  - Utility functions for draft management with user-scoped storage

- **Newsletter Campaign Push**: Publishing endpoint for Sendy email service integration
  - `POST /wp-json/extrachill/v1/newsletter/campaign/push` - Push newsletter posts to Sendy
  - Converts post content to email-friendly HTML format
  - Stores campaign ID on post for tracking and auditing
  - Requires `edit_posts` capability for security
  - Integrates with `send_newsletter_campaign_to_sendy()` helper function

### Changed

- **Plugin Initialization**: Added activation hook for database table creation
  - New `extrachill_api_activate()` function registers with WordPress activation hook
  - Automatically creates activity table on first plugin activation
  - Ensures database schema exists before endpoints try to use it
  - Graceful handling if table already exists

- **Core Bootstrap Process**: Extended plugin boot to initialize activity system
  - Added activity system initialization in `boot()` method
  - Loads database, schema, storage, emitter, and emitters modules
  - Only loads activity modules if files exist (graceful degradation)
  - Improved separation of concerns with modular loading

### Fixed

- **Artist Permissions Endpoint**: Enhanced CORS handling for link domain variants
  - Improved CORS header handling for `extrachill.link` and `www.extrachill.link` domains
  - Proper `Vary: Origin` header for cache compatibility

- **Media Upload**: Enhanced context validation and error handling
  - Improved validation for upload contexts
  - Better error messages for invalid operations

- **User Search**: Enhanced artist-capable context filtering
  - Improved user filtering for artist-capable context
  - Better support for roster management workflows

- **Artist Profile**: Minor enhancements to data handling
  - Improved response serialization
  - Better error handling for missing data

### Technical Notes

- **Database**: Activity system creates `{base_prefix}extrachill_activity` table with proper indexes (created_at, type/id, blog_id/id, actor_id/id)
- **Activation**: Plugin now requires activation to initialize database. Install via WordPress admin or `wp plugin activate extrachill-api --network`
- **Extensibility**: Activity emitters provide filter hooks for consuming plugins to emit custom activity events
- **Performance**: Activity feed uses keyset pagination (cursor-based) for efficient handling of large datasets
- **Security**: Activity visibility properly enforced at query level, not post-processing
- **Backward Compatibility**: All changes are additive; no breaking changes to existing endpoints

## 0.3.1

### Added
- **Contact Form Submission Endpoint**: New contact form endpoint with Turnstile verification and email integration
  - `POST /wp-json/extrachill/v1/contact/submit` - Handle contact form submissions with security verification
  - Validates Cloudflare Turnstile tokens for spam protection
  - Sends admin notification emails and user confirmation emails
  - Integrates with Sendy newsletter system for email list management
  - Comprehensive input validation and sanitization

## 0.3.0

### Changed
- **BREAKING: Blocks to Blog Refactoring**: Major architectural reorganization renaming "blocks" to "blog" throughout the codebase
  - Moved `inc/routes/blocks/` directory to `inc/routes/blog/`
  - Moved `docs/routes/blocks/` directory to `docs/routes/blog/`
  - Updated all endpoint URLs from `/blocks/*` to `/blog/*` (affects band-name, rapper-name, ai-adventure, image-voting endpoints)
  - Renamed functions from `extrachill_blocks_*` to `extrachill_blog_*`
  - Updated plugin references from "extrachill-blocks" to "extrachill-blog"
  - Updated production status and documentation references
- **Enhanced CORS Support**: Extended artist permissions endpoint to allow both `extrachill.link` and `www.extrachill.link` origins
  - Added `www.extrachill.link` to allowed CORS origins
  - Improved CORS header handling with proper `Vary: Origin` header

### Added
- **Shop Orders & Earnings API**: New comprehensive endpoints for artist order and earnings management
  - `GET /wp-json/extrachill/v1/shop/orders` - List orders containing user's artist products with filtering by status and limit
  - `GET /wp-json/extrachill/v1/shop/earnings` - Get earnings summary statistics (total orders, earnings, pending payout, completed sales)
  - Includes artist payout calculations with configurable commission rates
  - Proper permission checks requiring artist status or admin access
- **Artist-Capable User Search**: Enhanced user search with new context for roster management
  - Added `artist-capable` context to `GET /wp-json/extrachill/v1/users/search` endpoint
  - Filters users who can create artist profiles (user_is_artist, user_is_professional, or team members)
  - Added `exclude_artist_id` parameter to exclude existing roster members
  - Supports roster invite workflows by finding eligible users
- **Product Image Upload Support**: Extended media upload endpoint to support WooCommerce product images
  - Added `product_image` context to `POST/DELETE /wp-json/extrachill/v1/media` endpoints
  - Uploads images to shop site media library and sets as product featured images
  - Automatic cleanup of old product images when replaced
  - Permission validation ensures users can only manage images for products they own

### Technical Notes
- **Migration Required**: All client applications calling `/blocks/*` endpoints must update to `/blog/*` URLs
- All endpoints now include comprehensive error handling for multisite configuration issues
- Permission checks enhanced to use dynamic artist ownership validation
- Database queries optimized with proper prepared statements and meta queries

## 0.2.8

### Added
- **Shop Orders & Earnings API**: New comprehensive endpoints for artist order and earnings management
  - `GET /wp-json/extrachill/v1/shop/orders` - List orders containing user's artist products with filtering by status and limit
  - `GET /wp-json/extrachill/v1/shop/earnings` - Get earnings summary statistics (total orders, earnings, pending payout, completed sales)
  - Includes artist payout calculations with configurable commission rates
  - Proper permission checks requiring artist status or admin access
- **Product Image Upload Support**: Extended media upload endpoint to support WooCommerce product images
  - Added `product_image` context to `POST/DELETE /wp-json/extrachill/v1/media` endpoints
  - Uploads images to shop site media library and sets as product featured images
  - Automatic cleanup of old product images when replaced
  - Permission validation ensures users can only manage images for products they own
- **Artist-Capable User Search**: Enhanced user search with new context for roster management
  - Added `artist-capable` context to `GET /wp-json/extrachill/v1/users/search` endpoint
  - Filters users who can create artist profiles (user_is_artist, user_is_professional, or team members)
  - Added `exclude_artist_id` parameter to exclude existing roster members
  - Supports roster invite workflows by finding eligible users

### Changed
- **Dynamic Blog ID Handling**: Comprehensive refactor to replace hardcoded blog IDs with dynamic function calls
  - Replaced hardcoded blog IDs with `ec_get_blog_id('artist')`, `ec_get_blog_id('shop')`, etc. across all endpoints
  - Added proper error handling when blog IDs are not available
  - Improves maintainability and multisite compatibility
- **Enhanced Multisite Switching**: Added proper `switch_to_blog()`/`restore_current_blog()` calls throughout codebase
  - Artist endpoints now properly switch to artist blog for post operations
  - Shop endpoints switch to shop blog for WooCommerce operations
  - Stripe Connect endpoints switch to shop blog for Stripe SDK access
  - Prevents cross-blog data contamination and ensures correct context
- **Artist Taxonomy Synchronization**: Automatic sync of artist taxonomy terms with product operations
  - Products now automatically get assigned to artist taxonomy terms matching artist slugs
  - Ensures proper categorization and filtering in WooCommerce shop
  - Handles term creation and assignment during product creation/updates
- **Shop Products Refactor**: Complete overhaul of products endpoint for improved architecture
  - Replaced shop plugin function dependencies with direct WooCommerce API calls
  - Enhanced permission checks using artist ownership validation
  - Improved product listing with proper meta queries for artist filtering
  - New products now start as 'pending' status for review workflow
- **Stripe Connect Improvements**: Enhanced Stripe integration with dynamic blog handling
  - All Stripe operations now properly switch to shop blog context
  - Improved error handling and response consistency
  - Better integration with WooCommerce and Stripe SDK
- **Artist Profile URL Generation**: Updated roster endpoint to use centralized profile URL function
  - Changed from `bbp_get_user_profile_url()` to `ec_get_user_profile_url()` with email parameter
  - Ensures consistent profile URL generation across platform

### Technical Notes
- All endpoints now include comprehensive error handling for multisite configuration issues
- Permission checks enhanced to use dynamic artist ownership validation
- Database queries optimized with proper prepared statements and meta queries
- Backward compatibility maintained while improving architectural consistency

## 0.2.7

### Changed
- **Documentation Sync Excerpt Removal**: Removed excerpt parameter support from docs sync endpoint
  - Removed `excerpt` parameter from `POST /wp-json/extrachill/v1/sync/doc` endpoint validation
  - Removed `post_excerpt` from documentation post data insertion
  - Updated hash calculation to exclude excerpt for change detection
  - Simplifies documentation sync API by removing unused excerpt functionality

## 0.2.6

### Added
- **Internal Link Resolution**: Enhanced documentation sync controller with automatic .md link resolution
  - New `resolve_internal_links()` method converts internal .md file references to ec_doc permalinks
  - Processes HTML content after markdown conversion to find and resolve `<a href="file.md">` links
  - Uses `get_post_by_source_file()` to find matching documentation posts by source file path
  - Maintains original link if no matching post is found (graceful degradation)
  - Improves documentation navigation by enabling proper internal linking between docs

## 0.2.5

### Added
- **Pages Collection in Docs Info**: Added `pages` field to `/docs-info` endpoint response
  - New `extrachill_api_docs_info_collect_pages()` function collects published pages with title and URL
  - Provides documentation agents with site structure visibility for accurate linking
  - Pages sorted alphabetically by title

### Fixed
- **Header ID Generation**: Fixed TOC anchor linking to generate IDs for h2 tags only
  - Previously attempted h2-h6, now correctly limited to h2 headers
  - Prevents invalid ID generation for unsupported header levels
- **Artist Links Overlay Setting**: Corrected overlay_enabled handling in link page settings
  - Removed from boolean fields array as overlay is now stored via css_vars.overlay
  - Prevents incorrect boolean conversion of overlay settings

### Changed
- **Documentation Updates**: Enhanced docs-info.md with pages collection details
  - Added comprehensive documentation for the new pages field
  - Improved API reference with usage examples

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
- **CLAUDE.md Updates**: Enhanced technical documentation with complete admin, user management, and docs endpoint specifications
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
- `POST /wp-json/extrachill/v1/admin/lifetime-membership/grant` - Grant Lifetime Extra Chill Membership (ad-free) by username/email
- `DELETE /wp-json/extrachill/v1/admin/lifetime-membership/{user_id}` - Revoke Lifetime Extra Chill Membership
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
- Comprehensive API documentation in CLAUDE.md for all new endpoints

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
- Comprehensive CLAUDE.md documentation file (renamed from CLAUDE.md)

### Changed
- Event submissions now auto-populate user data when logged in, reducing form requirements
- Newsletter subscriptions simplified by removing Turnstile verification requirement
- Updated README.md documentation links to reference CLAUDE.md

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
