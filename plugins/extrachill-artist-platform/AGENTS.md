# Extra Chill Artist Platform

WordPress plugin providing comprehensive artist platform functionality for the Extra Chill community. Enables artists to create profiles, link pages, and manage subscribers.

This plugin is part of the Extra Chill Platform, a WordPress multisite network serving music communities across 10 active sites.

## Architecture

### Core Classes
- **ExtraChillArtistPlatform**: Main plugin class (singleton), initialization (`extrachill-artist-platform.php`)
- **ExtraChillArtistPlatform_PageTemplates**: Template handling, routing, custom template loading (`inc/core/class-templates.php`)
- **ExtraChillArtistPlatform_Assets**: Asset management and conditional enqueuing (`inc/core/artist-platform-assets.php`)
- **ExtraChillArtistPlatform_SocialLinks**: Comprehensive social link management with 15+ platform support, validation, and rendering (`inc/core/filters/social-icons.php`)

### File Organization
- **Core Directory**: `inc/core/` - Post types, rewrite rules, asset management, templates, centralized data functions
- **Artist Profiles**: `inc/artist-profiles/` - Admin, frontend, roster management
- **Link Pages**: `inc/link-pages/` - Live pages, management interface, subscription system
- **Join Flow**: `inc/join/` - User registration and artist platform onboarding
- **Homepage**: `inc/home/` - Homepage template override, hero section, artist grid hooks
- **Database**: `inc/database/` - Analytics and subscriber database functions
- **Assets**: `assets/` - Consolidated CSS/JS, organized by functionality
- **Blocks**: `src/blocks/` - Gutenberg block components (React-based)
  - **Link Page Editor Block**: `src/blocks/link-page-editor/` - Complete link page management via Gutenberg editor

**Note**: Migration functionality moved to extrachill-admin-tools plugin (`inc/tools/artist-platform-migration.php`)

### Custom Post Types
- **artist_profile**: Artist/band profiles (archive: `/artists/`, single: `/artists/{slug}` on artist.extrachill.com, top-level `/{slug}` on other domains)
- **artist_link_page**: Link pages (accessible at `extrachill.link/{artist-slug}` via top-level rewrite rules, canonical domain for public link pages)

### extrachill.link Domain Integration
The plugin provides comprehensive integration with the extrachill.link domain for artist link pages:

**Domain Mapping Architecture**:
- **Backend Mapping**: extrachill.link maps to artist.extrachill.com (blog ID 4) via .github/sunrise.php
- **Frontend URLs**: All link page URLs display as extrachill.link/{artist-slug} while operating on artist.extrachill.com backend
- **Cross-Domain Auth**: WordPress authentication cookie attributes adjusted by **extrachill-users** (`extrachill-users/inc/auth/extrachill-link-auth.php`) to support authenticated REST calls from extrachill.link
- **URL Preservation**: sunrise.php `home_url` filter replaces artist.extrachill.com with extrachill.link in frontend output

**Link Page URL Structure**:
- **Public URLs**: extrachill.link/{artist-slug} (e.g., extrachill.link/extra-chill)
- **Management Interface**: extrachill.link/manage-link-page (accessible when authenticated)
- **Join Flow**: extrachill.link/join redirects to artist.extrachill.com/login/?from_join=true via sunrise.php

**Rewrite Rules & Routing** (`inc/core/artist-platform-rewrite-rules.php`):
- **Domain-Aware Rewrite Rules**: Top-level rewrite rules only active on extrachill.link domain
- **Dynamic Exclusions**: Automatically excludes WordPress pages and critical paths (manage-artist, manage-link-page, join, wp-login, wp-admin)
- **Template Routing**: `extrachill_handle_link_domain_routing()` handles all extrachill.link routing via `template_include` filter
- **Root Domain Handling**: extrachill.link root serves "extra-chill" default link page
- **Canonical Redirect Prevention**: Disables WordPress canonical redirects on extrachill.link domain
- **404 Redirects**: Non-existent slugs redirect to extrachill.link root

**Public Template Integration**:
- **Template File**: inc/link-pages/live/templates/extrch-link-page-template.php
- **Powered By Footer**: Includes "Powered by Extra Chill" with link to https://extrachill.link
- **Edit Icon System**: Client-side permission check with CORS REST API to artist.extrachill.com (see Edit Icon System section)
- **Share Modal Integration**: Share URLs use extrachill.link canonical URLs (inc/link-pages/live/assets/js/extrch-share-modal.js)

### Key Features

#### Join Flow System
**Location**: `inc/join/`
- **Modal-Based Entry Point**: `extrachill.link/join` redirects to artist.extrachill.com/login/?from_join=true via sunrise.php
- **Modal Interface**: `inc/join/templates/join-flow-modal.php` - Displays "Existing vs New Account" selection to users
- **Integration Point**: Hooks into `extrachill_below_login_register_form` action from extrachill-users plugin
- **Assets**: `inc/join/assets/css/join-flow.css`, `inc/join/assets/js/join-flow-ui.js`
- **Request Detection**: `ec_is_join_flow_request()` - Identifies join flow sessions via `from_join` parameter
- **Post-Auth Routing**: Redirects authenticated users to dedicated creation/management pages (not automatic profile creation)
  - New users or users without artists → `/create-artist/` (artist-creator block)
  - Existing users with artists → `/manage-link-page/` (link-page-editor block)
