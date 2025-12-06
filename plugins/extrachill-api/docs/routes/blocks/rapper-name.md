# Rapper Name Generator Endpoint

## Route
`POST /wp-json/extrachill/v1/blocks/rapper-name`

## Purpose
Generate rapper name suggestions using AI. This endpoint powers the ExtraChill Blocks "Rapper Name Generator" block, providing AI-powered creative suggestions for hip-hop and rap artists.

## Permission
- **POST**: Public (no authentication required)

## POST Request

```json
{
  "input": "lyrics about struggle and success",
  "gender": "neutral",
  "style": "west coast"
}
```

### Request Parameters
| Parameter | Type | Required | Notes |
| --- | --- | --- | --- |
| `input` | string | Yes | Prompt or seed text for name generation. Minimum 1 character. |
| `gender` | string | No | Gender for name style (e.g., 'male', 'female', 'neutral'). Influences name suggestions. |
| `style` | string | No | Rap style preference (e.g., 'west coast', 'east coast', 'trap', 'conscious', 'drill'). Guides thematic focus. |

### POST Response
```json
{
  "names": [
    "King Cipher",
    "Lyric Storm",
    "Echo Phantom",
    "Rhythm Shadow",
    "Soul Prophet"
  ]
}
```

The response contains an array of generated rapper names tailored to the input criteria.

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
- Results are diverse and culturally aware, suitable for brainstorming rapper stage names
- The endpoint respects gender and style preferences to generate contextually appropriate names

## Related Endpoints
- `POST /blocks/band-name` - Generate band names instead
- `POST /ai-adventure` - Generate adventure story segments

## Usage Examples

### Basic Rapper Name Generation
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/rapper-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "street stories and social commentary"
  }'
```

### Generate with Gender and Style Preferences
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/rapper-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "fast flow with conscious lyrics",
    "gender": "neutral",
    "style": "east coast"
  }'
```

### Generate Trap-Style Rapper Names
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/rapper-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "hi-hat heavy beats with dark production",
    "style": "trap"
  }'
```

### Generate Conscious Hip-Hop Names
```bash
curl -X POST "http://site.local/wp-json/extrachill/v1/blocks/rapper-name" \
  -H "Content-Type: application/json" \
  -d '{
    "input": "political activism and introspective lyrics",
    "style": "conscious"
  }'
```

## Frontend Integration
The ExtraChill Blocks "Rapper Name Generator" block calls this endpoint when users click a "Generate Names" button, displaying the results in real-time.
