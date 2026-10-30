# Auth Sessions

List and revoke the current user's active device sessions (Connected Apps).

Thin adapters over the `wp-native/auth-sessions` and `wp-native/auth-revoke-session`
abilities (wp-native-auth). Both abilities are registered `show_in_rest => false`
there on purpose; these routes exist so a browser client can reach them without
flipping that flag. Both abilities resolve the acting user from the
authenticated bearer token / cookie session internally — neither accepts a
user ID as input, so a caller can only ever see or revoke its own sessions.

## Endpoints

### List Sessions

**Endpoint**: `GET /wp-json/extrachill/v1/auth/sessions`

**Purpose**: Return every active (non-revoked, non-expired) device session for
the current user, including OAuth-bound sessions (Connected Apps).

**Permission**: Requires logged-in user

**Parameters**: None

**Response** (HTTP 200):
```json
{
  "sessions": [
    {
      "device_id": "550e8400-e29b-41d4-a716-446655440000",
      "device_name": "Chris's iPhone",
      "created_at": "2026-01-01T12:00:00+00:00",
      "last_used_at": "2026-01-05T09:30:00+00:00",
      "expires_at": "2026-02-01T12:00:00+00:00",
      "current": true,
      "oauth_client_id": null,
      "oauth_client_name": null
    },
    {
      "device_id": "6ba7b810-9dad-41d1-80b4-00c04fd430c8",
      "device_name": null,
      "created_at": "2026-01-02T08:00:00+00:00",
      "last_used_at": "2026-01-04T18:00:00+00:00",
      "expires_at": "2026-02-02T08:00:00+00:00",
      "current": false,
      "oauth_client_id": "chatgpt",
      "oauth_client_name": "ChatGPT"
    }
  ]
}
```

**Response Fields** (per session row):

| Field | Type | Description |
|-------|------|-------------|
| `device_id` | string | UUID v4 device identifier |
| `device_name` | string\|null | Client-supplied device label, if any |
| `created_at` | string | Session creation time (ISO 8601) |
| `last_used_at` | string\|null | Last refresh time (ISO 8601), or null if never refreshed |
| `expires_at` | string | Refresh token expiration time (ISO 8601) |
| `current` | boolean | True if this is the device making the request |
| `oauth_client_id` | string\|null | OAuth client identifier when the session is an OAuth grant (e.g. a connected AI client); null for native app sessions |
| `oauth_client_name` | string\|null | Human-readable client name recorded at grant creation; null for native app sessions |

**Error Responses**:
- `401` - User not logged in
- `500` - wp-native-auth dependency unavailable

**Implementation Details**:
- Wraps the `wp-native/auth-sessions` ability unchanged — the route does no
  filtering, reshaping, or business logic of its own.
- The ability derives the user from the authenticated session; the route
  never forwards a user ID.

**File**: `inc/routes/auth/sessions.php`

---

### Revoke Session

**Endpoint**: `DELETE /wp-json/extrachill/v1/auth/sessions/{device_id}`

**Purpose**: Revoke one of the current user's device sessions — used to sign
out a lost device or disconnect an AI client (Connected Apps).

**Permission**: Requires logged-in user

**Parameters**:
- `device_id` (string, required, path) — UUID v4 device identifier to revoke

**Response** (HTTP 200):
```json
{
  "revoked": true
}
```

`revoked` is `false` (not an error) when no matching, non-revoked session
exists for the current user with that `device_id` — revoking an
already-revoked or unknown device is idempotent, not an error.

**Error Responses**:
- `400` - `device_id` is not a valid UUID v4 (rejected by the route's own arg
  schema before the ability runs)
- `401` - User not logged in
- `500` - wp-native-auth dependency unavailable

**Security**: `device_id` only ever selects *which of the current user's own
sessions* to revoke. The ability resolves the owning user from the
authenticated session, never from input, so a caller cannot revoke another
user's session even if it supplies another user's `device_id`.

**Method Choice**: The ability itself declares no explicit `destructive` or
`idempotent` annotation (both default to `null`/unset in wp-native-auth). This
route uses `DELETE` because the operation removes a resource addressed by a
path segment and is naturally idempotent — a second call against an
already-revoked `device_id` returns `revoked: false` rather than an error —
consistent with the `DELETE /artists/{id}/roster/{user_id}` and
`DELETE /community/drafts` conventions already used in this plugin.

**Implementation Details**:
- Wraps the `wp-native/auth-revoke-session` ability unchanged.

**File**: `inc/routes/auth/sessions.php`

---

## Usage Examples

### List and Render Connected Sessions (JavaScript)

```javascript
async function getSessions() {
  const response = await fetch('/wp-json/extrachill/v1/auth/sessions', {
    headers: {
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    }
  });

  if (!response.ok) {
    throw new Error('Could not load sessions.');
  }

  const { sessions } = await response.json();
  return sessions;
}
```

### Revoke a Session

```javascript
async function revokeSession(deviceId) {
  const response = await fetch(`/wp-json/extrachill/v1/auth/sessions/${deviceId}`, {
    method: 'DELETE',
    headers: {
      'Authorization': 'Bearer ' + localStorage.getItem('access_token')
    }
  });

  const { revoked } = await response.json();
  return revoked;
}
```

---

## Usage Notes

**Connected Apps**:
- OAuth-bound sessions (`oauth_client_id` not null) represent third-party AI
  clients (ChatGPT, Claude, Gemini, etc.) that were granted account access
  through the OAuth 2.1 authorization server.
- `oauth_client_name` is the human-readable label to show in a "Connected
  Apps" settings screen; fall back to `oauth_client_id` if null while
  `oauth_client_id` itself is not null.

**Related Endpoints**:
- [Auth Me](me.md) - Get current authenticated user
- [Auth Logout](logout.md) - Revoke the *current* device's own session
- [Auth Refresh](refresh.md) - Refresh access tokens