- **Separated Workflows**: Artist creation and link page management now handled via dedicated blocks, not join flow

#### Link Page System
**Location**: `inc/link-pages/` and `src/blocks/link-page-editor/`
- **Live Pages**: `inc/link-pages/live/` - Public templates, analytics
  - **Public URLs**: All link pages accessible at extrachill.link/{artist-slug}
  - **Template**: inc/link-pages/live/templates/extrch-link-page-template.php includes "Powered by Extra Chill" footer
- **Management Interface**: Gutenberg block editor system (primary)
  - **Gutenberg Block Editor**: `src/blocks/link-page-editor/` - React-based block for Gutenberg
    - **Block Registration**: Registered in main plugin init via `register_block_type( __DIR__ . '/build/blocks/link-page-editor' )`
    - **Block Location**: Appears in Gutenberg editor for artist_link_page post type
    - **React Components**: Tab-based interface with TabInfo, TabLinks, TabCustomize, TabAdvanced, TabSocials
    - **Build Process**: Webpack compilation via `npm run build` with wp-scripts
    - **API Client**: REST API integration via `src/blocks/shared/api/client.js`
   - **Analytics Dashboard**: Separate dedicated block `src/blocks/artist-analytics/`
     - **Block Registration**: Registered in main plugin init via `register_block_type( __DIR__ . '/build/blocks/artist-analytics' )`
     - **Features**: Chart.js-powered analytics, daily aggregation, link click tracking
- **Advanced Features**: `inc/link-pages/management/advanced-tab/` - Tracking, redirects, link expiration, YouTube embeds
- **Component Templates**: `inc/link-pages/management/templates/components/` - Modular UI components
- **Subscription Templates**: `inc/link-pages/live/templates/` - Email collection forms and modals
- **Canonical URLs**: All link page operations use extrachill.link URLs (sharing, analytics, public access)



#### Subscription System
**Locations**: `inc/artist-profiles/`, `inc/link-pages/live/templates/`, `inc/database/`
- Email collection with artist association (`inc/artist-profiles/subscribe-data-functions.php`)
- Database table: `{prefix}_artist_subscribers` (`inc/database/subscriber-db.php`)
- REST API-driven subscription forms with modal support
- Inline and modal subscription interfaces (`inc/link-pages/live/templates/`)
- Link page subscription functions (`inc/link-pages/subscribe-functions.php`)
- Export tracking and management capabilities

#### Analytics System
**Files**: `inc/database/link-page-analytics-db.php`, `inc/link-pages/live/analytics.php`
- **Architecture**: REST API endpoints in extrachill-api plugin; this plugin provides data via filter and handles database writes
- **Page View Tracking**: JavaScript beacon on public link pages → extrachill-api REST endpoint → action hook → database write
- **Link Click Tracking**: JavaScript beacon with link URL → extrachill-api REST endpoint → action hook → database write
- **Data Provider**: `extrachill_get_link_page_analytics` filter supplies analytics data to extrachill-api endpoints
- **Action Hooks**: `extrachill_link_page_view_recorded`, `extrachill_link_click_recorded` for handling tracking writes
- **Data Tables**: Daily views and link clicks stored in `{prefix}_extrch_link_page_daily_views` and `{prefix}_extrch_link_page_daily_link_clicks` tables
- **Data Retention**: Automatic 90-day pruning of daily analytics tables
- **Dashboard**: Chart.js-powered analytics block `src/blocks/artist-analytics/` with daily breakdown charts (v1.1.11+)

#### Roster Management System
**Location**: `inc/artist-profiles/roster/`
- Band member invitation system with email notifications (`inc/artist-profiles/roster/artist-invitation-emails.php`)
- Pending invitation tracking and management (`inc/artist-profiles/roster/manage-roster-ui.php`)
- REST API-powered roster management via extrachill-api plugin filter hooks (`inc/artist-profiles/roster/roster-filter-handlers.php`)
- Token-based invitation acceptance system
- Data functions and validation (`inc/artist-profiles/roster/roster-data-functions.php`)

#### Breadcrumb Integration
**File**: `inc/artist-profiles/frontend/breadcrumbs.php`
- Uses theme's extensibility filter hooks for breadcrumb customization
- `extrachill_breadcrumbs_root` filter for root link override (Extra Chill → Artist Platform)
- `extrachill_breadcrumbs_override_trail` filter for custom breadcrumb trails
- Homepage trail: "Artist Platform" (no link) to prevent "Archives" suffix
- Artist profile trail: "Artist Name" with proper hierarchy

