# Guest List Pass Endpoint

## Route
`POST /wp-json/extrachill/v1/events/{id}/guest-list-pass`

## Purpose
Backs an event's guest list page, `/events/{slug}/guest-list` (extrachill#433). The guest gives a name and the guest list password, and gets back a pass code to show on their phone. No account is involved. An email is optional and only used to send a copy. Thin adapter over the `extrachill/issue-guest-list-pass` ability (extrachill-events), which checks the password and capacity and issues the pass.

Site affinity: `events` (the `/extrachill/v1/events/` prefix).

## Admission
1. Cloudflare Turnstile (`turnstile_response`).
2. Shared per-client public-write rate limit, scope `guest-list-pass`, default 5 per minute (filter `extrachill_api_guest_list_pass_rate_limit`). This also bounds password guessing.

The ability is `show_in_rest: false`. This route is its only public write boundary.

## Request
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer (path) | Yes | Event post ID, forwarded as `event_id`. |
| `name` | string | Yes | Shown to the door at check-in. |
| `password` | string | Yes | Forwarded verbatim and checked against the stored hash. |
| `email` | string | No | Sends a copy of the pass. Not stored. |
| `turnstile_response` | string | Yes in production | Turnstile token. |

## Response
```json
{ "code": "ABCDE-FGHJK-LMNPQ", "name": "Sarah", "qr_url": "https://events.extrachill.com/wp-admin/admin-post.php?action=ec_rsvp_pass_qr&code=ABCDE-FGHJK-LMNPQ", "perk_text": "Extra Chill guest list", "emailed": false, "spots_left": 59 }
```

Each call issues a new pass and uses a spot. The guest list page keeps the code in the browser, so revisiting the page shows the same pass and doesn't issue another.

## Errors
| Code | Status | When |
| --- | --- | --- |
| `turnstile_missing_token` / `turnstile_failed` | 403 | Turnstile not passed. |
| `public_write_rate_limited` | 429 | The client is over its rate limit. |
| `event_not_found` | 404 | The event isn't published. |
| `guest_list_unavailable` | 403 / 503 | The event has no password-gated guest list. |
| `guest_rsvp_closed` | 410 | The event is in the past. |
| `guest_name_required` | 400 | No name was given. |
| `invalid_email` | 400 | The email is malformed. Leave it blank to skip the copy. |
| `invalid_password` | 403 | Wrong password. |
| `guest_list_full` | 409 | Every spot is claimed. |
