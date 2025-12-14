# Activity Feed

Retrieve paginated activity feed with configurable filtering and visibility controls.

## Endpoint

### Get Activity Feed

**Endpoint**: `GET /wp-json/extrachill/v1/activity`

**Purpose**: Query activity events across the network with support for filtering by blog, actor, type, and visibility settings.

**Permission**: Requires logged-in user. Private visibility requires `manage_options` capability.

**Parameters**:
- `cursor` (integer, optional) - Pagination cursor for keyset pagination
- `limit` (integer, optional) - Number of items to return
- `blog_id` (integer, optional) - Filter by specific blog
- `actor_id` (integer, optional) - Filter by activity actor (user ID)
- `visibility` (string, optional) - Filter by visibility: `public` (default) or `private` (requires admin)
- `types` (array, optional) - Array of activity type strings to include

**Response** (HTTP 200):
```json
{
  "activities": [
    {
      "id": 123,
      "blog_id": 1,
      "actor_id": 456,
      "type": "post_published",
      "object_type": "post",
      "object_id": 789,
      "timestamp": "2025-01-15T10:30:00Z",
      "visibility": "public",
      "data": {}
    }
  ],
  "cursor": 124,
  "has_more": true,
  "total": 456
}
```

**File**: `inc/routes/activity/feed.php`

## Permission Model

| Scenario | Permission | Result |
|----------|-----------|--------|
| Logged-in user, public visibility | - | Allowed |
| Logged-in user, private visibility | `manage_options` | Allowed if admin, error if not |
| Anonymous user | - | 401 Unauthorized |

## Implementation Details

- Uses `extrachill_api_activity_query()` to retrieve activity records
- Supports keyset pagination via `cursor` parameter for efficient large result sets
- Visibility filtering prevents unauthorized access to private activity
- Type filtering allows clients to request specific activity categories

## Error Responses

**Not Logged In** (HTTP 401):
```json
{
  "code": "rest_forbidden",
  "message": "Must be logged in.",
  "data": { "status": 401 }
}
```

**Insufficient Permissions** (HTTP 403):
```json
{
  "code": "rest_forbidden",
  "message": "Admin access required.",
  "data": { "status": 403 }
}
```

**Activity System Unavailable** (HTTP 500):
```json
{
  "code": "missing_activity",
  "message": "Activity system not loaded.",
  "data": { "status": 500 }
}
```

## Dependencies

- **extrachill-api**: Activity system module (`inc/activity/` directory)
- **extrachill-multisite**: Recommended for cross-site context

## Integration

Used by platform features requiring activity tracking and display:
- Community activity streams
- User activity dashboards
- Real-time notifications
- Analytics and reporting
