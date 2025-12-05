# Artist Subscriber Management Endpoints

Two authenticated endpoints expose subscriber records for artist profiles. Both rely on `ec_can_manage_artist()` for authorization and delegate data lookups/exports to filter hooks.

## Fetch Paginated Subscribers
`GET /wp-json/extrachill/v1/artist/subscribers`

### Query Parameters
| Field | Type | Required | Default | Notes |
| --- | --- | --- | --- | --- |
| `artist_id` | integer | Yes | – | Must be an `artist_profile` post. |
| `page` | integer | No | 1 | Minimum 1. |
| `per_page` | integer | No | 20 | Clamped between 1 and 100. |

### Flow
1. Ensures `artist_id` targets an `artist_profile`.
2. Requires `ec_can_manage_artist( current_user_id, artist_id )`.
3. Calls `apply_filters( 'extrachill_get_artist_subscribers', null, $artist_id, [ 'page' => $page, 'per_page' => $per_page ] )`.
4. Returns whatever structure the filter supplies (must include `subscribers`).

### Expected Response Shape
```
{
  "subscribers": [ { "email": "fan@example.com", ... } ],
  "pagination": { "page": 1, "per_page": 20, "total": 54 }
}
```

## Export Subscribers for CSV
`GET /wp-json/extrachill/v1/artist/subscribers/export`

### Query Parameters
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `artist_id` | integer | Yes | Same validation + permission as the paginated endpoint. |
| `include_exported` | boolean | No | When true, downstream handlers may include rows already exported. |

### Flow
1. Performs the same post-type and permission checks as above.
2. Calls `apply_filters( 'extrachill_export_artist_subscribers', null, $artist_id, $include_exported )`.
3. Returns the filter result, which should include all subscriber rows ready for CSV generation.

## Error Conditions
| Code | HTTP Status | Description |
| --- | --- | --- |
| `invalid_artist` | 400 | Post ID missing or not an `artist_profile`. |
| `permission_denied` | 403 | Current user cannot manage the artist. |
| `fetch_failed` / `export_failed` | 500 | Filter handlers returned unexpected data. |

## Implementation Notes
- Both endpoints simply broker requests; actual data access must be implemented by hooking the relevant filters.
- Hook handlers can return `WP_Error` to expose contextual failures back to the client.
