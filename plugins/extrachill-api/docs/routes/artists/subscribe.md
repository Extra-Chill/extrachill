# Artist Subscriber Signup Endpoint

## Route
`POST /wp-json/extrachill/v1/artists/{id}/subscribe`

## Purpose
Used by public link pages so fans can subscribe to artist updates. The endpoint validates inputs and hands the subscription to platform handlers via filter hooks.

## Request Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `id` | integer | Yes | Artist profile post ID (in URL path) |
| `email` | string | Yes | Sanitized with `sanitize_email` and validated with `is_email`. |

## Processing Flow
1. Validates request payload using the REST argument schema. Invalid email immediately triggers `400` errors.
2. Confirms the artist ID belongs to the `artist_profile` post type.
3. Fires `apply_filters( 'extrachill_artist_subscribe', null, $artist_id, $email )` so consuming plugins can store the subscriber (e.g., Mailchimp, internal CRM).
4. Returns:
```json
{
  "message": "Thank you for subscribing!"
}
```

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `invalid_email` | 400 | Email missing or not valid. |
| `invalid_artist` | 400 | Post ID is not an `artist_profile`. |
| Filter-provided error | variable | Any `WP_Error` returned by the subscription handler is surfaced directly.

## File
`inc/routes/artists/subscribe.php`

## Integration Notes
- Ensure a filter handler is attached to `extrachill_artist_subscribe`; otherwise the endpoint will succeed without storing data.
- Recommended to show the returned message to the user so filter handlers can customize the confirmation copy.
