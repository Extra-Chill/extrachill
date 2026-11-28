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
- **Backend**: extrachill.link is the native domain of the Link Pages site (blog ID 13)
- **Frontend URLs**: Link pages display as `extrachill.link/{artist-slug}`
- **Authentication**: Host-only WordPress session established by [Link Pages browser SSO](../../../extrachill-network/docs/cross-domain-auth.md). The network cookie is not shared with the Link Pages domain.

**URL Parameter Detection**:
- Join flow entry: `extrachill.link/join` → redirects to `artist.extrachill.com/login/?from_join=true` via `ec_network_link_page_join_route()` (extrachill-network `inc/link-pages/site-integration.php`)
- Detected via `from_join` parameter in URL
- Post-registration redirect: tracked via `join_flow_completion_{user_id}` transients

## Edit icon and editor

The Link Pages plugin checks the local session without personalizing the public HTML. It uses the returned REST nonce to check artist permissions and shows the pencil only when the user may edit. `/edit` uses the same host's cookie and nonce, with server-side route affinity forwarding artist requests to the owner site.
