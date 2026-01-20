# ExtraChill Users

**THE SINGLE SOURCE OF TRUTH FOR USER MANAGEMENT** - Network-activated WordPress plugin providing all user-related functionality for the ExtraChill Platform multisite network. This plugin is the centralized authority for authentication (including Google OAuth), registration, password reset, user onboarding, cross-site user management, team member system, profile URL resolution, network-wide avatar menu, custom avatars, and online user tracking across all 11 active sites (Blog IDs 1–5, 7–12) with docs at Blog ID 10.

User management functionality was migrated here from extrachill-multisite plugin to follow the single responsibility principle. All user-specific features, authentication flows, and user data operations are consolidated in this plugin.

## Plugin Information

- **Name**: Extra Chill Users
- **Version**: 0.5.6
- **Text Domain**: `extrachill-users`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: true (network activated across all sites)
- **Requires Plugins**: extrachill-multisite, extrachill-api
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4

## Architecture

### Plugin Purpose
**Single Source of Truth for User Management** - This plugin is the centralized authority for all user-related functionality across the ExtraChill multisite network. Comprehensive user management system providing authentication, registration, password reset, team member management, profile URL resolution, custom avatars, online user tracking, and network-wide avatar menu functionality.

**Migration History**: User management features were extracted from extrachill-multisite plugin to follow the single responsibility principle. This plugin now handles ALL user-specific logic while extrachill-multisite focuses solely on multisite infrastructure (Cloudflare Turnstile and network admin menu).

### Plugin Loading Pattern
- **Procedural WordPress Pattern**: Uses direct `require_once` includes for all plugin functionality
- **Network Plugin Structure**: Network-activated plugin providing functionality across all multisite installations
- **Gutenberg Blocks**: Registers login/register and password reset blocks via `register_block_type()`
- **Modular Organization**: 17 include files organized by functionality domain

### Core Features

#### Google OAuth Integration (`inc/oauth/`)

**Google Sign-In System**:
- Server-side Google OAuth integration via `inc/oauth/google-service.php`
- RS256 ID token verification via `inc/oauth/jwt-rs256.php`
- Dedicated Google sign-in button with JavaScript handling via `assets/js/google-signin.js`
- Network-wide OAuth configuration managed by extrachill-multisite plugin
- REST API endpoint: `POST /wp-json/extrachill/v1/auth/google`

**OAuth Service Functions**:
- `ec_google_verify_id_token()` - Validates Google ID tokens using RS256
- `ec_google_get_or_create_user()` - Creates or retrieves user from Google profile
- Auto-login after successful Google authentication

**Configuration**: OAuth client ID and settings stored in network options via extrachill-multisite admin page (`admin/network-oauth-settings.php`)

#### User Onboarding System (`inc/onboarding/`, `blocks/onboarding/`)

**Onboarding Flow**:
- Gutenberg block for new user onboarding at `blocks/onboarding/`
- Service layer at `inc/onboarding/service.php` for onboarding logic
- REST API endpoints: `GET/POST /wp-json/extrachill/v1/users/onboarding`
- Tracks onboarding completion status in user meta

**Onboarding Block**:
- Registered via `register_block_type()` alongside login-register and password-reset blocks
- Provides guided first-time user experience
- Integrates with artist platform for artist onboarding flows

#### Admin Access Control (`inc/admin-access-control.php`)

**Network-Wide Admin Restriction**:
- Restricts wp-admin access to administrators only across entire multisite network
- Hides admin bar for non-administrator users
- Prevents login redirects to wp-admin for non-administrators
- Preserves AJAX functionality and admin-post.php access
- Safe redirects to homepage for unauthorized access attempts

**Functions**:
- `extrachill_redirect_admin()` - Redirects non-administrators away from wp-admin
- `extrachill_hide_admin_bar_for_non_admins()` - Hides admin bar for non-admin users
- `extrachill_prevent_admin_auth_redirect()` - Filters login redirect to prevent wp-admin access

#### Authentication System (`inc/auth/{login,register,password-reset,logout}.php` with supporting logic in `inc/core/`)

**Auth Handlers (inc/auth/)**:
- `inc/auth/login.php` monitors failed login attempts, intercepts `authenticate`, and routes wp-login.php access through the `/login/` UI without exposing wp-admin.
- `inc/auth/register.php` validates registration submissions (Turnstile, roster invites, newsletter integration) and delegates user creation to the core helpers before auto-logging the user via `EC_Redirect_Handler`.
- `inc/auth/password-reset.php` verifies `admin-post.php` submissions through `EC_Redirect_Handler::from_post()`, enforces nonces, and keeps the email/request/reset flow inside the custom UI while relying on WordPress reset helpers.
- `inc/auth/logout.php` filters logout URLs and processes custom logout requests with nonce verification so redirects stay consistent.

