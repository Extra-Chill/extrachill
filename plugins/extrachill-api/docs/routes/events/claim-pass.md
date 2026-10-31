# Guest List Claim Endpoint

## Route
`POST /wp-json/extrachill/v1/events/{id}/claim-pass`

## Purpose
Lets a logged-in member claim a spot on a password-gated guest list and get their RSVP pass back on screen (extrachill#433). Thin adapter over the `extrachill/claim-event-pass` ability (extrachill-events), which checks the guest list password and capacity, marks the member Going, and issues and emails the pass. Logged-out visitors use [guest-rsvp](guest-rsvp.md) instead.

Site affinity: `events` (the `/extrachill/v1/events/` prefix).

## Admission
1. Logged-in user (cookie + REST nonce).
2. Shared per-client public-write rate limit, scope `claim-pass`, default 5 per minute (filter `extrachill_api_claim_pass_rate_limit`). This bounds password guessing.

The ability itself is `show_in_rest: false`; this route is its only public write boundary.

## Request
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer (path) | Yes | Event post ID. Forwarded as `event_id`. |
| `password` | string | When the event has one | Forwarded verbatim; checked against the stored hash. |

## Response
The caller's own pass:

```json
{ "issued": true, "code": "ABCDE-FGHJK-LMNPQ", "perk_text": "Extra Chill guest list", "status": "active" }
```

## Errors
| Code | Status | When |
| --- | --- | --- |
| `rest_not_logged_in` | 401 | Not logged in. |
| `public_write_rate_limited` | 429 | Over the per-client budget. |
| `event_not_found` | 404 | Not a published event. |
| `guest_list_unavailable` | 403 / 503 | No RSVP perk on the event, or a dependency is missing. |
| `guest_rsvp_closed` | 410 | Event is in the past. |
| `invalid_password` | 403 | Wrong or missing guest list password. |
| `guest_list_full` | 409 | Every spot is claimed. |
| `guest_list_already_checked_in` | 409 | The caller's pass was already redeemed. |
