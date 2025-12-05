# Newsletter Subscription Endpoint

## Route
`POST /wp-json/extrachill/v1/newsletter/subscribe`

## Purpose
Centralizes opt-ins from every signup surface (site header, article footers, blocks) and forwards them to the multisite subscription handler so marketing lists stay synchronized across the network.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `email` | string | Yes | Validated via `is_email` and sanitized with `sanitize_email`. |
| `context` | string | Yes | Free-form identifier (e.g., `homepage`, `artist-link-page`) that downstream systems use for attribution. |

## Processing Flow
1. REST schema enforces both arguments and rejects malformed emails before the handler runs.
2. Ensures the helper `extrachill_multisite_subscribe()` exists (provided by the newsletter/multisite plugin family); missing helpers return `500 function_missing`.
3. Passes the sanitized payload to the helper, which handles deduplication, tagging, and third-party sync.
4. Responds with the helper’s status message so UIs can display the exact confirmation copy.

## Response Contract
Successful requests mirror the helper payload:
```json
{
  "success": true,
  "message": "Thanks for joining the list!"
}
```
Failures return `WP_Error` objects, typically with code `subscription_failed` and a helper-defined message (e.g., duplicate email, provider outage).

## Integration Guidance
- Always send JSON and include the `context` string so marketing funnels can attribute conversions accurately.
- Because the endpoint is public, remember to include REST nonces or application passwords when calling from WordPress-rendered pages to satisfy core security checks.
- Surface the returned `message` verbatim; downstream handlers may localize or customize copy per campaign.