**Business Logic (`inc/core/`)**:
- `inc/core/user-creation.php` remains the single source of truth for community.extrachill.com user creation and registration metadata (`registration_page`, `registration_source`, `registration_method`) plus onboarding flags.
- `inc/core/registration-emails.php` sends branded HTML welcome messages immediately after registration.
- `inc/core/online-users.php` records network-wide activity for online status, notices, and dashboard widgets.

**Block Forms**:
- The compiled `build/login-register` block renders the login/register interfaces (tabs, Turnstile widget, roster invites, `source_url`/`success_redirect_url`, and universal notices) and posts to `admin-post.php` routes handled by the auth handlers.
- The compiled `build/password-reset` block renders the email request and reset forms inline, posts to the `admin-post.php` endpoints secured by `EC_Redirect_Handler`, and keeps users on the same UI while WordPress resets their password.

#### Online Users Tracking (`inc/core/online-users.php`)

**Network-Wide Activity Tracking**:
- Records user activity across all 11 active sites in the multisite network (Blog IDs 1–5, 7–12)
- Centralized data storage on community.extrachill.com as the single source of truth
- 15-minute activity window for "online" status determination
- Updates `last_active` user meta via the `wp` action hook on all sites

**Performance Optimizations**:
- Transient caching for online user counts (5-minute cache)
- Per-user activity update throttling (at least 15 minutes between updates)
- Cached "most ever online" check (5-minute cache)
- Total members count cached for 24 hours

**Most Ever Online Tracking**:
- Tracks highest concurrent user count with recorded date
- Stored in `most_ever_online` option on community.extrachill.com
- Format: `array('count' => 123, 'date' => 'm/d/Y')`

#### Custom Avatar Display System (`inc/avatar-display.php`)

**Avatar Display** (`inc/avatar-display.php`):
- Filters `pre_get_avatar` to provide custom avatars before Gravatar
- Uses `custom_avatar_id` user meta storing WordPress attachment ID
- Retrieves thumbnail size via `wp_get_attachment_image_url()`
- Proper multisite support via `get_user_option()` (checks network-wide)
- Automatic fallback to Gravatar if no custom avatar set
- Returns null to let WordPress handle Gravatar fallback

**Migration Utility**:
- `generate_custom_avatar_ids()` function for legacy `custom_avatar` URL migration
- Converts old URL-based avatars to attachment ID system
- Admin-only utility via `?generate_custom_avatar_ids=1` URL parameter

**Note**: Avatar upload UI moved to extrachill-community plugin (`inc/user-profiles/edit/avatar-upload.php`) as it's specific to bbPress profile edit integration. This plugin provides network-wide display logic; community plugin provides the upload interface. Both use the centralized REST API (`/wp-json/extrachill/v1/media`) for actual upload operations.

#### Gutenberg Blocks

**Login/Register Block** (`blocks/login-register/`, `build/login-register/`):
- Provides login/register interface as Gutenberg block
- Includes Google OAuth sign-in button integration
- Registered via `register_block_type()` in main plugin file
- Built with WordPress Scripts
- Source at `blocks/login-register/`, compiled to `build/login-register/`

**Password Reset Block** (`blocks/password-reset/`, `build/password-reset/`):
- Provides password reset interface as Gutenberg block
- Registered via `register_block_type()` in main plugin file
- Built with WordPress Scripts
- Source at `blocks/password-reset/`, compiled to `build/password-reset/`

**Onboarding Block** (`blocks/onboarding/`, `build/onboarding/`):
- Provides new user onboarding flow as Gutenberg block
- Guides users through initial profile setup
- Integrates with artist platform for artist onboarding
- Registered via `register_block_type()` in main plugin file
- Built with WordPress Scripts

#### Team Member Management System (`inc/team-members.php`)
**Functions**:
- `ec_is_team_member( $user_id = 0 )` - Check if user is Extra Chill team member with manual override support
- `ec_has_main_site_account( $user_id )` - Verify if user has account on extrachill.com main site

**User Meta Fields**:
- `extrachill_team` - Boolean flag (1 = team member, 0 = not team member)
- `extrachill_team_manual_override` - Manual override ('add' forces true, 'remove' forces false, empty/null uses standard check)