#### Blog Coverage Integration
**File**: `inc/artist-profiles/blog-coverage.php`
- Links artist profiles to main site (blog ID 1) taxonomy archives
- `extrachill_artist_get_taxonomy_by_slug()` - Queries main site for matching artist taxonomy
- `extrachill_artist_display_blog_coverage_button()` - Renders "View Blog Coverage" button
- Cross-site blog switching with try/finally pattern for safe multisite operations

#### Edit Icon System
**Location**: `inc/link-pages/live/ajax/`, `inc/link-pages/live/assets/js/`, `assets/css/`

**Architecture**:
- **Client-Side Permission Check**: JavaScript-only rendering with zero server-side HTML for security
- **CORS Request Flow**: extrachill.link → artist.extrachill.com REST API with credentials
- **Permission Validation**: Uses `ec_can_manage_artist()` permission system
- **Dynamic Rendering**: Edit button only appears in DOM if user has permission

**Files**:
- `inc/link-pages/live/ajax/edit-permission.php` - REST API endpoint enqueuer with CORS support
- `inc/link-pages/live/assets/js/link-page-edit-button.js` - Permission check and button rendering
- `assets/css/extrch-links.css` (lines 243-264) - Fixed position edit button styles

**CORS Headers**:
- `Access-Control-Allow-Origin: https://extrachill.link`
- `Access-Control-Allow-Credentials: true`
- `Access-Control-Allow-Methods: POST, GET, OPTIONS`
- `Access-Control-Allow-Headers: Content-Type`

**Integration Points**:
- Hooked via `extrch_link_page_minimal_head` action (priority 10)
- Localized script data: `api_url` (REST endpoint), `artist_id`
- Requires WordPress authentication cookies accessible from extrachill.link domain

**Cross-Domain Authentication**:
- Relies on WordPress cookies with SameSite=None; Secure attributes for cross-domain access
- Cookie configuration managed by extrachill-users plugin (extrachill-users/inc/auth/extrachill-link-auth.php)
- Edit button JavaScript sends credentials via `fetch()` with `credentials: 'include'` parameter

### Asset Management
**Class**: `ExtraChillArtistPlatform_Assets` in `inc/core/artist-platform-assets.php`
- File existence checks before enqueuing
- Timestamp-based cache busting via `filemtime()`
- Conditional loading based on template context
- Context-aware asset loading for different page types
- **Join flow asset loading**: `enqueue_join_flow_assets()` - Loads join flow CSS/JS on login page with `from_join` parameter detection

#### Gutenberg Block System

**Location**: `src/blocks/`

Modern React-based Gutenberg blocks providing comprehensive platform management interface with five specialized blocks:

**1. Link Page Editor Block** (`src/blocks/link-page-editor/`)

Complete React-based Gutenberg block for link page editing with live preview:

**Block Structure**:
- **Block Entry**: `src/blocks/link-page-editor/index.js` - Block registration and setup
- **Block Definition**: `src/blocks/link-page-editor/block.json` - Block metadata and attributes
- **Block Edit Component**: `src/blocks/link-page-editor/edit.js` - Main editor interface
- **Block Render**: `src/blocks/link-page-editor/render.php` - Server-side block rendering
- **Styles**: `src/blocks/link-page-editor/editor.scss`, `style.scss` - Block styling

**React Components** (`src/blocks/link-page-editor/components/`):
- **Editor.js**: Main editor container managing tabs and state
- **Preview.js**: Live preview component showing real-time updates
- **JumpToPreview.js**: Mobile navigation button to jump to preview panel
- **Tab Components** (`tabs/`):
  - `TabInfo.js`: Artist info, title, and biography editing
  - `TabLinks.js`: Link management with drag-and-drop reordering
  - `TabCustomize.js`: Styling options (fonts, colors, backgrounds)
  - `TabAdvanced.js`: Advanced settings (tracking, expiration, YouTube)
  - `TabSocials.js`: Social platform link management
- **Shared Components** (`shared/`):
  - `ColorPicker.js`: Color selection interface
  - `ImageUploader.js`: Media upload handler
  - `DraggableList.js`: Drag-and-drop list component
  - `LinkPageUrl.js`: Display canonical link page URL
  - `QRCodeModal.js`: QR code generation and download

**Context System** (`context/`):
- **EditorContext.js**: Manages editor state and tab navigation
- **PreviewContext.js**: Manages preview data and live updates

**Custom Hooks** (`hooks/`):
- `useArtist.js`: Hook for artist data and metadata
- `useLinks.js`: Hook for link management and updates
- `useMediaUpload.js`: Hook for media upload handling
- `useSocials.js`: Hook for social platform management

**2. Link Page Analytics Block** (`src/blocks/artist-analytics/`)

Dedicated Gutenberg block providing comprehensive analytics interface:

**Features**:
- Chart.js-powered analytics dashboard
- Daily page view aggregation
- Link click tracking and breakdown
- Date range filtering
- Artist context switching for multi-artist management

**Components**:
- **Analytics.js**: Main analytics dashboard component
- **ArtistSwitcher.js**: Artist context switching interface
- **AnalyticsContext.js**: Context for analytics data management
- **Custom Hooks**: useAnalytics for analytics queries

