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
- Maps to the dedicated Link Pages site (Blog ID 13)
- URL preservation: URLs display as extrachill.link
- `extrachill.link` is a different registrable domain than `extrachill.com`, so it does **not** receive the `.extrachill.com` auth cookie — see [Cross-Domain Cookie Handling](#cross-domain-cookie-handling) below

**Cookie Configuration**: WordPress sets the multisite auth cookie for:
- `.extrachill.com` (wildcard, covers all subdomains — derived automatically by WordPress core, no custom config)

`extrachill.link` never receives this cookie. It uses host-only WordPress auth cookies (see below).

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

**Do not define `COOKIE_DOMAIN` in `wp-config.php`.** Core handles the network subdomains, and the network plugin sets a host-only value on the Link Pages host.

`wp-config.php` (verified live, `wp-config.php:85-92`) only defines the multisite bootstrap constants:

```php
define( 'MULTISITE', true );
define( 'SUBDOMAIN_INSTALL', true );
define( 'DOMAIN_CURRENT_SITE', 'extrachill.com' );
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

Because `SUBDOMAIN_INSTALL` is `true` and the network domain is `extrachill.com`, core defines `COOKIE_DOMAIN` as `.extrachill.com` on network-domain requests, with no plugin or config-file involvement. This is the cleanest seam in this network's auth model: cross-subdomain SSO across `*.extrachill.com` is stock WordPress multisite, not custom infrastructure. Do not add an explicit `COOKIE_DOMAIN` define — it would be redundant at best, and risks fighting the network's `cookie_domain` value if the network row ever changes.

**Result**: Single login session across all `.extrachill.com` subdomains, entirely via core's default derivation.

**The one exception is `extrachill.link`** — a different registrable domain, so it can never receive the `.extrachill.com` cookie no matter how `COOKIE_DOMAIN` is set. It is a native multisite domain for its own site (blog 13), so routing is stock core; authentication uses a browser SSO handoff to create a separate WordPress session. See [Domain Mapping for extrachill.link](#domain-mapping-for-extrachilllink) and the [Notes](#notes) section below.

## Domain Mapping for extrachill.link

### Native multisite domain

`extrachill.link` is the `domain` of the Link Pages site (Blog ID 13) in `wp_blogs`. WordPress core resolves the host to that site in `ms_load_current_site_and_network()` / `get_site_by_path()` (`wp-includes/ms-load.php`), which also strips a leading `www.`. There is no `sunrise.php` drop-in and `wp-config.php` must not define `SUNRISE` (#1705: a stale drop-in that forced blog 4 broke link-page analytics after the cutover).

- `www.extrachill.link/*` → 301 → `https://extrachill.link/*` is done in nginx (`deploy/nginx/sites-enabled/extrachill`).
- The standalone extrachill-link-pages runtime owns routing on blog 13; `/join` forwards to the artist login via `ec_network_link_page_join_route()` in `extrachill-network/inc/link-pages/site-integration.php`.

### URL Preservation

**Frontend Display**:
- User visits: `https://extrachill.link/john-doe/`
- Browser address bar: Shows `extrachill.link`
- Content served from: Blog ID 13

**Backend Operation**:
- WordPress operates in Blog ID 13 context
- Database queries use Blog ID 13 prefix
- Relative links work normally

**Cross-Site Navigation**: Links between sites update domain appropriately

## Cross-Domain Cookie Handling

### Cookie Visibility

**extrachill.com Cookies**: The `.extrachill.com` auth cookie is accessible on all `.extrachill.com` subdomains (`community.extrachill.com`, `shop.extrachill.com`, `artist.extrachill.com`, etc.) because they share a registrable domain.

**extrachill.link Cookies**: `extrachill.link` is a **different registrable domain** than `extrachill.com`, so the `.extrachill.com` auth cookie is never sent there — not by the browser, not by any Domain-attribute trick, because cookie scoping is bound to the registrable domain regardless of which blog serves it. `extrachill.link` uses a separate WordPress session and a host-only cookie. See [Notes](#notes) below for the mechanism.

**Cookie Scope**:
```
.extrachill.com cookie (set once, accessible everywhere on that domain):
- extrachill.com
- community.extrachill.com
- shop.extrachill.com
- artist.extrachill.com
- etc.

extrachill.link mapping:
- WordPress core resolves the host to the Link Pages site (Blog ID 13)
- Does NOT receive the .extrachill.com cookie (different registrable domain)
- Authenticated REST actions use the local WordPress cookie and a `wp_rest` nonce
```

### Session Consistency

**Single Session**: User's WordPress session is one per user, not per domain — but only across sites that share the `.extrachill.com` cookie. The browser handoff creates an independent session on `extrachill.link`.

**Logout Behavior**: Logout from any `.extrachill.com` site clears the shared session cookie across all `.extrachill.com` domains. Logout on either domain does not end the session on the other. Sign out on both domains to end both sessions. A later visit to `/edit` can create a new Link Pages session if the network session is still active.

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
- `extrachill.link` as a native multisite domain for blog 13 (no domain-mapping drop-in)
- `extrachill-network` defines `COOKIE_DOMAIN` as `''` on the canonical Link Pages host before core can derive it from the network domain. No third-party cookies are needed.
- `/edit` and the header's Log In link start a browser handoff. The artist site uses the network session or redirects to its login page. It hands a 60-second, single-use token back in a URL fragment; an isolated page removes the fragment and submits the token by POST. The Link Pages host checks the initiating browser's state cookie before creating a session.
- Public Link Pages remain cacheable: the pencil checks the local session separately, and only browsers with an editor hint, a remembered editor marker, or a network referrer try SSO automatically. The hint and marker are not credentials.
- When deploying, purge cached Link Pages HTML and any previously cached `/edit` response so the new button configuration and private editor response take effect.

### Cookie domain expectations

**Check 1: Cookie Domain Derivation**
- `COOKIE_DOMAIN` should **not** appear as an explicit `define()` in `wp-config.php` — if it does, someone added a redundant (or worse, conflicting) override of core's automatic derivation.
- To verify the effective value at runtime: `wp --url=https://extrachill.com eval 'var_dump( COOKIE_DOMAIN );'` should show `.extrachill.com`. The equivalent command with `--url=https://extrachill.link` should show `string(0) ""`.

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

- [Blog ID Helpers](blog-id-helpers.md)
- [extrachill-users authentication](../../extrachill-users/docs/authentication.md) - Authentication system
- `inc/link-pages/browser-session.php` - `extrachill.link` browser SSO
- [WordPress Multisite Handbook](https://developer.wordpress.org/plugins/multisite/)
- [WordPress Security Handbook](https://developer.wordpress.org/plugins/security/)
