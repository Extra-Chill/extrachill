# User Mentions

@username mention system with autocomplete search and reply button integration for referencing users in forum content.

## Creating Mentions

### Autocomplete Search
Type `@` followed by username characters in TinyMCE editor to trigger autocomplete dropdown.

### User Selection
Select user from dropdown to insert mention into content. Mention appears as `@username`.

### Search Criteria
Autocomplete searches both username (`user_login`) and profile slug (`user_nicename`) fields.

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
  {
    "id": 123,
    "username": "user_login",
    "slug": "user_nicename",
    "avatar_url": "https://...",
    "profile_url": "https://community.extrachill.com/users/user_nicename"
  }
]
```

## Security

### Permission
For mentions autocomplete (default `context=mentions`), the endpoint requires a logged-in user.

### Input Sanitization
Search terms sanitized with `sanitize_text_field()` before database query.

### Result Filtering
Search limited to existing WordPress users only.

## Reply Button Integration

Reply buttons in forum threads automatically insert @mentions when clicked, scrolling to the reply form and pre-filling the mention for easy responses.

**Implementation**: Reply button handler runs independently of TinyMCE availability, ensuring functionality even when the rich text editor is not loaded.

## Usage Patterns

Users mention others to:
- Direct responses to specific community members
- Acknowledge contributions or reference previous discussions
- Request input from users with relevant expertise
- Notify users of content they may find interesting

Mentions create engagement and facilitate threaded conversations within forum discussions.
