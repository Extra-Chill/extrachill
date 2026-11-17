# Auth Login Continue

Finish a login that `/auth/login`, `/auth/google`, or `/auth/apple` paused
with `challenge_required`, for example to ask for a two-factor code.

## Endpoint

**Endpoint**: `POST /wp-json/extrachill/v1/auth/login/continue`

**Permission**: Public. The continuation token is the credential: it is bound
to the device and client that started the login, and it expires five minutes
after the challenge was issued.

**Parameters**:
- `continuation_token` (string, required) - From the challenge response
- `device_id` (string, required) - The UUID v4 used for the login
- `challenge_response` (object, required) - The answer for `challenge_policy`

For `challenge_policy: "two-factor"`, `challenge_response` is one of:

```json
{ "provider": "Two_Factor_Totp", "code": "123456" }
```

```json
{ "provider": "Two_Factor_Email", "resend": true }
```

`provider` is one of the `challenge.providers[].key` values. Omit it to use
`challenge.provider`. Resend works for providers with `"resend": true`.

**Request Example**:
```json
{
  "continuation_token": "...",
  "device_id": "550e8400-e29b-41d4-a716-446655440000",
  "challenge_response": { "provider": "Two_Factor_Totp", "code": "123456" }
}
```

## Responses

**Login complete** (HTTP 200): the same response the starting route returns on
success, with the destination chosen when the login started. A login started
on `/auth/login`:

```json
{
  "access_token": "...",
  "access_expires_at": "2025-01-15T10:30:00Z",
  "refresh_token": "...",
  "refresh_expires_at": "2025-02-15T10:30:00Z",
  "redirect_url": "https://community.extrachill.com/",
  "user": { "id": 123, "username": "user", "display_name": "User Name", "avatar_url": "https://...", "profile_url": "https://..." }
}
```

A login started on `/auth/google` or `/auth/apple` adds `success` and
`onboarding_completed` and includes `email` in `user`, as those routes do.
When the login started with `set_cookie: true`, the WordPress login cookie is
set by this response.

**Challenge again** (HTTP 200): after a resend, the challenge comes back with
the same `continuation_token` and `continuation_expires_at`, and
`challenge.status` set to `"code_sent"`.

**Error Responses**:
- `400` - `invalid_device_id`, `rest_missing_callback_param`, or `two_factor_provider_not_enabled`
- `401` - `two_factor_invalid` (wrong code; try again with the same token), `invalid_continuation`, or `continuation_expired`
- `403` - `two_factor_password_reset_required` (too many failed codes; the password was reset and the login cannot continue), or an account policy error such as `extrachill_user_blocked`
- `409` - `continuation_in_progress`
- `429` - `two_factor_too_fast` (wait `data.retry_after` seconds), or `continuation_rate_limited` (too many attempts; log in again)
- `500` - `extrachill_dependency_missing`
- `503` - `challenge_policy_unavailable`

**Implementation Details**:
- Validates `device_id`, then calls `extrachill_users_continue_login_with_tokens()`
- Verification, attempt limits, and session creation are handled by wp-native-auth's challenge policies; the `two-factor` policy is registered by extrachill-users

**File**: `inc/routes/auth/login-continue.php`

**Related Endpoints**:
- [Auth Login](login.md)
- [Auth Google](google.md)
- [Auth Apple](apple.md)
