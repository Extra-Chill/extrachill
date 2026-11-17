# Browser Handoff Endpoint

Returns a one-time URL that sets WordPress auth cookies in a real browser and redirects to the requested destination. Used for cross-device authentication flows (e.g., mobile app to browser).

## Endpoint

```
POST /wp-json/extrachill/v1/auth/browser-handoff
```

## Authentication

Requires authenticated user (WordPress login).

## Request Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `redirect_url` | string | Yes | Absolute URL on extrachill.com domain to redirect after authentication |

## Response

```json
{
    "handoff_url": "https://extrachill.com/wp-admin/admin-post.php?action=extrachill_browser_handoff&ec_browser_handoff=TOKEN"
}
```

## Restrictions

- `redirect_url` must be on extrachill.com or *.extrachill.com domain
- extrachill.link is NOT supported (returns error)
- Token is one-time use

## Error Responses

### 400 Missing Redirect URL

```json
{
    "code": "missing_redirect_url",
    "message": "redirect_url is required.",
    "data": { "status": 400 }
}
```

### 400 Invalid Redirect URL

```json
{
    "code": "invalid_redirect_url",
    "message": "redirect_url must be on extrachill.com.",
    "data": { "status": 400 }
}
```

### 500 Dependency Missing

```json
{
    "code": "extrachill_dependency_missing",
    "message": "extrachill-users is required for browser handoff.",
    "data": { "status": 500 }
}
```

## Two-factor members

The token records when the session that minted it last passed Two Factor. For a bearer session, that is only when the `two-factor` challenge policy verified it. When the handoff is consumed for a member who uses Two Factor:

- **The minting session passed 2FA:** the cookie is set with the same 2FA time, and the member lands on `redirect_url`. Two Factor's own code screen is never shown.
- **It did not:** no cookie is set. The member is redirected to `/login/?redirect_to=<redirect_url>` and enters their code there.

## Dependencies

- extrachill-users plugin (provides `extrachill_users_create_browser_handoff_token()`)

## Related Endpoints

- [POST /auth/login](login.md) - User login returning access + refresh tokens
- [GET /auth/me](me.md) - Get current authenticated user