**Override Hierarchy**:
1. Check `extrachill_team_manual_override` meta (takes precedence)
2. If override is 'add' → return true
3. If override is 'remove' → return false
4. Fallback to `extrachill_team` meta value

**Cross-Site Integration**:
- Uses `switch_to_blog()` and `restore_current_blog()` for main site account verification
- Dynamic site discovery with automatic WordPress blog-id-cache for performance

#### User Creation System (`inc/user-creation.php`)
**Filter**: `extrachill_create_community_user`

**Function**: `ec_multisite_create_community_user( $user_id, $registration_data )`

**Purpose**: Single source of truth for user creation on community.extrachill.com regardless of which site initiates registration

**Registration Data Array**:
- `username` - Required username
- `password` - Required password
- `email` - Required email address
- `from_join` - Boolean: whether registration starts from the join flow
- `registration_page` - Optional URL for where registration happens
- `registration_source` - Optional string (e.g. `web`, `extrachill-app`)
- `registration_method` - Optional string (e.g. `standard`, `google`)

**Workflow**:
1. Validate required fields (username, password, email)
2. Resolve community blog ID via `ec_get_blog_id( 'community' )`
3. Switch to community site if not already there
4. Create user with `wp_create_user()`
5. Persist registration metadata (when present) and set onboarding flags
6. Restore original blog context
7. Fire `extrachill_new_user_registered` action (on success)
8. Return user_id or WP_Error

**Used By**: Registration handler (`inc/auth/register.php`) for network-wide user creation

#### Artist Profile Functions (`inc/artist-profiles.php`)
**Purpose**: Network-wide canonical functions for user-artist profile relationships

**Core Functions**:

1. **`ec_get_artists_for_user( $user_id = null, $admin_override = false )`** - Single source of truth
   - **Default behavior** (`$admin_override = false`): Returns user's own published artist profile IDs
     - Checks `_artist_profile_ids` user meta
     - Verifies published status on artist.extrachill.com via blog switching
     - Includes artist profiles where user is post author
     - Used in frontend contexts (avatar menu, dashboards, grids, ownership checks)

   - **Admin override** (`$admin_override = true`): Admins get ALL published artists
     - Only activates for users with `manage_options` capability
     - Returns all published artist profiles in the network
     - Used ONLY in management page switcher dropdowns
     - Regular users still get their own artists even with override=true

   - **Context-aware design**: Single function handles both frontend and admin contexts
     - Frontend contexts → use default parameter (everyone sees their own artists)
     - Management dropdowns → use `$admin_override = true` (admins see all for administration)

2. **`ec_can_create_artist_profiles( $user_id = null )`**
   - Returns boolean - can user create new artist profiles?
   - Checks capabilities: `edit_pages` capability OR
   - User meta flags: `user_is_artist` OR `user_is_professional`
   - Permission-aware logic

**Artist Marketplace Metadata**:
- **Stripe Account ID**: Stored as `_stripe_account_id` on the `artist_profile` post (Blog ID 4)
- **Shipping Address**: Stored as `_shipping_address` array on the `artist_profile` post
- **Fulfillment**: Managed via the Shop Manager block using these identifiers

**Multisite Architecture**:
- All functions handle blog switching to artist.extrachill.com automatically
- Dynamic site discovery with automatic WordPress blog-id-cache for performance
- try/finally blocks ensure proper blog restoration
- Network-wide availability (plugin is network-activated)

**Used By**:
- extrachill-users: Avatar menu system (`inc/avatar-menu.php`)
- extrachill-artist-platform: All templates and management interfaces
- extrachill-chat: Add link to page tool (`inc/tools/artist-platform/add-link-to-page.php`)
- extrachill-stream: Artist membership authentication
- extrachill-community: Artist platform buttons
- Any plugin needing user-artist profile relationships

#### Cross-Site Artist Linking (`inc/artist-profiles.php`)
**Canonical Linking**:
- `ec_get_artist_profile_by_slug()` and `ec_render_cross_site_artist_profile_links()` have been migrated to the `extrachill-multisite` plugin (`inc/cross-site-links/`) to provide unified linking across the 11-site network.
- This plugin now consumes these centralized functions for all user-artist profile link resolution.

#### Author Profile URL Resolution (`inc/author-links.php`)
**Functions**:
- `ec_get_user_profile_url( $user_id, $user_email = '' )` - Centralized user profile URL logic
- `ec_get_comment_author_link_multisite( $comment )` - Generate comment author link HTML that routes to community profiles
- `ec_should_use_multisite_comment_links( $comment )` - Check if comment should use multisite linking (after Feb 9, 2024)
- `ec_display_author_community_link( $author_id )` - Display "View Community Profile" button on author archives
- `ec_customize_comment_form_logged_in( $defaults )` - Fix wp-admin profile links to route to community bbPress profile