**3. Artist Manager Block** (`src/blocks/artist-manager/`)

Complete artist profile management interface:

**Features**:
- Artist information and biography editing
- Profile image upload and management
- Social link management (integrated with social platform system)
- Roster/member management with team invitations
- Subscriber list management and export

**Tab Structure**:
- **TabInfo**: Artist name, biography, profile image
- **TabSocials**: Social platform link management
- **TabMembers**: Band member invitation and management
- **TabSubscribers**: Email subscriber list and export

**4. Artist Creator Block** (`src/blocks/artist-creator/`)

Dedicated Gutenberg block for artist profile creation:

**Features**:
- Guided artist profile creation with user permission checks
- Profile metadata initialization (name, biography, images)
- User prefill from authenticated context
- Automatic link page creation for new profiles
- REST API integration for save operations

**5. Artist Shop Manager Block** (`src/blocks/artist-shop-manager/`)

Comprehensive shop product management with Stripe integration:

**Location**: `src/blocks/artist-shop-manager/`

**Features**:
- Complete shop product CRUD operations (create, read, update, delete)
- Product media uploads and management (up to 5 images per product)
- Inventory tracking and management with size variants
- Order management with fulfillment tracking
- Stripe Connect integration for payment processing
- Shipping configuration and label purchasing
- Product status management (draft/published)
- Sale price and pricing management

**Component Structure**:

**ProductsTab** (`components/tabs/ProductsTab.js`):
- Product creation and editing form
- Drag-and-drop image reordering
- Size variant support via STANDARD_SIZES array (XS, S, M, L, XL, XXL)
- Individual size stock tracking
- Product name, price, sale price, and description editing
- Product publishing with Stripe validation (requires Stripe Connect for public products)
- Draft/published status selection
- Stripe connection status checking with helpful messaging
- Image upload with preview generation and cleanup
- Product listing display with status indicators and quick actions
- Drag-and-drop image management (up to 5 images)
- Image deletion and reordering via REST API
- Size display on product cards with out-of-stock indicators

**OrdersTab** (`components/tabs/OrdersTab.js`):
- Order list with filtering (All, Needs Fulfillment, Completed)
- Order detail view with customer and shipping information
- Shipping label purchasing ($5 flat rate USPS)
- Tracking number management and entry
- Order status management and updates
- Refund processing with confirmation
- Customer information display (name, email, shipping address)
- Item-level order details with quantities and totals
- Artist payout calculation and display
- Shipping label reprinting capability

**PaymentsTab** (`components/tabs/PaymentsTab.js`):
- Stripe Connect status display
- Connection flow with OAuth redirect
- Account details verification status
- Charges and payouts enablement status
- Stripe dashboard access for account management
- Payment capability status checking
- Note display for pending/restricted accounts
- Status refresh functionality

**ShippingTab** (`components/ShippingTab.js`):
- Shipping address form (required for order fulfillment)
- Full name, street address (primary and secondary), city, state, ZIP code, country
- US state dropdown selection
- Form validation and error handling
- Address persistence via REST API
- Success feedback after save

**REST API Integration**:
- `createShopProduct()`: Create new product with status
- `updateShopProduct()`: Update product and publish
- `deleteShopProduct()`: Move product to trash
- `uploadShopProductImages()`: Upload product images with preview generation
- `deleteShopProductImage()`: Delete specific product image
- `purchaseShippingLabel()`: Purchase USPS shipping label
- `getArtistShippingAddress()`: Fetch configured shipping address
- `updateArtistShippingAddress()`: Save/update shipping address

**Validation & Constraints**:
- Product name required
- Price must be greater than zero
- At least one image required for published products
- Stripe Connect required for publishing (can_receive_payments)
- Size variant management with auto-total calculation
- Maximum 5 images per product
- Inventory tracking toggles based on size usage or manual stock entry

**Stripe Integration**:
- **Connection Status**: Checks `can_receive_payments`, `charges_enabled`, `payouts_enabled`
- **Account Status**: Displays `connected`, `pending`, `restricted` states
- **Details Verification**: Shows `details_submitted` status
- **Automatic Validation**: Prevents product publishing until Stripe is fully set up
- **Error Messaging**: Clear guidance on Stripe setup requirements before publishing

**Size Variants**:
- Fixed size set: XS, S, M, L, XL, XXL
- Per-size inventory tracking
- Toggle to enable/disable size variant tracking
- Auto-calculation of total inventory across sizes
- Size display on product cards in storefront

**REST API Integration** (All Blocks):
- `client.js`: Unified API client for all block requests located at `src/blocks/shared/api/client.js`
- Single source of truth for REST API calls across all blocks
- Handles image uploads, saves, and data fetching
- Automatic nonce handling and error management
- Used by link-page-editor, artist-analytics, artist-manager, artist-creator, and artist-shop-manager blocks

