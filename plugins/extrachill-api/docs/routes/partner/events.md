# Partner events

`GET /extrachill/v1/partner/events` — keyed, venue-scoped upcoming events for a partner venue's own website (Extra-Chill/extrachill#1802). Source: [`inc/routes/partner/events.php`](../../../inc/routes/partner/events.php).

- **Auth:** header `X-Extrachill-Partner-Key`. The key selects the venue; there is no venue parameter, and a key in the query string is ignored. Keys are issued per venue with `wp extrachill venues partner-key`.
- **Query:** `limit` integer 1–50, default 20.
- **Ability:** `extrachill/get-partner-venue-events` (extrachill-events, `show_in_rest: false`). The response body is the ability output unchanged; see [extrachill-events abilities](../../../../extrachill-events/docs/abilities.md#extrachillget-partner-venue-events) for the field contract.
- **Affinity:** `/extrachill/v1/partner/` routes to the events site. The partner key header is forwarded across the signed affinity hop (bound into the signature digest) only for this route family.
- **Headers:** `Cache-Control: private, max-age=300`, `Vary: X-Extrachill-Partner-Key`, `X-Robots-Tag: noindex`.
- **Rate limit:** public-read admission, scope `partner_events`, 60 requests/minute per client.
- **Errors:** 401 `extrachill_partner_key_invalid` for a missing, malformed, unknown, rotated, revoked, or deleted-venue key; 400 for an invalid `limit`; 429 when rate limited; 503 when the events ability is unavailable.