**Multisite Routing**:
`ec_get_comment_author_link_multisite` ensures that comment authors are linked to their `community.extrachill.com` profiles rather than local site archives, maintaining identity consistency across the network.

**Resolution Logic**:
1. Try email lookup on community site → return bbPress profile URL
2. Try user_id lookup on community site → return bbPress profile URL
3. Fall back to main site author archive URL

**New Helpers**:
- `ec_get_user_community_profile_url( $user_id, $user_email = '' )` - Explicit community bbPress profile resolver
- `ec_get_user_author_archive_url( $user_id )` - Explicit main site author archive resolver (always returns a URL when possible)

**Usage Guidance**:
- Use `ec_get_user_profile_url()` for general-purpose profile links (mentions, avatar menus, “View Profile”) so identity always resolves to the community bbPress profile when possible.
- Use `ec_get_user_author_archive_url()` for article contexts (post bylines, “More articles by…”) where `/author/{slug}/` is desirable even if the user also has a community profile.

**URL Formats**:
- Community profiles: `https://community.extrachill.com/u/username/`
- Main site author archives: `https://extrachill.com/author/username/`

**Bidirectional Profile Linking**:
The platform provides bidirectional linking between author archives and community profiles:

1. **Community Profile → Author Archive**:
   - Community profile can show an "Extra Chill Articles" link to the author archive.
   - Gating (>= 1 published post) is handled in extrachill-community.

2. **Author Archive → Community Profile**:
   - Function: `ec_display_author_community_link( $author_id )`
   - Hook: `extrachill_after_author_bio` (priority 10)
   - URL: `https://community.extrachill.com/u/{user_nicename}/`
   - Conditional: Only displays if user exists on community.extrachill.com

**Integration**: Used by theme for comment author links, user profile display, and bidirectional profile navigation

#### Avatar Menu System (`inc/avatar-menu.php`)
**Function**: `extrachill_display_user_avatar_menu()`

**Hook**: `extrachill_header_top_right` at priority 30

**Filter**: `ec_avatar_menu_items` - Allows plugins to inject custom menu items

**Menu Structure**:
1. View Profile (bbPress profile URL)
2. Edit Profile (bbPress edit profile URL)
3. **Custom Menu Items** (via `ec_avatar_menu_items` filter)
4. Settings (site-specific settings page)
5. Log Out (WordPress logout with home URL redirect)

**Filter Usage Example**:
```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_avatar_menu', 10, 2 );

function my_plugin_avatar_menu( $menu_items, $user_id ) {
    $menu_items[] = array(
        'url'      => home_url( '/my-page/' ),
        'label'    => __( 'My Page', 'textdomain' ),
        'priority' => 10  // Lower numbers appear first
    );
    return $menu_items;
}
```

**Conditional Loading**: Avatar menu system loads network-wide (not site-specific)

#### Asset Management (`inc/assets.php`)
**Function**: `extrachill_users_enqueue_avatar_menu_assets()`

**Assets Loaded**:
- `assets/css/avatar-menu.css` - Avatar dropdown styling
- `assets/css/online-users.css` - Online users widget styling
- `assets/css/user-badges.css` - User badge styling (artist, team, professional)
- `assets/js/avatar-menu.js` - Avatar dropdown functionality

**Loading Pattern**:
- Avatar menu and online users assets load network-wide
- User badges CSS loads network-wide with `extrachill-root` dependency
- Uses `filemtime()` versioning for cache busting
- Checks file existence before enqueuing

#### User Badge Styling (`assets/css/user-badges.css`)
**Purpose**: Provides consistent badge styling for artists, team members, and professionals across the network.

**Badge Classes**:
- `.user-is-artist` - Artist badge with pink color (`--artist-badge-color`)
- `.extrachill-team-member` - Team member badge with cyan color (`--team-badge-color`)
- `.user-is-professional` - Professional badge with purple color (`--professional-badge-color`)

**Features**:
- CSS custom properties for badge colors (supports light/dark mode)
- Tooltip display via `data-title` attribute and CSS `::before` pseudo-element
- Inline-flex display for proper icon alignment
- Network-wide availability (loaded on all sites)

