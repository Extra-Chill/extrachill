# Chat History Reset Endpoint

## Route
`DELETE /wp-json/extrachill/v1/chat/history`

## Purpose
Clears the authenticated user’s saved AI chat transcript so future prompts start fresh within the extrachill-chat experience.

## Authentication
- Requires a logged-in WordPress user. Permission callback blocks anonymous requests.

## Processing Flow
1. Confirms extrachill-chat helpers (`ec_chat_get_or_create_chat`, `ec_chat_clear_history`) exist. Returns a `500` error if the chat plugin is inactive.
2. Resolves the per-user chat post via `ec_chat_get_or_create_chat( $user_id )`.
3. Calls `ec_chat_clear_history( $chat_post_id )` to remove all stored conversation entries.

## Response
Successful clears return:
```
{ "message": "Chat history cleared successfully." }
```

Failures bubble up as `WP_Error` responses, including logs for unexpected storage issues.

## Usage Notes
- Invoke this endpoint when a user selects “Reset Chat” in the UI. No request body is required.
- Because the endpoint depends on extrachill-chat, ensure that plugin remains network-activated wherever this API is available.
