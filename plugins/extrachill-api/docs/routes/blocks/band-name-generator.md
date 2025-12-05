# Band Name Generator Endpoint

## Route
`POST /wp-json/extrachill/v1/blocks/band-name`

## Purpose
Delegates band name suggestions from the ExtraChill Band Name Generator block to the shared helper inside the extrachill-blocks plugin, ensuring the same creative rules apply across the site.

## Request Fields
| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `input` | string | Yes | User-provided word or name. Sanitized via `sanitize_text_field`. |
| `genre` | string | No | Optional genre cue for the helper. |
| `number_of_words` | integer | No | Desired length hint; sanitized with `absint`. |
| `first_the` | boolean | No | When true, instructs the helper to prepend "The". |
| `and_the` | boolean | No | Adds "and The" phrasing when supported by the generator. |

## Processing Flow
1. REST schema enforces required args and sanitizes all inputs.
2. Returns `400 invalid_input` if `input` is empty.
3. Confirms `extrachill_blocks_generate_band_name()` exists; if not, responds with `500 function_missing`, signaling that extrachill-blocks must be active.
4. Passes all sanitized parameters to the helper and returns the generated name.

## Response Example
```json
{ "name": "The Cosmic Owls" }
```

## Error Conditions
| Code | Status | Description |
| --- | --- | --- |
| `invalid_input` | 400 | Missing or empty `input` field. |
| `function_missing` | 500 | Helper not available (plugin inactive). |

## Usage Guidance
- Endpoint is public; include rate limiting or captchas on the UI if abuse is a concern.
- Because generation logic lives in extrachill-blocks, update that plugin to tweak creative rules without touching this API layer.