#### Registration Emails (`inc/registration-emails.php`)
**Welcome Email System**:
- Sends HTML welcome email after successful registration
- Includes user information and getting started links
- Custom email template with Extra Chill branding

#### Comment Auto-Approval System (`inc/comment-auto-approval.php`)
**Logged-In User Comment Auto-Approval**:
- Automatically approves comments from logged-in users, bypassing moderation queue
- Non-logged-in users follow standard WordPress comment moderation settings
- Simple security-focused implementation for trusted user comments

**Function**: `ec_auto_approve_logged_in_comments( $approved, $commentdata )`

**Hook**: `pre_comment_approved` filter at priority 10

**Implementation**:
- Checks if user is logged in via `is_user_logged_in()`
- Returns approval status `1` for logged-in users, effectively auto-approving their contributions.
- Returns default approval status for non-logged-in users

**Purpose**: Improve user experience by immediately approving comments from authenticated users while maintaining moderation for anonymous comments

#### Lifetime Extra Chill Membership System (`inc/lifetime-membership.php`)
**Membership Management**:
- `is_user_lifetime_member( $userDetails = null )` - Validate if user has Lifetime Extra Chill Membership (reads user meta; primary benefit is ad-free experience)
- `ec_create_lifetime_membership( $username, $order_data )` - Create Lifetime Extra Chill Membership for user (writes user meta)
- WordPress-native user meta storage (no custom tables, no blog switching)
- Meta key: `extrachill_lifetime_membership`
- Works network-wide (user meta accessible from all multisite sites)

**Note**: Management UI provided by the 'Lifetime Memberships' tool in `extrachill-admin-tools` (React-based tool consuming `/admin/lifetime-membership` REST endpoints).

**User Meta Structure**:
```php
// Meta key: extrachill_lifetime_membership
// Meta value (array):
array(
  'purchased' => '2024-10-27 14:30:00',  // MySQL datetime
  'order_id'  => 12345,                   // WooCommerce order ID
  'username'  => 'johndoe'                // Community username
)
```

**Integration Points**:
- **Theme**: Calls `is_user_lifetime_member()` in `header.php` to block Mediavine ads
- **Shop Plugin**: Calls `ec_create_lifetime_membership()` when WooCommerce orders complete
- **User Meta**: Stores purchase data in `extrachill_lifetime_membership` meta key

**Architecture**:
- **WordPress-Native Storage**: Uses user meta (KISS principle, no custom tables)
- **No Blog Switching**: User meta accessible network-wide without `switch_to_blog()`
- **Validation Function**: Lives in users plugin (reads user meta)
- **Creation Function**: Lives in users plugin (writes user meta)
- **Purchase Handler**: Lives in shop plugin (WooCommerce integration only)
- **Clean Separation**: Users plugin owns data operations, shop plugin owns WooCommerce UI

## File Structure

```
extrachill-users/
├── extrachill-users.php           (main plugin file)
├── inc/
│   ├── team-members.php           (team member functions)
│   ├── admin-access-control.php   (admin access restriction)
│   ├── author-links.php           (profile URL resolution)
│   ├── artist-profiles.php        (artist profile functions - network-wide canonical)
│   ├── auth/
│   │   ├── class-redirect-handler.php (redirect handler class)
│   │   ├── login.php              (login handler with `EC_Redirect_Handler`)
│   │   ├── register.php           (registration handler with newsletter, roster require)
│   │   ├── logout.php             (custom logout handler)
│   │   └── password-reset.php     (password reset handler)
│   ├── auth-tokens/
│   │   ├── bearer-auth.php        (bearer token authentication)
│   │   ├── db.php                 (token database operations)
│   │   ├── service.php            (token service layer)
│   │   └── tokens.php             (token generation and validation)
│   ├── badges/
│   │   └── user-badges.php        (user badge system)
│   ├── core/
│   │   ├── activation.php         (plugin activation)
│   │   ├── online-users.php       (activity tracking)
│   │   ├── registration-emails.php (welcome email system)
│   │   └── user-creation.php      (community user creation filter)
│   ├── oauth/
│   │   ├── google-service.php     (Google OAuth integration)
│   │   └── jwt-rs256.php          (RS256 ID token verification)
│   ├── onboarding/
│   │   └── service.php            (user onboarding service)
│   ├── rank-system/
│   │   └── rank-tiers.php         (user rank tier system)
│   ├── avatar-display.php         (custom avatar display - network-wide)
│   ├── avatar-menu.php            (avatar menu display)
│   ├── comment-auto-approval.php  (comment auto-approval for logged-in users)
│   ├── lifetime-membership.php    (membership validation)
│   └── assets.php                 (asset enqueuing)
├── assets/
│   ├── css/
│   │   ├── avatar-menu.css        (avatar menu styles)
│   │   ├── online-users.css       (online users widget styles)
│   │   └── user-badges.css        (user badge styling - artist, team, professional)
│   └── js/
│       ├── auth-utils.js          (authentication utilities)
│       ├── avatar-menu.js         (avatar menu JavaScript)
│       └── google-signin.js       (Google OAuth sign-in handler)
├── blocks/
│   ├── login-register/            (Login/Register Gutenberg block source)
│   ├── onboarding/                (User Onboarding Gutenberg block source)
│   └── password-reset/            (Password Reset Gutenberg block source)
├── build/
│   ├── login-register/            (Compiled Login/Register block)
│   ├── onboarding/                (Compiled Onboarding block)
│   └── password-reset/            (Compiled Password Reset block)
├── docs/
│   ├── CHANGELOG.md               (version history)
│   └── user-management.md         (user management documentation)
├── package.json                   (npm dependencies for blocks)
├── composer.json                  (dev dependencies)
├── .buildignore                   (build exclusions)
└── CLAUDE.md                      (architectural documentation)
```

