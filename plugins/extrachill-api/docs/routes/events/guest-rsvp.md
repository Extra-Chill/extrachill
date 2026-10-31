# Guest RSVP Endpoint

## Route
`POST /wp-json/extrachill/v1/events/{id}/guest-rsvp`

## Purpose
Lets a logged-out visitor RSVP to a perk-enabled event with only an email address. Thin adapter over the `extrachill/guest-rsvp-event` ability (extrachill-events), which resolves or creates a claimable account via `extrachill/resolve-account-by-email` (extrachill-users), marks the visitor Going, and emails the RSVP pass. When the account is unclaimed, that same email carries a set-password link.

Site affinity: `events` (the `/extrachill/v1/events/` prefix).

## Admission
1. Cloudflare Turnstile (`turnstile_response`) via `ec_turnstile_check_request()`.
2. Shared per-client public-write rate limit, scope `guest-rsvp`, default 5 per minute (filter `extrachill_api_guest_rsvp_rate_limit`).

The ability itself is `show_in_rest: false`; this route is its only public write boundary.

## Request
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer (path) | Yes | Event post ID. Forwarded as `event_id`. |
| `email` | string | Yes | Sanitized with `sanitize_email()`. |
| `turnstile_response` | string | Yes in production | Consumed by admission, never forwarded. |
| `referrer` | string | No | External referrer URL for attribution. |
| `utm` | object | No | Only `utm_*` keys are forwarded, as sanitized text. |
| `password` | string | When the event has one | Guest list password. Forwarded verbatim; the ability checks it against the stored hash and limits failed attempts. |

## Response
Success is always opaque:

```json
{ "status": "rsvped" }
```

The response never includes the pass code, the user ID, or whether the email already had an account. The pass and any claim link arrive by email only.

## Errors
| Code | Status | When |
| --- | --- | --- |
| `turnstile_missing_token` / `turnstile_failed` | 403 | Missing or invalid Turnstile token. |
| `public_write_rate_limited` | 429 | Over the per-client budget. |
| `invalid_email` | 400 | Not a valid email. |
| `event_not_found` | 404 | Not a published event. |
| `guest_rsvp_unavailable` | 403 / 503 | Event has no RSVP perk, or a dependency is missing. |
| `guest_rsvp_closed` | 410 | Event is in the past. |
| `guest_rsvp_failed` | 500 | Generic failure; no account details are exposed. |
| `invalid_password` | 403 | Wrong or missing guest list password. |
| `guest_list_password_locked` | 429 | Too many wrong passwords from this client for this event. |
| `guest_list_full` | 409 | Every guest list spot is claimed. |
