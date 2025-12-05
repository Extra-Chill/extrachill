# Rapper Name Generator Endpoint

## Route
`POST /wp-json/extrachill/v1/blocks/rapper-name`

## Purpose
Generates stage names for the Rapper Name Generator block by delegating all creative logic to `extrachill_blocks_generate_rapper_name()` inside extrachill-blocks.

## Request Fields
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `input` | string | Yes | Base name; returning an empty string triggers `400 invalid_input`. |
| `gender` | string | No | Optional flavor hint passed directly to the helper. |
| `style` | string | No | Optional style descriptor (boom bap, trap, etc.). |
| `number_of_words` | integer | No | Desired word count for the final name. |

## Processing
1. Validates `input` and sanitizes every field using WordPress helpers (`sanitize_text_field`, `absint`).
2. Confirms the helper function exists; otherwise responds with `500 function_missing` to indicate extrachill-blocks must be active.
3. Calls `extrachill_blocks_generate_rapper_name()` with the sanitized args and returns the generated string.

## Response
```
{ "name": "Lil Midnight Flame" }
```

## Error Responses
| Code | HTTP | Description |
| --- | --- | --- |
| `invalid_input` | 400 | `input` missing or empty. |
| `function_missing` | 500 | Helper unavailable (plugin inactive). |

## Consumer Notes
- Public endpoint; no authentication required.
- Send JSON payloads from the block frontend, then display the returned `name` in the UI.
- All business logic stays in extrachill-blocks, so new naming rules belong there rather than this API layer.