## Technical Implementation

### WordPress Multisite Patterns
**Blog Switching Architecture**:
```php
if ( $blog_id = ec_get_blog_id( 'community' ) ) {
    try {
        switch_to_blog( $blog_id );
        // Cross-site database operations
        $user = get_userdata( $user_id );
    } finally {
        restore_current_blog();
    }
}
```

**Dynamic Site Discovery**:
- Uses `get_sites()` to enumerate network sites
- Automatic WordPress blog-id-cache for optimal performance
- Maintainable and flexible across network changes

### Plugin Loading Strategy
**Main Plugin File** (`extrachill-users.php`):
- Network activation check (requires multisite installation)
- Defines plugin constants (VERSION, PLUGIN_FILE, PLUGIN_DIR, PLUGIN_URL)
- Registers Gutenberg blocks via `register_block_type()` in `init` action
- Loads 18 core includes via `plugins_loaded` action
- Registers newsletter integration via `newsletter_form_integrations` filter

**Block Registration** (via `extrachill_users_register_blocks()`):
- `build/login-register` - Login/Register block
- `build/password-reset` - Password Reset block

**Include Loading Order** (via `extrachill_users_init()`):
1. `inc/auth/class-redirect-handler.php` - Redirect handler class
2. `inc/auth/login.php` - Login handler (EC_Redirect_Handler)
3. `inc/auth/register.php` - Registration handler with newsletter and roster invite handling
4. `inc/auth/logout.php` - Custom logout handler
5. `inc/auth/password-reset.php` - Password reset handler
6. `inc/core/online-users.php` - Activity tracking
7. `inc/core/registration-emails.php` - Welcome email system
8. `inc/core/user-creation.php` - User creation filter
9. `inc/team-members.php` - Team member functions
10. `inc/admin-access-control.php` - Admin access restriction
11. `inc/author-links.php` - Profile URL resolution
12. `inc/artist-profiles.php` - Artist profile functions (network-wide canonical)
13. `inc/assets.php` - Asset management
14. `inc/avatar-display.php` - Avatar display (loads network-wide)
15. `inc/avatar-menu.php` - Avatar menu (loads network-wide)
16. `inc/comment-auto-approval.php` - Comment auto-approval system
17. `inc/lifetime-membership.php` - Lifetime membership validation

**Note**: Avatar upload UI moved to extrachill-community plugin for bbPress integration


## Development Standards

### Code Organization
- **Procedural Pattern**: Direct `require_once` includes throughout
- **WordPress Standards**: Full compliance with network plugin development guidelines
- **Security Implementation**: Proper escaping, sanitization, and cross-site data access patterns
- **Performance Focus**: Conditional loading, domain-based site resolution, WordPress native caching

### Build System
- **Homeboy**: Use `homeboy build extrachill-users` for production builds
- **Auto-Detection**: Homeboy auto-detects network plugin from `Network: true` header
- **Production Build**: Creates `/build/extrachill-users.zip` file only
- **Test Infrastructure**: Homeboy provides universal PHPUnit, PHPCS, and ESLint
- **Composer Integration**: Production builds use `composer install --no-dev`, restores dev dependencies after
- **File Exclusion**: `.buildignore` rsync patterns exclude development files

## Dependencies

