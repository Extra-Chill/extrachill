# Extrachill Link Auth Helper

## Role
Modifies WordPress authentication cookies so cross-domain REST calls originating from `extrachill.link` retain the logged-in session while targeting network sites such as `artist.extrachill.com`.

## How It Works
1. Hooks `ec_register_cookie_samesite_callback()` into `init` (priority 1) to ensure early execution.
2. Registers `ec_add_samesite_none_to_wordpress_cookies()` with `header_register_callback()` so it runs right before headers are sent.
3. Reads all pending headers via `headers_list()`, removes every `Set-Cookie`, and re-adds them with `SameSite=None; Secure` appended when the cookie name contains `wordpress_` and the attribute is not already present.

## User Impact
- Cookie-authenticated requests from `extrachill.link` (e.g., artist permission checks) succeed without third-party cookie blocking.
- Keeps security aligned with modern browser requirements by ensuring all cross-site cookies are marked `Secure`.

## Operational Notes
- Only touches WordPress cookies; third-party cookies remain unchanged.
- Requires PHP environments that support `header_register_callback()` (standard for current WordPress hosting).
- Network activation is recommended anywhere the cross-domain editor experience is available.
