# Browser handoff (app → browser)

The browser handoff system lets a user authenticate in a non-browser client (e.g., the mobile app) and then bootstrap a WordPress cookie session in a real browser using a short-lived, single-use token.

## Implementation

### Token creation

- Function: `extrachill_users_create_browser_handoff_token( int $user_id, string $redirect_url ): string`
- File: `inc/auth-tokens/browser-handoff-token.php`
- Storage: `set_site_transient( 'ec_browser_handoff_' . $token, $payload, 60 )`
- Payload:
  - `user_id` (int)
  - `redirect_url` (string)
  - `created_at_ts` (int)

### Token consumption

- Function: `extrachill_users_consume_browser_handoff_token( string $token )`
- File: `inc/auth-tokens/browser-handoff-token.php`
- Behavior:
  - Reads payload from the site transient
  - Deletes the transient immediately (single-use)
  - Returns `WP_Error( 'invalid_handoff_token', ... )` on invalid/expired tokens

### Browser handler

- Handler: `extrachill_users_handle_browser_handoff()`
- File: `inc/auth/browser-handoff-handler.php`
- Hooks:
  - `admin_post_nopriv_extrachill_browser_handoff`
  - `admin_post_extrachill_browser_handoff`
- Input:
  - `ec_browser_handoff` query parameter (the token)

The handler:
1. Consumes the token payload.
2. Validates the redirect host:
   - Allows `extrachill.com` and `*.extrachill.com`
   - Rejects hosts containing `extrachill.link`
3. Sets the WordPress auth cookies (`wp_set_auth_cookie( $user_id, false )`).
4. Adds the redirect host to `allowed_redirect_hosts` for the current request.
5. Redirects to the requested URL via `wp_safe_redirect()`.

## Notes

- Tokens expire after **60 seconds**.
- The cookie is **non-persistent** (`remember = false`) for the handoff cookie set.