### PHP Requirements
- **PHP**: 7.4+
- **WordPress**: 5.0+ multisite network
- **Multisite**: Requires WordPress multisite installation (enforced on activation)
- **Requires Plugins**: extrachill-multisite (WordPress 6.5+ native dependency)

### Plugin Dependencies
- **extrachill-multisite**: Required for Turnstile captcha (`ec_render_turnstile_widget()`, `ec_verify_turnstile_response()`)
- **extrachill-api**: Required for REST API infrastructure
- **extrachill-newsletter**: Required for newsletter subscription (`extrachill_multisite_subscribe()` function)
- **extrachill-artist-platform** (optional): Optional integration for roster invitation acceptance during registration (`bp_get_pending_invitations()`, `bp_add_artist_membership()`, `bp_remove_pending_invitation()`)

### Development Dependencies
- **PHP CodeSniffer**: WordPress coding standards compliance
- **PHPUnit**: Unit testing framework
- **WPCS**: WordPress Coding Standards ruleset
- **WordPress Scripts**: Gutenberg block development and build tooling
- **npm**: Package manager for JavaScript dependencies

### WordPress Integration
- **Network Activation**: Must be network activated to function properly
- **Multisite Functions**: Leverages native `switch_to_blog()` and `restore_current_blog()`
- **Cross-Site Data**: Uses WordPress multisite database structure for cross-site access
- **Gutenberg Blocks**: Login/Register and Password Reset blocks for block editor

## Key Functionality

### Team Member System
**Purpose**: Identify Extra Chill staff and contributors across the network

**Integration Points**:
- extrachill-community plugin: Forum badges for team members
- extrachill-admin-tools plugin: Team member management interface
- Any plugin can check team status via `ec_is_team_member()`

**Manual Override Use Cases**:
- Fired staff: Set override to 'remove' to immediately revoke team status
- Community moderators: Set override to 'add' to grant team member privileges without main site account

### User Creation System
**Purpose**: Ensure all users created via registration forms exist on community.extrachill.com

**Why Community Site**:
- Single source of truth for user database
- bbPress integration requires users on community site
- Cross-domain authentication via WordPress multisite

**Integration**: Registration handler (`inc/auth/register.php`) calls `apply_filters('extrachill_create_community_user', ...)` during registration

### Profile URL Resolution
**Purpose**: Intelligently route users to correct profile URLs based on account location

**Use Cases**:
- Comment author links on main site
- User mention links in forums
- Theme author bio boxes

**Fallback Chain**: Main site → Community email lookup → Community ID lookup → Default author URL

### Avatar Menu System
**Purpose**: Provide consistent network-wide avatar dropdown menu

**Extensibility**: Other plugins can add menu items via `ec_avatar_menu_items` filter

**Current Integrations**:
- extrachill-artist-platform plugin: Adds artist profile management links
- Theme: Provides base menu structure and styling

### Authentication System
**Purpose**: Network-wide login, registration, and password reset functionality

**Key Features**:
- Custom login/register forms that never expose wp-admin
- Turnstile captcha integration for security
- Newsletter subscription during registration
- Roster invitation acceptance flow for artist platform
- Auto-login after registration and password reset
- Universal success notices via theme hook

### Online Users Tracking
**Purpose**: Network-wide activity tracking and online user statistics

**Key Features**:
- Tracks user activity across all 11 active sites in the multisite network (Blog IDs 1–5, 7–12)
- Centralized storage on community.extrachill.com
- Transient caching for performance optimization
- "Most ever online" tracking with date

### Custom Avatar System
**Purpose**: Custom avatar upload and display throughout the network

**Key Features**:
- WordPress attachment-based avatar storage
- bbPress profile edit integration
- Automatic Gravatar fallback
- Proper multisite support via `get_user_option()`

## Common Development Commands

### Building and Testing
```bash
# Install dependencies
composer install
npm install

# Build Gutenberg blocks
npm run build

# Development with hot reload (blocks)
npm run start

# Create production build (network plugin)
homeboy build extrachill-users

# Run PHP linting
homeboy lint extrachill-users

# Fix PHP coding standards
homeboy lint extrachill-users --fix

# Run tests
homeboy test extrachill-users
```

### Build Output
- **Production Package**: `/build/extrachill-users.zip` file only
- **Network Plugin**: Network-activate after deployment
- **File Exclusions**: Development files, vendor/, .git/, build tools excluded

## Integration Guidelines

