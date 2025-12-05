# Cross-Domain Cookie Controls

## Purpose
Ensures WordPress authentication cookies include `SameSite=None; Secure` so authenticated sessions persist when `extrachill.link` makes cross-site REST calls (e.g., artist permissions checks against `artist.extrachill.com`).

## Behavior Overview
1. `ec_register_cookie_samesite_callback()` hooks into `init` with priority `1` so it runs before other cookie logic.
2. Uses `header_register_callback()` to register `ec_add_samesite_none_to_wordpress_cookies()` for execution just before headers are sent.
3. The callback:
   - Reads all pending headers via `headers_list()`.
   - Removes each `Set-Cookie` header temporarily.
   - Re-adds every cookie header, appending `SameSite=None; Secure` to those containing `wordpress_` unless the attribute already exists.

## User Impact
- Logged-in creators stay authenticated while controlling link pages on `extrachill.link`.
- REST fetches such as `/wp-json/extrachill/v1/artist/permissions` can include credentials without triggering browser cross-site restrictions.

## Operational Notes
- Applies only to WordPress cookies, leaving third-party cookies untouched.
- Requires PHP versions that support `header_register_callback()` (available in WordPress core environments).
- Should remain network-activated wherever cross-domain editing experiences rely on cookie auth.
