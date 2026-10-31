# Cross-Domain Authentication

## Scope

This document describes current cross-domain authentication behavior.

## Overview

The Extra Chill Platform implements WordPress native multisite authentication across multiple domains (`.extrachill.com` subdomains and `extrachill.link` domain mapping). This document explains how cross-domain authentication works, enabling seamless login/logout across all network sites while maintaining security.

## Network Architecture

### Domains in the Network

**Primary Domain**: `extrachill.com`
- Subdomains for each site (community.extrachill.com, shop.extrachill.com, etc.)
- All subdomains share `.extrachill.com` cookie domain
- Native WordPress multisite authentication

**Mapped Domain**: `extrachill.link`
- Maps to artist.extrachill.com (Blog ID 4)
- URL preservation: URLs display as extrachill.link
- Backend operates on artist.extrachill.com
- `extrachill.link` is a different registrable domain than `extrachill.com`, so it does **not** receive the `.extrachill.com` auth cookie — see [Cross-Domain Cookie Handling](#cross-domain-cookie-handling) below

**Cookie Configuration**: WordPress sets the multisite auth cookie for:
- `.extrachill.com` (wildcard, covers all subdomains — derived automatically by WordPress core, no custom config)

`extrachill.link` never receives this cookie. It authenticates separately via a bearer token handoff (see below).

## WordPress Multisite Authentication

### How Multisite Authentication Works

**User Database**: Single user table across all sites (`wp_users`)

**Single Login**: User authenticates once, authenticated on all sites

**Session Persistence**: WordPress session cookies set at network level

**Cookie Domain**: Single cookie domain enables access across subdomain sites

### Cookie Structure

**Session Cookies Set**:
```
wordpress_logged_in_<SITECOOKIEHASH>
- Domain: .extrachill.com
- Path: /
- Secure: true (HTTPS only)
- HttpOnly: true (no JavaScript access)
- SameSite: Lax (CSRF protection)
```

**Authentication Verification**: User ID stored in cookie, verified on page load

**Admin Cookies**: Separate cookies for network admin access

### Cookie Domain Configuration

**`COOKIE_DOMAIN` is not defined anywhere in `wp-config.php`, and it should not be.** This is plain WordPress core behavior for subdomain multisite installs — zero custom code required.

`wp-config.php` (verified live, `wp-config.php:85-92`) only defines the multisite bootstrap constants:

```php
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', true );
define( 'DOMAIN_CURRENT_SITE', 'extrachill.com' );
define( 'SUNRISE', true );
```

WordPress core derives `COOKIE_DOMAIN` itself from those constants, in `ms_cookie_constants()` (`wp-includes/ms-default-constants.php:84-88`):

```php
if ( ! defined( 'COOKIE_DOMAIN' ) && is_subdomain_install() ) {
    if ( ! empty( $current_network->cookie_domain ) ) {
        define( 'COOKIE_DOMAIN', '.' . $current_network->cookie_domain );
    } else {
        define( 'COOKIE_DOMAIN', '.' . $current_network->domain );
    }
}
```

Because `SUBDOMAIN_INSTALL` is `true` and the network domain is `extrachill.com`, core defines `COOKIE_DOMAIN` as `.extrachill.com` on every request, with no plugin or config-file involvement. This is the cleanest seam in this network's auth model: cross-subdomain SSO across `*.extrachill.com` is stock WordPress multisite, not custom infrastructure. Do not add an explicit `COOKIE_DOMAIN` define — it would be redundant at best, and risks fighting the network's `cookie_domain` value if the network row ever changes.

**Result**: Single login session across all `.extrachill.com` subdomains, entirely via core's default derivation.

**The one exception is `extrachill.link`** — a different registrable domain, so it can never receive the `.extrachill.com` cookie no matter how `COOKIE_DOMAIN` is set. That domain requires bespoke handling: `wp-content/sunrise.php` domain mapping plus a separate bearer-token handoff. See [Domain Mapping for extrachill.link](#domain-mapping-for-extrachilllink) and the [Notes](#notes) section below.

## Domain Mapping for extrachill.link

### Sunrise PHP Implementation

**File**: `wp-content/sunrise.php`

This is the only valid path — the `SUNRISE` constant (defined in `wp-config.php`) causes WordPress core to `include_once WP_CONTENT_DIR . '/sunrise.php'` specifically (`wp-includes/ms-settings.php:51-53`). The canonical source lives in the `Extra-Chill/.github` repo and is deployed to `wp-content/sunrise.php` on the live install; it is not part of this repo's tree.

**Execution**: Runs before WordPress loads, during domain routing

**Responsibility**: Route extrachill.link to Blog ID 4 (artist.extrachill.com)

**Mapping Logic**:
```php
// extrachill.link → Blog ID 4
// extrachill.link/artist-slug/ displays at extrachill.link
// Backend operates on artist.extrachill.com
```

### ⚠️ Route Exclusion List (undocumented foot-gun)

`wp-content/sunrise.php:36-52` also adds a `rewrite_rules_array` filter at priority `0`, scoped to the `extrachill.link` host, that installs a catch-all rewrite:

```php
// wp-content/sunrise.php:41
$excluded = 'wp-admin|wp-login|wp-json|artists?|link-page|manage-artist|manage-link-page|join';

// wp-content/sunrise.php:44-47
$new_rules = [
    '^(' . $excluded . ')/?$' => 'index.php?$1',
    '^([^/]+)/?$'             => 'index.php?artist_link_page=$matches[1]',
];
```

Any single-segment top-level path on `extrachill.link` that is **not** in that pipe-delimited `$excluded` list is captured by `^([^/]+)/?$` and routed as `artist_link_page=$matches[1]` — i.e. treated as a link-page slug, not as a real route. This means:

- Adding a brand-new top-level route to the artist site (e.g. a new page slug, a new REST-adjacent endpoint, a new top-level admin-post action) will silently be swallowed by the link-page catch-all on `extrachill.link` unless its slug is also added to the `$excluded` list in `wp-content/sunrise.php`.
- This list is defined inline in a drop-in file (`wp-content/sunrise.php`), not in this repo, not in `extrachill-artist-platform`, and not discoverable from a normal plugin-code grep — it is a live trap for anyone adding routes without knowing to check sunrise.
- Current exclusions: `wp-admin`, `wp-login`, `wp-json`, `artist`/`artists`, `link-page`, `manage-artist`, `manage-link-page`, `join`.

### URL Preservation

**Frontend Display**:
- User visits: `https://extrachill.link/artist/john-doe/`
- Browser address bar: Shows `extrachill.link`
- Content served from: Blog ID 4

**Backend Operation**:
- WordPress operates in Blog ID 4 context
- Database queries use Blog ID 4 prefix
- Relative links work normally
- Admin area accessible via: `https://extrachill.link/wp-admin/`

**Cross-Site Navigation**: Links between sites update domain appropriately

## Cross-Domain Cookie Handling

### Cookie Visibility

**extrachill.com Cookies**: The `.extrachill.com` auth cookie is accessible on all `.extrachill.com` subdomains (`community.extrachill.com`, `shop.extrachill.com`, `artist.extrachill.com`, etc.) because they share a registrable domain.

**extrachill.link Cookies**: `extrachill.link` is a **different registrable domain** than `extrachill.com`, so the `.extrachill.com` auth cookie is never sent there — not by the browser, not by any Domain-attribute trick, because cookie scoping is bound to the registrable domain regardless of `sunrise.php`'s blog-ID mapping. `extrachill.link` authenticates via a separate bearer-token handoff instead of a shared cookie. See [Notes](#notes) below for the mechanism.

**Cookie Scope**:
```
.extrachill.com cookie (set once, accessible everywhere on that domain):
- extrachill.com
- community.extrachill.com
- shop.extrachill.com
- artist.extrachill.com
- etc.

extrachill.link mapping:
- sunrise.php routes the request to artist.extrachill.com (Blog ID 4) content
- Does NOT receive the .extrachill.com cookie (different registrable domain)
- Authenticated actions use a bearer token instead — see Notes
```

### Session Consistency

**Single Session**: User's WordPress session is one per user, not per domain — but only across sites that share the `.extrachill.com` cookie. `extrachill.link` authenticates out-of-band via the bearer-token handoff, not via shared session cookies.

**Logout Behavior**: Logout from any `.extrachill.com` site clears the shared session cookie across all `.extrachill.com` domains. It does not directly affect an `extrachill.link` bearer token already issued to a client, since that token is a separate credential (see Notes).

**Blog Switching**: User remains logged in when visiting different `.extrachill.com` sites

**Blog Context**: Current blog ID changes, but user ID remains constant

## Authentication Flow

### Login Process

**Step 1: User Accesses Login Form**
- Visits any site or `/wp-login.php`
- Form displays (theme or WordPress default)

**Step 2: Form Submission**
- Email/username and password submitted
- AJAX or traditional POST to `/wp-login.php`
- Password verified against `wp_users` table

**Step 3: Cookie Set**
```php
// WordPress sets cookie for .extrachill.com domain
setcookie(
    'wordpress_logged_in_[hash]',
    $cookie_value,
    $expire,
    '/',
    '.extrachill.com',
    true,  // secure (HTTPS)
    true   // httponly
);
```

**Step 4: Redirect**
- Successful: Redirect to referring page or dashboard
- Failed: Display error message, form persists
- 2FA: If enabled, redirect to verification

### Logout Process

**Step 1: User Clicks Logout Link**
- Link includes nonce for security
- Posts to `/wp-login.php?action=logout`

**Step 2: Cookie Cleared**
```php
// WordPress deletes authentication cookies
setcookie(
    'wordpress_logged_in_[hash]',
    '',
    time() - 3600,
    '/',
    '.extrachill.com'
);
```

**Step 3: Session Ended**
- User no longer authenticated on any site
- Accessing member-only content redirects to login
- Session data cleared

**Step 4: Redirect**
- Redirects to login page or home page
- User can login again if needed

## Multisite User Creation

### Where Users Register

**Primary Registration Site**: community.extrachill.com (Blog ID 2)

**User Creation Location**: WordPress `wp_users` table (network-wide)

**Registration Flow** (extrachill-users plugin):
1. User fills registration form
2. Email/username validated
3. User account created in `wp_users`
4. User automatically added to community site
5. User can access all sites with same credentials

### Blog Membership

**Automatic Membership**: New users added to community.extrachill.com automatically

**Other Sites**: User must be added by admin or self-add via integration

**Plugin Integration**: Plugins can add users to specific sites on registration

**Profile Access**: User profile accessible from any site (profile.extrachill.com or artist.extrachill.com)

## Cross-Site User Context

### Current User Verification

**Available Across Network**:
```php
// Works on any site
$user_id = get_current_user_id();
$user = wp_get_current_user();
$user_email = $user->user_email;
```

**User Blog Membership**: Verify user has access to current blog
```php
// Check if user is member of current blog
if ( ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
    // User doesn't have access to this blog
}
```

### User Data Access

**User Meta**: Stored in network table `wp_usermeta`

**Blog-Specific Meta**: Can store blog-specific data with user meta key pattern

**Access Pattern**:
```php
// Get user meta (works across blogs)
$meta_value = get_user_meta( $user_id, 'meta_key', true );

// Update user meta (works across blogs)
update_user_meta( $user_id, 'meta_key', 'new_value' );
```

**No Blog Switching Needed**: User meta accessible from any blog context

## Capability Verification

### User Capabilities

**Network Capabilities**: Some capabilities apply network-wide
- `manage_network` - Network administrator
- `manage_sites` - Can manage individual sites

**Blog Capabilities**: Some capabilities are per-blog
- `manage_options` - Can edit blog options (admin)
- `edit_posts` - Can edit blog posts

**Capability Checks**:
```php
// Network-wide
if ( current_user_can( 'manage_network' ) ) {
    // Network admin only
}

// Blog-specific
if ( current_user_can( 'manage_options' ) ) {
    // Current blog admin only
}

// Check specific user/blog
if ( user_can( $user_id, 'manage_options', $blog_id ) ) {
    // User can manage options on specific blog
}
```

### Role Hierarchy

**Network Roles**: Super admin (network-wide)

**Blog Roles**: Admin, Editor, Author, Contributor, Subscriber (per-blog)

**Custom Roles**: Plugins can define custom roles per blog

**Team Member System** (extrachill-users): Custom role/permission system for artist teams

## REST API Authentication

### API Token vs Cookie Auth

**REST API Endpoints**: Use WordPress native authentication

**Cookie Authentication**: Works for same-domain requests
- Browser requests include cookies automatically
- HTTPS required for security
- CORS issues possible for cross-domain

**REST Nonces**: Required for POST/PUT/DELETE requests
```php
// Generate nonce
wp_nonce_field( 'wp_rest' );

// Verify in REST endpoint
if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp_rest' ) ) {
    wp_send_json_error( 'Invalid nonce' );
}
```

**Authentication Methods**:
1. Cookie authentication (logged-in users)
2. REST nonces for same-origin browser requests
3. Bearer access + refresh tokens for first-party clients (mobile app and other non-browser clients)

## Security Considerations

### Cookie Security

**HTTPS Only**: Cookies only sent over HTTPS (secure flag)

**HttpOnly**: Cookies not accessible to JavaScript (prevents XSS attacks)

**SameSite Protection**: Cookies not sent for cross-site requests (prevents CSRF)

**Domain Scoping**: Cookies limited to `.extrachill.com` (not accessible to other domains)

### Session Hijacking Prevention

**Session Validation**: User agent and IP address can be validated per session

**Short Expiration**: Sessions expire after inactivity period

**Secure Transport**: HTTPS prevents man-in-the-middle attacks

**Nonce Verification**: One-time tokens for state-changing actions

### Password Security

**Hashing**: Passwords hashed with bcrypt/phpass (WordPress standard)

**No Plain Storage**: Passwords never stored or logged

**Reset Flow**: Secure password reset via email link

**2FA Support**: Two-factor authentication available (plugin dependent)

## Notes

### Cookie attribute handling

Cross-domain auth relies on:

- WordPress core's automatic `COOKIE_DOMAIN` derivation (`.extrachill.com`) for subdomain multisite — no explicit `COOKIE_DOMAIN` define, see [Cookie Domain Configuration](#cookie-domain-configuration) above
- Domain mapping via `wp-content/sunrise.php` for `extrachill.link` (canonical source in the `Extra-Chill/.github` repo, deployed to `wp-content/sunrise.php`)
- For `extrachill.link` (a different registrable domain than `.extrachill.com`, so the auth cookie can never reach it), authenticated REST calls use a **wp-native bearer token** minted by `extrachill-api/inc/auth/extrachill-link-token-handoff.php` on the artist site and handed to the link page in a URL fragment. This replaced the former `SameSite=None; Secure` cookie patch, which modern browser privacy (Safari ITP, Chrome third-party-cookie phase-out) increasingly blocked.

Cookie domain configuration lives in WordPress core's multisite bootstrap (not an explicit config define) and the hosting/proxy layer.

### Cookie domain expectations

**Check 1: Cookie Domain Derivation**
- `COOKIE_DOMAIN` should **not** appear as an explicit `define()` in `wp-config.php` — if it does, someone added a redundant (or worse, conflicting) override of core's automatic derivation.
- To verify the effective value at runtime: `wp eval 'var_dump( COOKIE_DOMAIN );'` should print `string(15) ".extrachill.com"`.

**Check 2: HTTPS Configuration**
- Ensure all sites use HTTPS
- Insecure connections don't transmit secure cookies

**Check 3: Site Registration**
- User must be registered/member of site
- Check `wp_users` table (exists)
- Check `wp_usermeta` for user capabilities

### Session consistency expectations

**Common Causes**:
- Cookie not being set (secure flag issue)
- Cookie domain mismatch
- User not member of current blog
- Session expired

**Debug Signals**:
- The browser stores `wordpress_logged_in_*` and `wordpress_sec_*` cookies for `.extrachill.com`.
- The request host matches the cookie domain and uses HTTPS.
- The current user is a member of the current blog where required (WordPress multisite).


### Logout behavior expectations

**Likely Cause**: User is member of multiple blogs with different cache

**Solution**:
1. Clear browser cookies manually
2. Wait for cache expiration
3. Login again

## Cross-References

- [extrachill-network CLAUDE.md - Blog ID Management](../CLAUDE.md#blog-id-management)
- [extrachill-users CLAUDE.md](../extrachill-users/CLAUDE.md) - Authentication system
- `Extra-Chill/.github` repo, `sunrise.php` - Domain mapping implementation (canonical source; deployed to `wp-content/sunrise.php`, not part of this repo's tree)
- `extrachill-api/inc/auth/extrachill-link-token-handoff.php` - `extrachill.link` bearer-token handoff implementation
- [WordPress Multisite Handbook](https://developer.wordpress.org/plugins/multisite/)
- [WordPress Security Handbook](https://developer.wordpress.org/plugins/security/)
