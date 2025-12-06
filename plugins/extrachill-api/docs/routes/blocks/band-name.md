# Band Name Generator Endpoint

## Route
`POST /wp-json/extrachill/v1/blocks/band-name`

## Purpose
Generate band name suggestions using AI. This endpoint powers the ExtraChill Blocks "Band Name Generator" block, providing AI-powered creative suggestions for music artists.

## Permission
- **POST**: Public (no authentication required)

## POST Request

```json
{
  "input": "rock music from the 80s",
  "genre": "rock",
  "number_of_words": 2
}
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `input` | string | Yes | Prompt or seed text for name generation. Minimum 1 character. |
| `genre` | string | No | Music genre for context-aware generation (e.g., 'rock', 'hip-hop', 'pop', 'metal'). Helps the AI focus suggestions. |
| `number_of_words` | integer | No | Preferred word count for generated names (e.g., 1, 2, 3). Guides length of suggestions. |

### POST Response
```json
{
  "names": [
    "Thunder Echo",
    "Electric Void",
    "Chrome Rebel",
    "Midnight Static",
    "Iron Wave"
  ]
}
```

The response contains an array of generated band names tailored to the input criteria.

## Error Codes
| Code | Status | Description |
| --- | --- | --- |
| `invalid_input` | 400 | Input is missing or empty |
| `ai_unavailable` | 500 | AI provider is not available or configured |
| `ai_error` | 500 | AI provider returned an error or timed out |

## Implementation Notes
- This endpoint delegates to the extrachill-blocks plugin's AI generation logic
- The AI provider is accessed through the `extrachill-ai-client` plugin's filter system
- Input is sanitized before sending to the AI provider
- Generation is typically fast but may take a few seconds depending on API response time
- Results are diverse and creative, suitable for brainstorming band names

## Related Endpoints
- `POST /blocks/rapper-name` - Generate rapper names instead
- `POST /ai-adventure` - Generate adventure story segments

## Usage Examples

### Basic Band Name Generation
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/band-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "experimental electronic music"
  }'
```

### Generate with Genre and Word Count Preference
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/band-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "heavy metal influences with modern production",
    "genre": "metal",
    "number_of_words": 2
  }'
```

### Generate Rock Band Names
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/band-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "80s rock revival with modern twist",
    "genre": "rock",
    "number_of_words": 2
  }'
```

## Frontend Integration
The ExtraChill Blocks "Band Name Generator" block calls this endpoint when users click a "Generate Names" button, displaying the results in real-time.