**Build Configuration**:
- **Webpack**: `webpack.config.js` - Compiles React and SCSS for all blocks
- **wp-scripts**: WordPress build tooling for React/Webpack integration
- **Compiled Output**: `build/blocks/*/` - Generated assets for each block
- **Asset Enqueuing**: Auto-detected via `register_block_type()` manifest
- **Build Process**: `npm run build` compiles all blocks to production bundle

**Block Registration**:
```php
// Registered in extrachill-artist-platform.php
function extrachill_artist_platform_register_blocks() {
    register_block_type( __DIR__ . '/build/blocks/link-page-editor' );
    register_block_type( __DIR__ . '/build/blocks/artist-analytics' );
    register_block_type( __DIR__ . '/build/blocks/artist-manager' );
    register_block_type( __DIR__ . '/build/blocks/artist-creator' );
    register_block_type( __DIR__ . '/build/blocks/artist-shop-manager' );
}
add_action( 'init', 'extrachill_artist_platform_register_blocks' );
```

**Block-Based Architecture**:
- **Gutenberg Block Editor**: Modern React-based interface for WordPress block editor (primary)
- **Management Pages**: The plugin auto-creates standard pages that mount blocks (see `extrachill_artist_platform_create_pages()` in `extrachill-artist-platform.php`):
  - `/create-artist/` (artist-creator)
  - `/manage-artist/` (artist-manager)
  - `/manage-link-page/` (link-page-editor)
  - `/manage-shop/` (artist-shop-manager)
- **Post Type Editing Context**:
  - Artist profiles: `artist_profile` post type (managed via artist-manager)
  - Link pages: `artist_link_page` post type (managed via link-page-editor)
- **Analytics**: Separate `artist-analytics` block for analytics dashboards
- **REST API**: All management operations via REST API, not traditional AJAX
- **Unified Data**: Blocks use centralized data functions (ec_get_link_page_data, ec_get_artist_profile_data)

#### Public Link Page Scripts
**Directory**: `inc/link-pages/live/assets/js/`
- `link-page-public-tracking.js` - Analytics and click tracking
- `link-page-subscribe.js` - Subscription form functionality
- `link-page-youtube-embed.js` - YouTube video embed handling
- `extrch-share-modal.js` - Native Web Share API with social fallbacks
- `link-page-edit-button.js` - CORS-based permission checking for edit button visibility

**Global Components**: `assets/js/`
- `artist-switcher.js` - Artist selection dropdown for switching contexts
- `artist-platform.js` - Core plugin functionality
- `artist-platform-home.js` - Homepage-specific features

**Join Flow Interface**: `inc/join/assets/js/`
- `join-flow-ui.js` - Modal handling for existing vs new account selection

**Key JavaScript Modules**:
- `join-flow-ui.js` - Join flow modal UI (login/register)
- `link-page-public-tracking.js` - Link page view + click tracking
- `link-page-subscribe.js` - Link page subscription form handling
- `link-page-youtube-embed.js` - YouTube embed handling
- `extrch-share-modal.js` - Share modal UI
- `link-page-edit-button.js` - Edit button permission check + rendering

### Database Schema

#### Analytics Tables
```sql
CREATE TABLE {prefix}_extrch_link_page_daily_views (
    view_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    view_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (view_id),
    UNIQUE KEY unique_daily_view (link_page_id, stat_date)
);

CREATE TABLE {prefix}_extrch_link_page_daily_link_clicks (
    click_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    link_page_id bigint(20) unsigned NOT NULL,
    stat_date date NOT NULL,
    link_url varchar(2083) NOT NULL,
    click_count bigint(20) unsigned NOT NULL DEFAULT 0,
    PRIMARY KEY (click_id),
    UNIQUE KEY unique_daily_link_click (link_page_id, stat_date, link_url(191)),
    KEY link_page_date (link_page_id, stat_date)
);
```

#### Subscription Table
```sql
CREATE TABLE {prefix}_artist_subscribers (
    subscriber_id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    artist_profile_id BIGINT(20) UNSIGNED NOT NULL,
    subscriber_email VARCHAR(255) NOT NULL,
    username VARCHAR(60) NULL DEFAULT NULL,
    subscribed_at DATETIME NOT NULL,
    exported TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (subscriber_id),
    UNIQUE KEY email_artist (subscriber_email, artist_profile_id),
    KEY artist_profile_id (artist_profile_id),
    KEY exported (exported)
);
```

## Plugin Integration

### Cross-Plugin Dependencies

**Required Plugins**:
- **extrachill-users**: Provides user management and authentication features

**Recommended Plugins**:
- **extrachill-users**: Provides avatar menu system, user creation filter, join flow integration, and **artist profile functions** (`ec_get_artists_for_user()`, `ec_can_create_artist_profiles()`)
- **extrachill-multisite**: Network-wide functionality and cross-site data access

**Artist Profile Functions** (from extrachill-users plugin):
- All user-artist profile relationship functions now live in extrachill-users/inc/artist-profiles.php
- Network-wide availability (extrachill-users is network-activated)
- Functions handle multisite blog switching automatically
- This plugin uses these functions throughout templates and management interfaces

