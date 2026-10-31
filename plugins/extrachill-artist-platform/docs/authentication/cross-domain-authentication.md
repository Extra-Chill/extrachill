# Cross-Domain Authentication

The Extra Chill Artist Platform uses WordPress multisite native authentication for cross-domain user sessions.

## Authentication Architecture

### Primary: WordPress Multisite Authentication

Users remain authenticated across all `.extrachill.com` subdomains automatically through WordPress multisite's native user session system. No custom session management required.

**Coverage**:
- Main site: `extrachill.com`
- Artist site: `artist.extrachill.com`

### Secondary: extrachill.link Domain Integration

The `extrachill.link` domain provides public access to artist link pages while maintaining cross-domain authentication for management operations.

**Architecture**:
- **Backend**: extrachill.link maps to artist.extrachill.com (blog ID 4)
- **Frontend URLs**: Link pages display as `extrachill.link/{artist-slug}`
- **Authentication**: WordPress cookies configured with `SameSite=None; Secure` attributes for cross-domain access
- **Cookie Configuration**: Managed by extrachill-users plugin (extrachill-users/inc/auth/extrachill-link-auth.php)

**URL Parameter Detection**:
- Join flow entry: `extrachill.link/join` → redirects to `artist.extrachill.com/login/?from_join=true` via sunrise.php
- Detected via `from_join` parameter in URL
- Post-registration redirect: tracked via `join_flow_completion_{user_id}` transients

## Edit Icon System (CORS Authentication)

Client-side permission checking with cross-domain REST API requests to artist.extrachill.com:

**Files**:
- REST API Enqueuer: `inc/link-pages/live/ajax/edit-permission.php`
- JavaScript: `inc/link-pages/live/assets/js/link-page-edit-button.js`
- Styles: `assets/css/extrch-links.css` (lines 243-264)

**Flow**:
1. User views link page on `extrachill.link/{artist-slug}`
2. JavaScript makes CORS request to `artist.extrachill.com/wp-json/extrachill/v1/edit-permission`
3. Server validates user permission via `ec_can_manage_artist()`
4. Edit button rendered if permission granted (JavaScript-only, no server HTML)

**CORS Headers**:
```
Access-Control-Allow-Origin: https://extrachill.link
Access-Control-Allow-Credentials: true
Access-Control-Allow-Methods: POST, GET, OPTIONS
Access-Control-Allow-Headers: Content-Type
```

**Security**:
- Uses WordPress authentication cookies (SameSite=None; Secure)
- Permission validation via `ec_can_manage_artist()` system
- Credentials included via `fetch()` with `credentials: 'include'`
- Hooked via `extrch_link_page_minimal_head` action (priority 10)
