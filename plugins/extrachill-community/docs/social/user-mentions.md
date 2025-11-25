# User Mentions

@username mention system with autocomplete search for referencing users in forum content.

## Creating Mentions

### Autocomplete Search
Type `@` followed by username characters in TinyMCE editor to trigger autocomplete dropdown.

### User Selection
Select user from dropdown to insert mention into content. Mention appears as `@username`.

### Search Criteria
Autocomplete searches both username (`user_login`) and display name (`user_nicename`) fields.

### Result Limit
Autocomplete displays maximum 10 matching users per search.

## Mention Notifications

### Automatic Notifications
Mentioned users receive real-time notification when content containing their mention is published.

### Notification Content
Notifications include mention author, content context, and direct link to mentioned content.

## REST API

### User Search Endpoint
**Provided by extrachill-api plugin** at `/wp-json/extrachill/v1/users/search`

- **Method**: GET
- **Parameters**:
  - `term` (string): Search query (minimum 1 character)
- **Response**: JSON array of matching users with `username` and `slug` fields

### Response Format
```json
[
  {"username": "user_login", "slug": "user_nicename"},
  {"username": "another_user", "slug": "another-user"}
]
```

## Security

### Public Endpoint
User search endpoint publicly accessible to support autocomplete functionality. Endpoint provided by extrachill-api plugin.

### Input Sanitization
Search terms sanitized with `sanitize_text_field()` before database query.

### Result Filtering
Search limited to existing WordPress users only.

## Usage Patterns

Users mention others to:
- Direct responses to specific community members
- Acknowledge contributions or reference previous discussions
- Request input from users with relevant expertise
- Notify users of content they may find interesting

Mentions create engagement and facilitate threaded conversations within forum discussions.
