# Partner API reference

Fetch one venue's upcoming events for server-rendered display on its website. See [custom builds](custom-builds.md) for an implementation example.

## Request and authentication

```http
GET https://events.extrachill.com/wp-json/extrachill/v1/partner/events?limit=20
X-Extrachill-Partner-Key: <your-key>
```

`limit` is an integer from **1 to 50**, default **20**. The venue comes from the key, not a venue parameter. Each key grants access to one venue. Ask the Extra Chill team for a key, or to rotate or revoke one.

**Keep the key server-side only.** Store it in a server environment variable or secret store. Never ship it to browsers, client components, public environment variables, HTML, or public repositories. Call this endpoint from your server, not from browser JavaScript.

## Response

This illustrative success response uses the complete contract; nullable fields contain JSON `null`, not the string `"null"`:

```json
{
  "venue": {
    "id": 1524,
    "name": "Lo-Fi Brewing",
    "slug": "lo-fi-brewing",
    "url": "https://events.extrachill.com/venue/lo-fi-brewing/",
    "booking_embed_url": null
  },
  "events": [
    {
      "id": 123,
      "title": "Example show",
      "url": "https://events.extrachill.com/event/example-show/",
      "start": "2026-12-01T20:00:00-05:00",
      "end": null,
      "timezone": "America/New_York",
      "performers": ["Example band"],
      "price": "$10",
      "image": null,
      "excerpt": "An evening of live music.",
      "status": "EventScheduled",
      "ticket": {
        "url": "https://events.extrachill.com/event/example-show/",
        "label": "Get Tickets",
        "kind": "info"
      },
      "schema": {
        "@context": "https://schema.org",
        "@type": "MusicEvent",
        "name": "Example show",
        "startDate": "2026-12-01T20:00:00-05:00"
      }
    }
  ],
  "generated_at": "2026-12-01T12:00:00+00:00",
  "cache_ttl": 300
}
```

The example's `schema` is abbreviated; render the complete object returned by the API rather than reconstructing it.

### Top-level fields

| Field | Type | Meaning |
|---|---|---|
| `venue` | object | The venue associated with the key. |
| `events` | array of objects | Upcoming events; an empty array is a successful response with no shows. |
| `generated_at` | ISO 8601 string | Response generation time. |
| `cache_ttl` | integer | Cache lifetime in seconds; currently 300. |

### Venue fields

| Field | Type | Meaning |
|---|---|---|
| `id` | integer | Extra Chill venue ID. |
| `name` | string | Display name. |
| `slug` | string | Venue slug. |
| `url` | URL string | Venue page on Extra Chill. |
| `booking_embed_url` | URL string or null | Optional hosted booking iframe URL. |

### Event fields

| Field | Type | Meaning |
|---|---|---|
| `id` | integer | Extra Chill event ID. |
| `title` | string | Event title. |
| `url` | URL string | Event page on Extra Chill. |
| `start` | ISO 8601 string | Start date/time with offset. |
| `end` | ISO 8601 string or null | End date/time, if available. |
| `timezone` | string | IANA venue timezone, such as `America/New_York`; use it for display. |
| `performers` | array of strings | Performer names. |
| `price` | string or null | Display price, such as `$10`; not a numeric checkout amount. |
| `image` | URL string or null | Featured flyer when allowed; otherwise the schema image, if available. When the flyer is used and `schema` is present, the flyer appears first in `schema.image`. |
| `excerpt` | plain-text string or null | Short event description; not HTML. |
| `status` | string | Schema.org event status, such as `EventScheduled`. |
| `ticket` | object | Ticket action described below. |
| `schema` | object | Schema.org `MusicEvent` JSON-LD, including `@context: https://schema.org` and `@type: MusicEvent`. Emit the full object server-side. |

### Ticket fields

| Field | Type | Meaning |
|---|---|---|
| `url` | URL string or null | Buy/action destination. Omit the button when null. |
| `label` | string | Button text, such as `Get Tickets` or `RSVP`. |
| `kind` | string | One of `checkout`, `external`, `rsvp`, `info`, or `none`. |

**Use `ticket.url` as-is.** Non-null ticket URLs point to Extra Chill event pages. For `checkout` and `external`, fans use the event page to check out or reach the outside seller. Do not replace these URLs with a seller's direct URL or construct checkout URLs yourself.

Free events with an external registration link use `kind: rsvp` and `label: RSVP`. Their `ticket.url` includes the stable, unsigned `ec_rsvp=1` query parameter. Preserve it when rendering the link. It highlights the **Going** button but does not record attendance: the fan must press **Going** and sign in or create an account if needed. The organizer's registration link remains available on the event page.

## Errors

- **401:** missing, invalid, revoked, or otherwise unusable key. The JSON error code is `extrachill_partner_key_invalid` (for example, `{ "code": "extrachill_partner_key_invalid" }`). Check the server's secret and request rotation if needed; do not expose credentials in an error page.
- **400:** bad request, such as an invalid `limit`. Correct the request rather than repeatedly retrying it. No additional error-code contract is specified here.

Treat non-2xx responses as errors, not event data. A failed request is not the same as a successful empty `events` array.

## Caching

Respect `cache_ttl` and the response header **`Cache-Control: private, max-age=300`**. Cache server-side per key and request arguments; do not share authenticated API responses in a public cache. Refresh after five minutes under the current contract. Never log the key. You may keep a last-known-good copy for outages, but do not treat authentication failure as a successful refresh.

The [WordPress plugin](wordpress-plugin.md) has its own 15-minute local cache and outage fallback.

## Booking embed

When `venue.booking_embed_url` is non-null, append `&parent-origin=<your https origin>` with the origin URL-encoded. For example, an origin is `https://www.example.com`, without a page path. A URL builder can safely set the `parent-origin` query parameter on the supplied URL without discarding its existing parameters (including `booking-embed=1`). The venue must have that exact origin on its booking embed allowlist; ask the team to configure it.

Render the booking URL in an iframe only. If it is null, omit the embed. **Booking is the one iframe surface; events must be server-rendered HTML and JSON-LD, never iframed.**
