# Single-Origin Google OAuth Architecture

This document describes the canonical-origin pattern used for Google sign-in across the Extra Chill multisite network.

## Why

Google Identity Services (GIS) requires every JavaScript origin that renders the sign-in button to be explicitly listed in the **Authorized JavaScript origins** field in Google Cloud Console. No wildcards, no parent-domain inheritance. Every new subsite added to the network would otherwise need a manual GCP touch before Google sign-in worked there — and the failure mode is silent (the button just doesn't paint, no PHP error, no debug log entry).

## How it works

### Canonical origin: `community.extrachill.com`

The community subsite is the only origin registered in GCP's authorized list. It's the existing identity hub (login page, lostpassword form, onboarding flow), so concentrating Google sign-in here is a low-disruption move.

### Behavior by site

| Where the `extrachill/login-register` block renders | What you see |
|---|---|
| `community.extrachill.com` (canonical) | The GIS button renders normally — `accounts.google.com/gsi/client` script loaded, button populated by `google-signin.js`. |
| Any other subsite | A styled "Continue with Google" link that points at `https://community.extrachill.com/login/?google_redirect=<encoded-current-url>`. Clicking it navigates to the canonical origin. After successful auth, the canonical origin 302s the user back to the URL they came from. |

### Why no token-exchange dance is needed

The auth cookie is set on the `.extrachill.com` cookie domain (`COOKIE_DOMAIN` constant), so any cookie set on `community.extrachill.com` is automatically visible to every sibling subsite. Once the canonical origin completes Google sign-in and sets the auth cookie, the user is effectively logged in everywhere on the network.

### return_to validation

The `google_redirect` query param is parsed by `ec_users_get_validated_google_redirect_from_request()` and validated by `ec_users_is_valid_return_to_url()` before being honored. The allowlist is:

- Scheme must be `https`
- Host must be exactly `extrachill.com` or end in `.extrachill.com`

This closes a latent open-redirect surface: the Google handler used to reflect `success_redirect_url` back to the client unchanged, so a malicious actor could craft a sign-in link with `success_redirect_url=https://phishing.example.com/`. After this change, only `*.extrachill.com` hosts can be redirect targets.

## Code surface

| File | Role |
|---|---|
| `inc/oauth/google-canonical-origin.php` | Helpers: `ec_users_is_canonical_google_origin()`, `ec_users_canonical_google_signin_url()`, `ec_users_is_valid_return_to_url()`, `ec_users_get_validated_google_redirect_from_request()` |
| `inc/oauth/google-service.php` | `ec_google_login_with_tokens()` now validates `success_redirect_url` against the allowlist before reflecting it |
| `blocks/login-register/render.php` | Branches on `ec_users_is_canonical_google_origin()`; only enqueues the GIS script on the canonical origin; passes `googleSignInRedirectUrl` to the React view layer on non-canonical sites; picks up the `google_redirect` query param on the canonical side and feeds it into `success_redirect_url` |
| `blocks/login-register/view.js` | `<GoogleButtons redirectUrl={config.googleSignInRedirectUrl} />` renders either the GIS container (canonical) or a styled redirect link (non-canonical) |
| `blocks/login-register/style.css` | `.google-signin-button--redirect` styled to visually match Google's "outline" theme |

## Adding a new subsite

There is nothing to do. The new subsite renders the redirect link automatically; Google sign-in works on day one.

If you want the new subsite to render the GIS button locally instead (e.g. you're testing a future migration), add its origin to the GCP Authorized JavaScript origins list — but the canonical-origin design means you should rarely if ever need to.

## End-to-end flow

```
User on https://studio.extrachill.com/compose?draft=42
  ↓ clicks "Continue with Google" (redirect-variant)
GET https://community.extrachill.com/login/?google_redirect=https%3A%2F%2Fstudio.extrachill.com%2Fcompose%3Fdraft%3D42
  ↓ blocks/login-register/render.php picks up the param via
    ec_users_get_validated_google_redirect_from_request()
  ↓ feeds it into config.successRedirectUrl
  ↓ GIS button renders; user clicks; ID token POSTed to
    /wp-json/extrachill/v1/auth/google with success_redirect_url
  ↓ ec_google_login_with_tokens() validates success_redirect_url
    against the *.extrachill.com allowlist, mints auth cookie
    on .extrachill.com (visible to all subsites)
  ↓ returns { redirect_url: "https://studio.extrachill.com/compose?draft=42", ... }
  ↓ google-signin.js calls window.location.assign(redirect_url)
User lands on https://studio.extrachill.com/compose?draft=42, logged in.
```

## Out of scope

- **Mobile OAuth** (iOS / Android client IDs are separate and don't have this restriction).
- **Apple Sign-In** would follow the same pattern if Apple surfaces a similar origin restriction in the future.
