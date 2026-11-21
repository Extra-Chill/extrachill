# Guest digest signup and ticket refunds

Paths are relative to `/wp-json/extrachill/v1`; both use Events affinity.

## POST /events/local-scene-digest/guest-subscribe

**Source:** `inc/routes/events/local-scene-digest-guest.php`.

**Permission:** `extrachill_api_local_scene_digest_guest_permission`: verifies Turnstile first, then shared per-client public-write admission (default 5 per minute, filterable). Missing security helper returns `guest_digest_security_unavailable` (503). No login required.

**Parameters:** required `location_slug` and `email` strings; optional `scope` (`location` default, or `venue`), `referrer` string, `utm` object, `turnstile_response` string. With `scope: venue`, `location_slug` carries a venue term slug and the request asks for that venue's weekly show updates (extrachill#1724); `scope` is forwarded only when it is exactly `venue`. The handler sanitizes slug/email/referrer and forwards only scalar `utm_*` keys. The Turnstile token is transport-only.

**Owner:** Events ability `extrachill/guest-subscribe-local-scene-digest` resolves accounts, confirmation emails and subscriptions.

**Response:** always `{ "status": "check_inbox" }` on success, without disclosing account or subscription existence. Ability errors propagate; missing ability returns `ability_not_found` (503).

## POST /events/tickets/orders/(?P<reference>[A-Za-z0-9_-]{1,191})/refund

**Source:** `inc/routes/events/ticket-refund.php`.

**Permission:** `is_user_logged_in`. Shop additionally checks seller finance authority and rejects refunds after check-in.

**Parameters:** path `reference` (1–191 letters, digits, underscores or hyphens); no body fields.

**Owner:** Shop ability `extrachill/refund-ticket-order`, invoked via `ec_cross_site_rest_request_http` as the current user with `input.order_reference`.

**Response:** `{ "refunded": boolean }`; absent/false upstream `refunded` becomes false. Missing cross-site transport returns `ticket_refund_unavailable` (503); upstream errors propagate.
