# Chat Message Endpoint

## Route
`POST /wp-json/extrachill/v1/chat/message`

## Purpose
Sends a user’s message to the ExtraChill AI assistant, saves the updated conversation, and returns both the AI response and any tool calls requested by the chat engine.

## Authentication
- Requires a logged-in WordPress session. Anonymous requests fail (`permission_callback` checks `is_user_logged_in()`).

## Request Body
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `message` | string | Yes | Sanitized with `sanitize_textarea_field( wp_unslash( $value ) )`. Must contain non-empty text after trimming. |

## Processing Flow
1. Validates the message body via REST arg schema.
2. Checks extrachill-chat helpers exist: `ec_chat_get_or_create_chat()`, `ec_chat_send_ai_message()`, and `ec_chat_save_conversation()`. Missing helpers trigger `500 function_missing` errors.
3. Retrieves/creates the user’s chat post to store conversation history.
4. Sends the sanitized message to `ec_chat_send_ai_message( $message, $chat_post_id )`, which returns:
   - `content`: AI reply text.
   - `tool_calls`: Optional structured tool invocations.
   - `messages`: Full conversation stack to persist.
5. Saves conversation history via `ec_chat_save_conversation()` when provided.
6. Responds with:
```json
{
  "message": "AI response text",
  "tool_calls": [ ... ],
  "timestamp": "YYYY-MM-DD HH:MM:SS"
}
```

## Error Handling
| Code | Status | Description |
| --- | --- | --- |
| `function_missing` | 500 | extrachill-chat helper not loaded. |
| `chat_history_error` | 500 | Failed to fetch/create the chat post. |
| `ai_error` | 500 | AI provider returned an error. |
| Validation errors | 400 | Empty or missing message field. |

## Client Guidance
- Always include REST nonces/cookies when calling from the WordPress UI.
- Display the returned `tool_calls` output when present; downstream components may need to render tool results inline.
- Use the `timestamp` to show when the assistant responded relative to the site timezone.
- Log or surface `ai_error` messages to help users retry when the upstream AI provider is unavailable.
