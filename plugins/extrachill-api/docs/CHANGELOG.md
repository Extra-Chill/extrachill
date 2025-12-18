# Changelog

All notable changes to the ExtraChill API plugin are documented here. This file is the single source of truth for release history.

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

- **Endpoint Count**: Updated README.md and AGENTS.md to reflect current endpoint totals
- **Directory Structure**: Enhanced AGENTS.md with complete route directory organization
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

- **Database**: Activity system creates `{wp_prefix}extrachill_activity` table with proper indexes on blog_id, actor_id, timestamp, visibility
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
