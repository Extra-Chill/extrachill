# Artist Subscribers Management

REST API endpoints for managing artist email subscribers.

## Endpoints

### List Subscribers

**Endpoint**: `GET /wp-json/extrachill/v1/artists/{id}/subscribers`

**Purpose**: Retrieve paginated list of subscribers for an artist profile.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)
- `page` (integer, optional) - Page number (default: 1)
- `per_page` (integer, optional) - Results per page (default: 20, max: 100)

**Response** (HTTP 200):
```json
{
  "subscribers": [
    {
      "id": 1,
      "email": "fan@example.com",
      "subscribed_date": "2025-01-15"
    },
    {
      "id": 2,
      "email": "another@example.com",
      "subscribed_date": "2025-01-14"
    }
  ],
  "total": 45,
  "page": 1,
  "per_page": 20
}
```

**Permission**: User must have permission to manage the artist (`ec_can_manage_artist()`)

**File**: `inc/routes/artists/subscribers.php`

### Export Subscribers

**Endpoint**: `GET /wp-json/extrachill/v1/artists/{id}/subscribers/export`

**Purpose**: Export all subscribers for an artist as CSV for email marketing integration.

**Parameters**:
- `id` (integer, required) - Artist profile post ID (in URL path)
- `include_exported` (boolean, optional) - Whether to include previously exported subscribers (default: false)

**Response**: Subscriber data for CSV generation (HTTP 200)

**Response Format**: JSON with subscriber array
```json
{
  "subscribers": [
    { "email": "fan@example.com", "subscribed_date": "2025-01-15" },
    { "email": "another@example.com", "subscribed_date": "2025-01-14" }
  ]
}
```

**Permission**: User must have permission to manage the artist

**File**: `inc/routes/artists/subscribers.php`

## Error Responses

**Invalid Artist** (HTTP 400):
```json
{
  "code": "invalid_artist",
  "message": "Invalid artist specified.",
  "data": { "status": 400 }
}
```

**Permission Denied** (HTTP 403):
```json
{
  "code": "permission_denied",
  "message": "You do not have permission to view subscribers for this artist.",
  "data": { "status": 403 }
}
```

**Fetch Failed** (HTTP 500):
```json
{
  "code": "fetch_failed",
  "message": "Could not fetch subscriber data.",
  "data": { "status": 500 }
}
```

## Dependencies

- Artist platform integration for permission checks (`ec_can_manage_artist()`)
- Filter hooks for subscriber data retrieval:
  - `extrachill_get_artist_subscribers` - For paginated subscriber lists
  - `extrachill_export_artist_subscribers` - For CSV export data

## Integration

Used by artist dashboards and email marketing tools to manage subscriber lists and export data for external services.
