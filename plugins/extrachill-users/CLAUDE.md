# ExtraChill Users

Network-activated WordPress plugin providing comprehensive user management for the ExtraChill Platform multisite network. Handles authentication, registration, password reset, cross-site user management, team member system, profile URL resolution, network-wide avatar menu, custom avatars, and online user tracking across all 8 sites.

## Plugin Information

- **Name**: Extra Chill Users
- **Version**: 1.1.0
- **Text Domain**: `extrachill-users`
- **Author**: Chris Huber
- **Author URI**: https://chubes.net
- **License**: GPL v2 or later
- **Network**: true (network activated across all sites)
- **Requires Plugins**: extrachill-multisite
- **Requires at least**: 5.0
- **Tested up to**: 6.4
- **Requires PHP**: 7.4

## Architecture

### Plugin Purpose
Comprehensive user management system for the ExtraChill multisite network providing authentication, registration, password reset, team member management, profile URL resolution, custom avatars, online user tracking, and network-wide avatar menu functionality. Extracted from extrachill-multisite plugin to follow single responsibility principle - this plugin handles all user-specific logic while extrachill-multisite focuses on multisite infrastructure.

### Plugin Loading Pattern
- **Procedural WordPress Pattern**: Uses direct `require_once` includes for all plugin functionality
- **Network Plugin Structure**: Network-activated plugin providing functionality across all multisite installations
- **Gutenberg Blocks**: Registers login/register and password reset blocks via `register_block_type()`
- **Modular Organization**: 14 include files organized by functionality domain

### Core Features

#### Authentication System (`inc/login.php`, `inc/register.php`, `inc/logout.php`)

**Login System** (`inc/login.php`):
- Custom login form with error handling and validation
- Redirects wp-login.php access to custom `/login/` page for non-logged-in users
- Login failure handling with user-friendly error messages
- Success redirects with `?login=success` parameter for universal success notices
- Preserves `redirect_to` parameter for post-login routing
- Uses WordPress native authentication via wp-login.php POST submission

**Registration System** (`inc/register.php`):
- Custom registration form with user type selection (artist/professional checkboxes)
- Cloudflare Turnstile captcha integration via `ec_render_turnstile_widget()` and `ec_verify_turnstile_response()` from extrachill-multisite
- Creates users on community.extrachill.com via `extrachill_create_community_user` filter
- Newsletter subscription integration via `extrachill_multisite_subscribe($email, 'registration')` bridge function
- Roster invitation acceptance during registration (extrachill-artist-platform integration)
- Auto-login after successful registration with auth cookie
- Success redirects with `?registration=success` parameter for universal success notices
- Universal success notices displayed via `extrachill_before_body_content` theme hook

**Logout System** (`inc/logout.php`):
- Not implemented as separate file - WordPress native logout functionality used

**Newsletter Integration**:
- Registers 'registration' context via `newsletter_form_integrations` filter
- Zero hardcoded Sendy credentials - all configuration via extrachill-newsletter admin UI
- Subscription handled via extrachill-multisite bridge function during registration

**Roster Invitation Flow**:
- Detects `?action=bp_accept_invite&token=...&artist_id=...` URL parameters
- Validates invitation token against pending invitations via `bp_get_pending_invitations()`
- Pre-fills email field for invited users
- Displays invitation notice message during registration
- Automatically joins invited user to artist roster after registration via `bp_add_artist_membership()`
- Removes pending invitation via `bp_remove_pending_invitation()`
- Redirects to artist profile page after successful registration with invitation

#### Password Reset System (`inc/password-reset.php`)

**Custom Password Reset Flow**:
- Filters `lostpassword_url` to point to https://community.extrachill.com/reset-password/
- Two-step process: email submission → password reset form
- Uses WordPress native functions internally (`get_password_reset_key()`, `check_password_reset_key()`, `reset_password()`)
- Custom UI that never exposes wp-admin or wp-login.php
- 24-hour expiration for reset links (WordPress default)
- Auto-login after successful password reset
- Redirect to homepage with `?password-reset=success` parameter