#### Programmatic Link Addition API
**File**: `inc/core/actions/add.php`
- WordPress action-based link addition: `do_action('ec_artist_add_link', $link_page_id, $link_data, $user_id)`
- Sanitization filter: `apply_filters('ec_sanitize_link_data', $link_data)` - Cleans link text, URL, expiration, and ID
- Validation filter: `apply_filters('ec_validate_link_data', true, $link_data)` - Validates required fields and URL format
- Success/failure action hooks: `ec_artist_link_added`, `ec_artist_link_add_failed` - For logging, notifications, webhooks
- Permission validation: Uses existing `ec_can_manage_artist()` permission system
- Data integration: Works with centralized `ec_get_link_page_data()` and `ec_handle_link_page_save()` functions
- Section/position support: Optional parameters for link placement within sections

### Homepage Action Hooks System

**File**: `inc/home/homepage-hooks.php`

Centralized hook registrations for artist platform homepage functionality using theme's universal routing system.

**Template Override**:
- Filter: `extrachill_template_homepage` (provided by theme's template-router.php)
- Blog ID detection: Only overrides on artist.extrachill.com (blog ID 4)
- Template path: `inc/home/templates/homepage.php`

**Action Hooks**:
- `extrachill_artist_home_hero` - Renders hero section with user-state-aware welcome messages
  - Template: `inc/home/templates/hero.php`
  - Parameters: `$current_user`, `$is_logged_in`, `$can_create_artists`, `$user_artist_ids`
- `extrachill_above_artist_grid` - Renders content above artist directory grid
  - "Your Artists" section: `inc/home/templates/your-artists.php` (priority 10)
  - Support buttons: Inline rendering (priority 15)

**Supporting File**: `inc/home/homepage-artist-card-actions.php` - Card rendering actions for artist grid

### Join Flow Integration

**File**: `inc/join/join-flow.php`

Integrates with extrachill-users plugin to provide artist platform onboarding via modal.

**Action Hook Used**: `extrachill_below_login_register_form`

**Integration Pattern**:
```php
add_action( 'extrachill_below_login_register_form', 'ec_render_join_flow_modal' );
```

**Provided Features**:
- **Modal Interface**: Renders join flow modal for account selection (existing vs new)
- **Asset Loading**: Conditional enqueuing of join flow CSS/JS when `from_join` parameter detected
- **Post-Auth Routing**: Routes users to creation or management workflows based on artist status
  - `/create-artist/` for profile creation (artist-creator block)
  - `/manage-link-page/` for existing artists (link-page-editor block)
- **URL Parameter Detection**: Detects `from_join` parameter to trigger join flow interface

### Cross-Site Functionality


**Main Site Integration (extrachill.com)**:
- Artist profile display and navigation
- Cross-site theme integration for consistent styling

**Artist Site (artist.extrachill.com)**:
- Homepage template override for artist grid
- Management interfaces for profiles and link pages
- Analytics dashboard and subscription management

### Dependencies
- **WordPress**: 5.0+ (tested up to 6.4)
- **PHP**: 7.4+
- **Plugin Dependencies**: extrachill-users
- **External**: Font Awesome (icons).

### Additional Features

#### Link Expiration System
**Files**: `inc/link-pages/management/advanced-tab/link-expiration.php`, `src/blocks/link-page-editor/components/tabs/TabAdvanced.js`
- Time-based link lifecycle management
- Automatic link deactivation and scheduling
- Gutenberg block integration with expiration display

#### Drag-and-Drop Interface
**File**: `src/blocks/link-page-editor/components/tabs/TabLinks.js`
- dnd-kit integration for link reordering
- Touch-friendly drag-and-drop for mobile devices
- Real-time preview updates during reordering

#### Social Platform Integration
**File**: `inc/core/filters/social-icons.php`
- **Comprehensive Platform Support**: 15+ social platforms including Apple Music, Bandcamp, Bluesky, Facebook, GitHub, Instagram, Patreon, Pinterest, SoundCloud, Spotify, TikTok, Twitch, Twitter/X, YouTube, and custom links
- **Smart Icon Management**: Font Awesome icon class validation with fallback handling
- **URL Validation**: Automatic protocol addition and comprehensive URL sanitization
- **Custom Labels**: Support for custom link labels (e.g., custom website links)
- **CRUD Operations**: Complete social link lifecycle management with permission validation
- **Rendering System**: Flexible HTML output with customizable container classes and accessibility attributes

#### Unified Artist Card System
**Template**: `inc/artist-profiles/frontend/templates/artist-card.php`
**Styles**: `assets/css/artist-card.css`
- Reusable artist profile card component (renamed from artist-profile-card.php)
- Used across homepage, directory, and archive displays
- Supports WordPress loop context with global $post
- Hero section with profile and header images
- Displays artist name, genre, location metadata
- Extensible action hook: `ec_artist_card_actions` for additional buttons
- Extracted styles from artist-platform-home.css for modularity

#### Artist Context Switching
**File**: `assets/js/artist-switcher.js`
- Artist selection dropdown for multi-artist management
- Context-aware UI updates based on selected artist
- Seamless switching between artist profiles

#### Artist Grid System
**File**: `inc/artist-profiles/frontend/artist-grid.php`
- Activity-based artist sorting with comprehensive timestamp calculation
- User exclusion logic for personalized displays
- Link page activity tracking for sorting
- Responsive grid layouts with context-aware rendering
- Template integration via `ec_render_template()` system

#### Permissions
**File**: `inc/core/filters/permissions.php`
- Centralized permission checks for managing artists and profiles

#### Centralized Save System
**Core Files**: `inc/core/actions/save.php`
- **Unified save operations**: `ec_handle_link_page_save()`, `ec_handle_artist_profile_save()`
- **Data preparation**: `ec_prepare_link_page_save_data()`, `ec_prepare_artist_profile_save_data()`
- **File handling**: Centralized upload processing with cleanup of old attachments
- **Hook integration**: `ec_link_page_save`, `ec_artist_profile_save` action hooks
- **Admin post handlers**: Security-validated form submission processing

**Form Processing**: Pure WordPress form submission
- **Direct field processing**: All data processed via individual form fields
- **No JavaScript required**: Standard WordPress admin_post handler
- **Immediate validation**: Server-side sanitization and validation
- **Standard patterns**: Follows WordPress form processing conventions

**Management Interface**: Gutenberg block editor (React-based)
- Modern React components with tab-based interface in `src/blocks/link-page-editor/`
- REST API integration for all data operations via `src/blocks/shared/api/client.js`
- All management operations handled through Gutenberg block editor, no traditional PHP AJAX handlers

**Public Tracking (REST API)**: Lightweight modules for live page tracking
- Located in `inc/link-pages/live/ajax/` for public-facing operations
- **analytics.php**: Public tracking and analytics data with data pruning
- **edit-permission.php**: Real-time permission checking for live link pages

**Permission System**: Server-side permission validation
- Permission checks centralized via `inc/core/filters/permissions.php`
- Context-aware permission validation for REST API endpoints
- `ec_can_manage_artist()`, `ec_can_create_artist_profiles()` for general contexts
- Unified permission logic with proper nonce/authentication verification

#### Centralized Data System
**Core File**: `inc/core/filters/data.php`
- **`ec_get_link_page_data()`**: Single source of truth for all link page data
- Replaces scattered `get_post_meta()` calls with unified data provider function
- Supports live preview overrides and extensive data validation
- Provides comprehensive data for CSS variables, links, social icons, and advanced settings
- Applies WordPress filters for extensibility (`extrch_get_link_page_data`)
- Used throughout templates, REST API endpoints, and asset management

#### Data Synchronization
**Files**: `inc/core/actions/sync.php`, `inc/core/filters/data.php`
- Cross-system data consistency and component synchronization
- Centralized data filtering and validation
- Automatic sync triggers after save operations

#### Template System

**Dual Template Architecture**:

1. **Page Templates** (`inc/core/class-templates.php`)
   - Full page routing and template loading
   - WordPress template hierarchy integration
   - Context-aware template selection

2. **Component Templates** (`inc/core/filters/templates.php`)
   - Modular UI component rendering
   - Template filtering and customization

**Template Directories**:
- **Artist Profile Templates**: `inc/artist-profiles/frontend/templates/`
  - `single-artist_profile.php` - Artist profile single page
  - `artist-directory.php` - Artist directory archive
  - `artist-card.php` - Reusable artist card component (renamed from artist-profile-card.php)
   - `manage-artist-profile-tabs/` - Legacy tab templates (replaced by artist-manager block)
- **Homepage Templates**: `inc/home/templates/`
  - `homepage.php` - Main homepage template override
  - `hero.php` - Hero section with user-state-aware messaging
  - `your-artists.php` - "Your Artists" section above grid
- **Link Page Templates**: `inc/link-pages/live/templates/` (public), `inc/link-pages/management/templates/` (admin)
- **Component Templates**: `inc/link-pages/management/templates/components/` - Modular UI components
- **Core Templates**: `inc/core/templates/` - Base template components
- **Subscription Templates**: `inc/link-pages/live/templates/` - Email collection forms
- **Join Flow Templates**: `inc/join/templates/` - Join flow modal and account selection

#### Admin Interface
**Files**: `inc/artist-profiles/admin/meta-boxes.php`, `inc/artist-profiles/admin/user-linking.php`
- Custom meta boxes for artist profile management
- User account linking and validation
- Administrative assets: `inc/artist-profiles/assets/js/`

#### Custom CSS System
**File**: `assets/css/custom-social-icons.css`
- **Custom Social Icon Support**: Extends Font Awesome with additional social platforms (Substack, Venmo)
- **CSS Mask Technique**: Uses SVG masks to inherit parent text color and sizing
- **Font Awesome Integration**: Seamless integration with existing icon classes
- **Dynamic Color Inheritance**: Icons automatically adapt to theme colors

### Build System
**Core Files**: `build.sh -> ../../.github/build.sh`, `package.json`, `webpack.config.js`, `.buildignore`

**Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Automated Build Process**: Universal script auto-detects plugin type and creates production packages
- **Version Extraction**: Automatically extracts version from `Plugin Name:` header in main plugin file
- **File Filtering**: `.buildignore` provides rsync exclusion patterns for development files
- **Dependency Validation**: Checks for required tools (rsync, zip, composer)
- **Production Dependencies**: Runs `composer install --no-dev` before build, restores dev dependencies after
- **Vendor Inclusion**: WordPress plugins include `vendor/` directory with production dependencies (end users don't have Composer access)
- **Structure Validation**: Validates plugin integrity before packaging (ensures main file exists)
- **Output Location**: Creates `/build/extrachill-artist-platform.zip` non-versioned file only. The intermediate `/build/extrachill-artist-platform/` directory is temporary and removed during the build.

**Build Features**:
- **Clean Builds**: Automatic cleanup of previous `/build` artifacts (removes legacy `/dist` if exists)
- **Exclude Management**: Comprehensive file exclusion via `.buildignore` rsync patterns
- **Integrity Checks**: Plugin structure validation during build process
- **Progress Reporting**: Colored console output with success/error reporting
- **Archive Summary**: Total file count and ZIP size after successful build
- **Single Source of Truth**: Updates to `/.github/build.sh` automatically apply to all plugins/themes

**NPM Integration**: `package.json` provides build scripts and development tooling

**Block Build Process**: Webpack compilation for Gutenberg blocks
- **Build Command**: `npm run build` or `npm run start` for development
- **Webpack Config**: `webpack.config.js` - Compiles React components and styles
- **Compiled Output**: `build/blocks/link-page-editor/` - Generated compiled assets
- **Asset Integration**: Block assets enqueued via `register_block_type()` with auto-detected asset paths
- **wp-scripts Integration**: Uses WordPress @wordpress/scripts for standards compliance

## Development Standards

### Security Practices
- **Nonce verification**: All forms and REST API requests use WordPress nonce system
- **Input sanitization**: `wp_unslash()` before all sanitization functions
- **Data validation**: Type checking and allowlist validation for enums
- **Output escaping**: `esc_html()`, `esc_attr()`, `esc_url()` for all output
- **Permission checks**: Centralized `ec_can_manage_artist()` capability validation
- **File uploads**: Size limits, type validation, and cleanup of old attachments

### WordPress Patterns
- Uses WordPress native hooks and filters extensively
- Follows WordPress coding standards for translations
- Implements proper sanitization and escaping
- Uses WordPress HTTP API with custom timeout handling

### Code Organization
- Singleton pattern for core classes (`ExtraChillArtistPlatform`, `ExtraChillArtistPlatform_Templates`, `ExtraChillArtistPlatform_Assets`)
- Modular JavaScript organization by feature and functionality
- Centralized asset enqueuing with context-aware loading
- Template-based page routing system with nested organization
- Feature-based CSS organization with consolidated assets

### JavaScript Patterns

#### Key JavaScript Features
- **React-Based Block Editor**: Gutenberg block components for link page management
- **Responsive Tab System**: Editor.js provides tab management functionality for management interfaces
- **Drag-and-Drop**: dnd-kit-based reordering (see `src/blocks/link-page-editor/components/shared/DraggableList.js`)
- **REST API Integration**: Centralized API client for all data operations
- **Modern APIs**: Native Web Share API with social media fallbacks
- **Context-Aware Loading**: Asset management with conditional enqueuing based on page context
- **Artist Context Switching**: Seamless multi-artist management with state preservation
- **Join Flow Modals**: Modal-based user onboarding for account selection

#### Save System Data Flow
1. **Gutenberg block editor** manages link page data and updates
2. **REST API client** sends updates to server via `src/blocks/shared/api/client.js`
3. **Security validation**: Nonce verification and permission checks
4. **Data persistence**: Meta updates and file uploads processed
5. **Sync trigger**: Action hooks trigger cross-system data synchronization
6. **User feedback**: Editor returns success/error messages

### FORBIDDEN FALLBACK TYPES
- Placeholder fallbacks for undefined data that should be required
- Legacy fallbacks for removed functionality
- Fallbacks that prevent code failure or hide broken functionality
- Fallbacks that provide dual support for multiple methods

### Planning Standards (Plan Mode)
- Create a specific and refined plan outlining all explicit changes exactly as they will be implemented
- Plans must explicitly identify which files and functions to modify, create, or delete
- When writing todos, always include excessive detail, intermediary steps, and files to modify/create
- Plans MUST align with existing codebase patterns
- All code review should be completed before you present the plan

### Special Rules
- All AI models in the codebase are correct. Do not change them.
- Verify all API documentation using the context7 mcp

### Documentation Standards
- Use concise inline docblocks at the top of files and/or critical functions to explain technicalities for developers
- Inline comments are reserved strictly for nuanced behavior that is not obvious from the readable code
- Actively remove outdated documentation, including references to deleted functionality