### Using Team Member Functions
```php
// Check if user is team member
if ( function_exists( 'ec_is_team_member' ) && ec_is_team_member( $user_id ) ) {
    // Show team member content
}

// Check if user has main site account
if ( function_exists( 'ec_has_main_site_account' ) && ec_has_main_site_account( $user_id ) ) {
    // Link to main site author page
}
```

### Adding Avatar Menu Items
```php
add_filter( 'ec_avatar_menu_items', 'my_plugin_add_menu_item', 10, 2 );

function my_plugin_add_menu_item( $menu_items, $user_id ) {
    // Only add for specific user types
    if ( get_user_meta( $user_id, 'user_is_artist', true ) == '1' ) {
        $menu_items[] = array(
            'url'      => home_url( '/artist-dashboard/' ),
            'label'    => __( 'Artist Dashboard', 'my-plugin' ),
            'priority' => 5  // Appears near top of menu
        );
    }
    return $menu_items;
}
```

### Creating Users Programmatically
```php
$registration_data = array(
    'username'           => 'newuser',
    'password'           => wp_generate_password(),
    'email'              => 'user@example.com',
    'user_is_artist'     => true,
    'user_is_professional' => false
);

$user_id = apply_filters( 'extrachill_create_community_user', false, $registration_data );

if ( is_wp_error( $user_id ) ) {
    // Handle error
    error_log( $user_id->get_error_message() );
} else {
    // User created successfully on community.extrachill.com
    wp_set_auth_cookie( $user_id );
}
```

### Using Online Users Functions
```php
// Get current online users count
$online_count = ec_get_online_users_count();

// Display online users widget
ec_display_online_users_stats();
```

### Working with Custom Avatars
```php
// Avatar system automatically filters get_avatar() calls
// Custom avatars display automatically if user has custom_avatar_id meta

// Check if user has custom avatar
$avatar_id = get_user_option( 'custom_avatar_id', $user_id );
if ( $avatar_id && wp_attachment_is_image( $avatar_id ) ) {
    echo 'User has custom avatar';
}
```

### Integrating with Password Reset System
```php
// The lostpassword_url filter automatically redirects to custom reset page
$reset_url = wp_lostpassword_url();
// Returns: https://community.extrachill.com/reset-password/

// Display password reset form in custom template
if ( isset( $_GET['action'] ) && $_GET['action'] === 'reset' ) {
    $login = isset( $_GET['login'] ) ? sanitize_text_field( $_GET['login'] ) : '';
    $key = isset( $_GET['key'] ) ? sanitize_text_field( $_GET['key'] ) : '';
    echo ec_render_reset_password_form( $login, $key );
} else {
    echo ec_render_password_reset_request_form();
}
```

## WordPress Multisite Integration

### Native Functions Used
- **`switch_to_blog()`**: Cross-site database access
- **`restore_current_blog()`**: Restore original site context
- **`get_sites()`**: Dynamic network site discovery
- **`is_multisite()`**: Multisite installation detection
- **`is_user_member_of_blog()`**: Site membership verification
- **Network activation hooks**: Proper network plugin initialization

### Performance Optimizations
- **WordPress Blog-ID-Cache**: Automatic blog ID caching for optimal performance
- **Minimal Context Switching**: Efficient blog switching patterns with try/finally blocks
- **Error Handling**: Comprehensive error logging and fallback mechanisms
- **Transient Caching**: Online user counts cached for 5 minutes, total members for 24 hours
- **Activity Update Throttling**: Per-user activity updates throttled to 15-minute intervals
- **Most Ever Online Caching**: Most ever online check cached for 5 minutes

## Security Implementation

### Network-Wide Security
- **Cross-Site Data Security**: Proper sanitization and escaping for cross-site operations
- **Filter Security**: Input validation for user creation filter
- **Output Escaping**: `esc_html()`, `esc_attr()`, `esc_url()` throughout
- **WordPress Native Auth**: Leverages multisite user authentication system
- **Nonce Verification**: All forms use WordPress nonce system for CSRF protection
- **Turnstile Captcha**: Cloudflare Turnstile integration for bot prevention during registration
- **Input Sanitization**: All user input sanitized with `sanitize_text_field()`, `sanitize_email()`, etc.
- **Password Reset Security**: Uses WordPress native `get_password_reset_key()` with 24-hour expiration
- **Email Validation**: Doesn't reveal whether user exists during password reset (security best practice)

## User Info

- Name: Chris Huber
- Dev website: https://chubes.net
- GitHub: https://github.com/chubes4
- Founder & Editor: https://extrachill.com
- Creator: https://saraichinwag.com