**Email Request Form**:
- User enters email address
- Security-conscious messaging (doesn't reveal if account exists)
- Sends HTML email with reset link to community.extrachill.com/reset-password/?action=reset&key=...&login=...
- Transient-based success/error message display

**Password Reset Form**:
- Validates reset key using `check_password_reset_key()`
- Password confirmation with 8-character minimum
- Sets new password via `reset_password()`
- Auto-login via `wp_set_auth_cookie()`
- Invalid/expired links redirect to request form with error message

#### Online Users Tracking (`inc/online-users.php`, `inc/online-users-display.php`)

**Network-Wide Activity Tracking**:
- Records user activity across all 8 sites in the multisite network
- Centralized data storage on community.extrachill.com as single source of truth
- 15-minute activity window for "online" status determination
- Updates `last_active` user meta via `wp` action hook on all sites

**Performance Optimizations**:
- Transient caching for online user counts (5-minute cache)
- Per-user activity update throttling (15-minute minimum between updates)
- Cached "most ever online" check (5-minute cache)
- Total members count cached for 24 hours

**Most Ever Online Tracking**:
- Tracks highest concurrent user count with date
- Stored in `most_ever_online` option on community.extrachill.com
- Format: `array('count' => 123, 'date' => 'm/d/Y')`

**Display Widget** (`inc/online-users-display.php`):
- Shows network-wide "Online Now" count with green indicator
- Shows "Total Members" count
- Styled stats card with Font Awesome icons
- Queries community.extrachill.com for consistent data

#### Custom Avatar System (`inc/avatar-display.php`, `inc/avatar-upload.php`)

**Avatar Display** (`inc/avatar-display.php`):
- Filters `pre_get_avatar` to provide custom avatars before Gravatar
- Uses `custom_avatar_id` user meta storing WordPress attachment ID
- Retrieves thumbnail size via `wp_get_attachment_image_url()`
- Proper multisite support via `get_user_option()` (checks network-wide)
- Automatic fallback to Gravatar if no custom avatar set
- Returns null to let WordPress handle Gravatar fallback

**Avatar Upload** (`inc/avatar-upload.php`):
- Integration with bbPress profile edit page
- Custom upload field in bbPress edit profile form
- Stores attachment ID in `custom_avatar_id` user meta
- File validation and size restrictions
- Updates existing avatar when new one uploaded

**Migration Utility**:
- `generate_custom_avatar_ids()` function for legacy `custom_avatar` URL migration
- Converts old URL-based avatars to attachment ID system
- Admin-only utility via `?generate_custom_avatar_ids=1` URL parameter

#### Gutenberg Blocks

**Login/Register Block** (`build/login-register/`):
- Provides login/register interface as Gutenberg block
- Registered via `register_block_type()` in main plugin file
- Built with WordPress Scripts

**Password Reset Block** (`build/password-reset/`):
- Provides password reset interface as Gutenberg block
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
- Leverages WordPress native `get_blog_id_from_url()` with automatic blog-id-cache

#### User Creation System (`inc/user-creation.php`)
**Filter**: `extrachill_create_community_user`

**Function**: `ec_multisite_create_community_user( $user_id, $registration_data )`

**Purpose**: Single source of truth for user creation on community.extrachill.com regardless of which site initiates registration

**Registration Data Array**:
- `username` - Required username
- `password` - Required password
- `email` - Required email address
- `user_is_artist` - Boolean for artist status
- `user_is_professional` - Boolean for professional status

**Workflow**:
1. Validate required fields (username, password, email)
2. Get community blog ID via `get_blog_id_from_url()`
3. Switch to community site if not already there
4. Create user with `wp_create_user()`
5. Set `user_is_artist` and `user_is_professional` meta
6. Restore original blog context
7. Return user_id or WP_Error

**Used By**: Registration system in this plugin (`inc/register.php`) for network-wide user creation

#### Author Profile URL Resolution (`inc/author-links.php`)
**Functions**:
- `ec_get_user_profile_url( $user_id, $user_email = '' )` - Centralized user profile URL logic
- `ec_get_comment_author_link_multisite( $comment )` - Generate comment author link HTML
- `ec_should_use_multisite_comment_links( $comment )` - Check if comment should use multisite linking (after Feb 9, 2024)

**Resolution Logic**:
1. Check if user exists on main site (extrachill.com) → return author posts URL
2. Try email lookup on community site → return bbPress profile URL
3. Try user_id lookup on community site → return bbPress profile URL
4. Ultimate fallback → standard author posts URL

**URL Formats**:
- Main site users: `https://extrachill.com/author/username/`
- Community users: `https://community.extrachill.com/u/username/`

**Integration**: Used by theme for comment author links and user profile display

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
- `assets/js/avatar-menu.js` - Avatar dropdown functionality
- `assets/js/avatar-upload.js` - Avatar upload functionality

**Loading Pattern**:
- Avatar menu assets load network-wide
- Uses `filemtime()` versioning for cache busting
- Checks file existence before enqueuing

#### Registration Emails (`inc/registration-emails.php`)
**Welcome Email System**:
- Sends HTML welcome email after successful registration
- Includes user information and getting started links
- Custom email template with Extra Chill branding

## File Structure

```
extrachill-users/
├── extrachill-users.php           (main plugin file)
├── inc/
│   ├── team-members.php           (team member functions)
│   ├── author-links.php           (profile URL resolution)
│   ├── user-creation.php          (community user creation filter)
│   ├── login.php                  (login system)
│   ├── register.php               (registration system)
│   ├── logout.php                 (logout system - minimal/placeholder)
│   ├── password-reset.php         (password reset system)
│   ├── registration-emails.php    (welcome email system)
│   ├── online-users.php           (activity tracking)
│   ├── online-users-display.php   (online stats widget)
│   ├── avatar-display.php         (custom avatar display)
│   ├── avatar-upload.php          (avatar upload functionality)
│   ├── avatar-menu.php            (avatar menu display)
│   └── assets.php                 (asset enqueuing)
├── assets/
│   ├── css/
│   │   ├── avatar-menu.css        (avatar menu styles)
│   │   └── online-users.css       (online users widget styles)
│   └── js/
│       ├── avatar-menu.js         (avatar menu JavaScript)
│       └── avatar-upload.js       (avatar upload JavaScript)
├── blocks/
│   ├── login-register/            (Login/Register Gutenberg block source)
│   └── password-reset/            (Password Reset Gutenberg block source)
├── build/
│   ├── login-register/            (Compiled Login/Register block)
│   └── password-reset/            (Compiled Password Reset block)
├── package.json                   (npm dependencies for blocks)
├── composer.json                  (dev dependencies)
├── build.sh                       (symlink to ../../.github/build.sh)
├── .buildignore                   (build exclusions)
└── CLAUDE.md                      (architectural documentation)
```

## Technical Implementation

### WordPress Multisite Patterns
**Blog Switching Architecture**:
```php
// Standard pattern used throughout plugin
switch_to_blog( get_blog_id_from_url( 'community.extrachill.com', '/' ) );
try {
    // Cross-site database operations
    $user = get_userdata( $user_id );
} finally {
    restore_current_blog();
}
```

**Domain-Based Site Resolution**:
- Uses `get_blog_id_from_url()` with automatic WordPress blog-id-cache
- No hardcoded blog IDs - uses domain strings for maintainability
- Performance optimized via WordPress native caching

### Plugin Loading Strategy
**Main Plugin File** (`extrachill-users.php`):
- Network activation check (requires multisite installation)
- Defines plugin constants (VERSION, PLUGIN_FILE, PLUGIN_DIR, PLUGIN_URL)
- Registers Gutenberg blocks via `register_block_type()` in `init` action
- Loads 14 core includes via `plugins_loaded` action
- Registers newsletter integration via `newsletter_form_integrations` filter

**Block Registration** (via `extrachill_users_register_blocks()`):
- `build/login-register` - Login/Register block
- `build/password-reset` - Password Reset block

**Include Loading Order** (via `extrachill_users_init()`):
1. `inc/team-members.php` - Team member functions
2. `inc/author-links.php` - Profile URL resolution
3. `inc/user-creation.php` - User creation filter
4. `inc/assets.php` - Asset management
5. `inc/login.php` - Login system
6. `inc/register.php` - Registration system
7. `inc/logout.php` - Logout system
8. `inc/registration-emails.php` - Welcome email system
9. `inc/password-reset.php` - Password reset system
10. `inc/online-users.php` - Activity tracking
11. `inc/avatar-display.php` - Avatar display (loads network-wide)
12. `inc/avatar-upload.php` - Avatar upload (loads network-wide)
13. `inc/avatar-menu.php` - Avatar menu (loads network-wide)
14. `inc/online-users-display.php` - Online stats widget

## Development Standards

### Code Organization
- **Procedural Pattern**: Direct `require_once` includes throughout
- **WordPress Standards**: Full compliance with network plugin development guidelines
- **Security Implementation**: Proper escaping, sanitization, and cross-site data access patterns
- **Performance Focus**: Conditional loading, domain-based site resolution, WordPress native caching

### Build System
- **Universal Build Script**: Symlinked to shared build script at `../../.github/build.sh`
- **Auto-Detection**: Script auto-detects network plugin from `Network: true` header
- **Production Build**: Creates `/build/extrachill-users/` directory and `/build/extrachill-users.zip` file (non-versioned)
- **Composer Integration**: Production builds use `composer install --no-dev`, restores dev dependencies after
- **File Exclusion**: `.buildignore` rsync patterns exclude development files
- **Structure Validation**: Ensures network plugin integrity before packaging

## Dependencies

### PHP Requirements
- **PHP**: 7.4+
- **WordPress**: 5.0+ multisite network
- **Multisite**: Requires WordPress multisite installation (enforced on activation)
- **Requires Plugins**: extrachill-multisite (WordPress 6.5+ native dependency)

### Plugin Dependencies
- **extrachill-multisite**: Required for Turnstile captcha (`ec_render_turnstile_widget()`, `ec_verify_turnstile_response()`)
- **extrachill-multisite**: Required for newsletter subscription (`extrachill_multisite_subscribe()` bridge function)
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

**Integration**: Registration system in this plugin (`inc/register.php`) calls `apply_filters('extrachill_create_community_user', ...)` during registration

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
- Tracks user activity across all 8 sites in the multisite network
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
./build.sh

# Run PHP linting
composer run lint:php

# Fix PHP coding standards
composer run lint:fix

# Run tests
composer run test
```

### Build Output
- **Production Package**: `/build/extrachill-users/` directory and `/build/extrachill-users.zip` file
- **Network Plugin**: Must be installed in network plugins directory
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
- **`get_blog_id_from_url()`**: Domain-based blog ID resolution with automatic caching
- **`is_multisite()`**: Multisite installation detection
- **`is_user_member_of_blog()`**: Site membership verification
- **Network activation hooks**: Proper network plugin initialization

### Performance Optimizations
- **WordPress Native Caching**: `get_blog_id_from_url()` uses blog-id-cache automatically
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
