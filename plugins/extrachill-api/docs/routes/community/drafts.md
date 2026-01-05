# Community Drafts

Manage bbPress topic and reply drafts with context-aware storage and retrieval.

## Endpoints

### Get Draft

**Endpoint**: `GET /wp-json/extrachill/v1/community/drafts`

**Purpose**: Retrieve a saved draft for a topic or reply with context matching.

**Permission**: Requires logged-in user

**Parameters**:
- `type` (string, required) - Draft type: `topic` or `reply`
- `forum_id` (integer, optional) - Forum ID for topic drafts
- `topic_id` (integer, optional) - Topic ID for reply drafts
- `reply_to` (integer, optional) - Reply-to comment ID
- `prefer_unassigned` (boolean, optional) - For topics, fallback to unassigned forum draft if specific forum draft missing

**Response** (HTTP 200):
```json
{
  "draft": {
    "type": "topic",
    "blog_id": 2,
    "forum_id": 5,
    "topic_id": 0,
    "title": "Draft topic title",
    "content": "Draft content in progress"
  }
}
```

**File**: `inc/routes/community/drafts.php`

---

### Save Draft

**Endpoint**: `POST /wp-json/extrachill/v1/community/drafts`

**Purpose**: Save or update a draft topic or reply.

**Permission**: Requires logged-in user

**Parameters**:
- `type` (string, required) - Draft type: `topic` or `reply`
- `forum_id` (integer, optional) - Forum ID for topic drafts
- `topic_id` (integer, optional) - Topic ID for reply drafts
- `reply_to` (integer, optional) - Reply-to comment ID
- `title` (string, optional) - Draft title
- `content` (string, optional) - Draft content (allows HTML)

**Request Example**:
```json
{
  "type": "topic",
  "forum_id": 5,
  "title": "New Discussion Topic",
  "content": "<p>Starting a new discussion...</p>"
}
```

**Response** (HTTP 200):
```json
{
  "saved": true,
  "draft": {
    "type": "topic",
    "blog_id": 2,
    "forum_id": 5,
    "title": "New Discussion Topic",
    "content": "<p>Starting a new discussion...</p>"
  }
}
```

---

### Delete Draft

**Endpoint**: `DELETE /wp-json/extrachill/v1/community/drafts`

**Purpose**: Delete a saved draft.

**Permission**: Requires logged-in user

**Parameters**:
- `type` (string, required) - Draft type: `topic` or `reply`
- `forum_id` (integer, optional) - Forum ID for topic drafts
- `topic_id` (integer, optional) - Topic ID for reply drafts
- `reply_to` (integer, optional) - Reply-to comment ID

**Response** (HTTP 200):
```json
{
  "deleted": true
}
```

## Context Rules

### Topic Drafts
- Requires `forum_id` parameter
- Stored per user, per forum
- `prefer_unassigned` flag allows fallback to forum_id=0 draft

### Reply Drafts
- Requires `topic_id` parameter (must be > 0)
- Optionally targets specific reply via `reply_to`
- Stored per user, per topic

## Error Responses

**Not Logged In** (HTTP 401):
```json
{
  "code": "rest_forbidden",
  "message": "Must be logged in.",
  "data": { "status": 401 }
}
```

**Missing forum_id for Topic** (HTTP 400):
```json
{
  "code": "missing_forum_id",
  "message": "forum_id is required for topic drafts.",
  "data": { "status": 400 }
}
```

**Invalid Context** (HTTP 400):
```json
{
  "code": "invalid_topic_id",
  "message": "topic_id is required for reply drafts.",
  "data": { "status": 400 }
}
```

**Draft Helpers Unavailable** (HTTP 500):
```json
{
  "code": "missing_helpers",
  "message": "Draft utilities not loaded.",
  "data": { "status": 500 }
}
```

## Implementation Details

- Uses `extrachill_api_bbpress_draft_get()` for retrieval
- Uses `extrachill_api_bbpress_draft_upsert()` for save/update
- Uses `extrachill_api_bbpress_draft_delete()` for deletion
- Content handled via `wp_unslash()` to preserve user input
- Draft `blog_id` is always the current blog ID (no cross-blog draft storage)
- Fallback logic for unassigned forum topics when `prefer_unassigned` flag set

## Dependencies

- **extrachill-api**: Draft management utilities (`inc/utils/bbpress-drafts.php`)
- **bbPress**: Forum post type and taxonomy

## Integration

Used by community features to persist draft progress:
- Topic creation forms
- Reply composition modals
- Auto-save functionality
- Draft recovery after accidental navigation
