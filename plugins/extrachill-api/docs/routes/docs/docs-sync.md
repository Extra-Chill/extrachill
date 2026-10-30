# Documentation Sync

REST API endpoint for synchronizing documentation content from source .md files to the documentation platform.

## Endpoint

### Sync Documentation

**Endpoint**: `POST /wp-json/extrachill/v1/sync/doc`

**Purpose**: Sync documentation from source .md files to documentation platform with hash-based change detection.

**Parameters**:
- `source_file` (string, required) - Source file path
- `title` (string, required) - Documentation title
- `content` (string, required) - Documentation content
- `platform_slug` (string, required) - Platform identifier
- `slug` (string, required) - Documentation page slug
- `filesize` (integer, required) - Source file size
- `timestamp` (string, required) - File timestamp
- `force` (boolean, optional) - Force update if already exists (default: false)

**Response - Created/Updated** (HTTP 200):
```json
{
  "success": true,
  "action": "created",
  "id": 123
}
```

**Response - Skipped** (HTTP 200):
```json
{
  "success": true,
  "action": "skipped",
  "id": 123
}
```

**Permission**: Requires `edit_posts` capability

**File**: `inc/routes/docs-sync-routes.php`

## Error Responses

**Permission Denied** (HTTP 403):
```json
{
  "code": "rest_forbidden",
  "message": "Sorry, you are not allowed to do that.",
  "data": { "status": 403 }
}
```

**Validation Error** (HTTP 400):
```json
{
  "code": "rest_invalid_param",
  "message": "Invalid parameter(s): source_file",
  "data": {
    "status": 400,
    "params": {
      "source_file": "Missing parameter"
    }
  }
}
```

## Dependencies

- `ExtraChill_Docs_Sync_Controller` class for handling sync operations
- Documentation platform integration for content storage
- Hash-based change detection to avoid unnecessary updates

## Integration

Used by documentation generation agents to automatically sync .md files to the platform's documentation system.