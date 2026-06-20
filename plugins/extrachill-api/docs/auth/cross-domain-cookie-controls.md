# Cross-Domain Cookie Controls

> **Note:** The extrachill.link **edit button** no longer depends on this helper.
> It now authenticates with a wp-native bearer token (see
> `inc/auth/extrachill-link-token-handoff.php` and `docs/routes/artists/permissions.md`),
> which is immune to SameSite / third-party-cookie restrictions. This SameSite
> control remains only for any other flow that may still rely on cross-site
> WordPress cookies; audit and retire it if nothing else does.

## Purpose
Adds `SameSite=None; Secure` to every WordPress authentication cookie so REST requests originating from `extrachill.link` carry logged-in identity when hitting `artist.extrachill.com`.

## Behavior
- `ec_register_cookie_samesite_callback()` hooks into `init` (priority 1).
- Registers `ec_add_samesite_none_to_wordpress_cookies()` with `header_register_callback()` so it runs immediately before headers are sent.
- Iterates over every `Set-Cookie` header, targets only values containing `wordpress_`, and appends `SameSite=None; Secure` unless already present.

## User Impact
Users managing artist link pages on `extrachill.link` stay authenticated when the interface polls `/extrachill/v1/artists/{id}/permissions`, ensuring edit buttons reflect accurate access without forcing re-login.
