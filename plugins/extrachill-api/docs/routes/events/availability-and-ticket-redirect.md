# Booking availability and ticket navigation

These current registrations are included so route coverage also accounts for endpoints outside the original missing-route list. Paths are relative to `/wp-json/extrachill/v1`.

## POST /venues/(?P<venue>\d+)/booking-availability

**Source:** `inc/routes/events/booking-availability.php`.

**Permission:** `extrachill_api_booking_availability_permission`, shared public-read limiter (default 60/minute). No Turnstile challenge is consumed.

**Parameters:** exact JSON body with integer `venue` equal to the positive path venue, `requested_space_key` (nonempty sanitized key, maximum 64 characters), `requested_start_at`, `requested_end_at` (valid `Y-m-d H:i:s` venue-local wall-clock strings, end after start). Query parameters and extra body keys are rejected.

**Owner/ability:** hidden Events ability `extrachill/check-booking-availability`; path venue maps to `venue_term_id`. Requires `show_in_rest` exactly false.

**Response:** only `{available: boolean}` (200), no booking details. Validation returns `booking_availability_invalid_request` (400), rate limiting returns `public_read_rate_limited` (429) with bounded Retry-After; other failures return privacy-safe `booking_availability_unavailable` (503, retryable).

## GET /events/tickets/(?P<id>\d+)/go

**Source:** `inc/routes/events/ticket-redirect.php`.

**Permission callback:** `__return_true`; handler applies navigation bot checks and per-client rate limiting. `Sec-Fetch-Site` permits same-origin/same-site; when absent, an off-network Referer is rejected. Absence of both headers is admitted into a separate ambiguous counter/budget.

**Parameters:** required positive integer path `id` (`absint`), mapped to `event_id`.

**Owner/ability:** hidden `data-machine-events/resolve-ticket-destination`; requires `show_in_rest` exactly false. No affiliate host/template logic is embedded in the API.

**Response:** 302 with `Location`, `X-Robots-Tag: noindex, nofollow`, `Cache-Control: no-store`, null body. Invalid IDs, rejected navigation, missing/visible ability, failed resolution and absent destination converge on a plain 404 rather than revealing why resolution failed.
