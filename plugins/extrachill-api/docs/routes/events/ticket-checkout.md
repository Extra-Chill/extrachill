# Ticket checkout

`POST /wp-json/extrachill/v1/events/tickets/checkout`

Public, no account required. Turnstile is checked first, followed by the shared
per-client public-write limiter (`ticket-checkout`, default 5/minute; filter
`extrachill_api_ticket_checkout_rate_limit`). Missing security support fails closed.

Body: `{event: integer, quantity: integer (1–10), name: string, email: string,
turnstile_response: string}`.

Returns `{checkout_url, session_id}`. Redirect the buyer to `checkout_url`.
Sale closed, insufficient inventory, and seller payments unavailable return 409.
The route has Shop site affinity, despite its Events URL, and invokes Shop's hidden
`extrachill/start-ticket-checkout` ability locally. The core ability REST runner
is intentionally disabled for public checkout to prevent bypassing admission.

Success returns to the canonical event URL with `tickets=success` and
`session_id`; cancellation uses `tickets=cancelled`. A success redirect is not
proof of fulfillment: the signed Connect webhook owns issuance.
