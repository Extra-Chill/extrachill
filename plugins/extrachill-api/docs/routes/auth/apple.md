# Apple authentication

## POST /wp-json/extrachill/v1/auth/apple

**Source:** `inc/routes/auth/apple.php`.

**Permission callback:** `__return_true`; no existing login required. Users owns identity-token/nonce verification and account/session issuance.

**Parameters:** required string `identity_token`, `nonce` (raw nonce), `device_id` (trimmed UUID v4); optional strings `device_name`, `given_name`, `family_name`, `registration_source`, `registration_method`, `registration_page` (URL-sanitized), and `newsletter_consent` boolean (false). Other optional strings and the device ID are text-sanitized.

**Delegation:** calls `ec_apple_login_with_tokens(identity_token, device_id, options)` in `extrachill-users`, not a WordPress ability. Options rename `nonce` to `raw_nonce` and include the optional device/name/registration/consent fields.

**Response:** Users authentication/token result, unchanged (200), matching the token-session service rather than an API-defined projection.

**Errors:** `extrachill_dependency_missing` (500) if Apple service or UUID helper is absent; `apple_not_configured` (503) when the configuration helper reports disabled; `missing_token`, `missing_nonce`, `invalid_device_id` (400); `two_factor_required` (403) when the member uses two-factor authentication and in-app 2FA is unavailable on this site. Service errors propagate.
