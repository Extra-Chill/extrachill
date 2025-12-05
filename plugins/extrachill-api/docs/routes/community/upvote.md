# Community Upvote Endpoint

## Route
`POST /wp-json/extrachill/v1/community/upvote`

## Purpose
Records an upvote (or removes it, depending on the helper implementation) for forum topics and replies inside the community plugin. All business rules—duplicate detection, score limits, notifications—live in `extrachill_process_upvote()` so they remain consistent with the legacy AJAX flow.

## Authentication
- Requires a logged-in session. Requests made without cookies (or tokens) receive `401`.

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `post_id` | integer | Yes | WordPress post ID for the topic or reply being upvoted. Must be greater than zero. |
| `type` | string | Yes | Either `topic` or `reply`. Values outside the enum are rejected by the REST schema. |

## Processing Lifecycle
1. REST schema validates the arg types and enforces the `topic`/`reply` enum.
2. The handler fetches the current user ID and delegates to `extrachill_process_upvote( $post_id, $type, $user_id )` in the community plugin.
3. The helper returns an array describing the new score and whether the upvote is currently active.
4. On success the endpoint responds with:
```json
{
  "success": true,
  "message": "Thanks for voting!",
  "new_count": 42,
  "upvoted": true
}
```

## Error Responses
| Code | HTTP | Description |
| --- | --- | --- |
| `function_missing` | 500 | Helper function unavailable (plugin inactive). |
| `upvote_failed` | 400 | Helper rejected the action (rate limit, invalid post, etc.). Message text comes from the helper response. |

## Consumer Guidance
- Send JSON with `Content-Type: application/json`; the route does not parse multipart data.
- Always pair requests with nonce-authenticated fetch calls from the frontend to ensure cookies accompany the request.
- Use the returned `upvoted` boolean to toggle UI states without refetching the entire thread.
