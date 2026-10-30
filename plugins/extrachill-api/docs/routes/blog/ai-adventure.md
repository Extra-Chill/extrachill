# AI Adventure Endpoint

## Route
`POST /wp-json/extrachill/v1/blog/ai-adventure`

## Purpose
Powers the ExtraChill Blog "AI Adventure" block by proxying structured story prompts to the AI provider defined in the extrachill-ai-client ecosystem.

## Dependencies
- `EXTRACHILL_BLOG_PLUGIN_DIR` must be defined so the route can include `src/blocks/ai-adventure/includes/prompt-builder.php`.
- `ExtraChill_Blog_Prompt_Builder` supplies all prompt templates.
- AI calls are delegated via `apply_filters( 'chubes_ai_request', $payload, 'openai' )`.

## Request Body
All parameters are provided as JSON:

| Field | Type | Required | Notes |
| --- | --- | --- | --- |
| `isIntroduction` | boolean | No | When true, triggers the introduction flow.
| `characterName` | string | No | Sanitized text.
| `adventureTitle` | string | No | Sanitized text.
| `adventurePrompt` | string | No | Detailed story setup.
| `pathPrompt` | string | No | Optional branch direction.
| `stepPrompt` | string | No | Optional step-specific instruction.
| `gameMasterPersona` | string | No | Persona description for the AI narrator.
| `storyProgression` | array | No | Prior narrative segments.
| `playerInput` | string | No | Latest user choice.
| `triggers` | array | No | Trigger definitions with `id` + `destination` used for progression analysis.
| `conversationHistory` | array | No | Rolling chat history.
| `transitionContext` | array | No | Additional context for scene transitions.

## Processing Flow
1. Validates prompt builder availability, returning a `500` error if missing.
2. Sanitizes every field before use.
3. When `isIntroduction` is true:
   - Builds introduction messages via `ExtraChill_Blog_Prompt_Builder::build_introduction_messages()`.
   - Sends payload to the AI filter with `model = gpt-5-nano`.
   - Returns `{ "narrative": "..." }`.
4. Otherwise, it:
   - Builds conversation messages.
   - Calls the AI filter.
   - Optionally runs `analyze_progression()` if triggers exist, which issues a second AI request to decide `nextStepId`.

## Response Structures
### Introduction
```
{ "narrative": "Opening story prose" }
```

### Step Progression
```
{
  "narrative": "Narrative text or empty when nextStepId is set",
  "nextStepId": "trigger destination id or null"
}
```

## Failure Modes
| Code | Status | Description |
| --- | --- | --- |
| `extrachill_blog_missing` | 500 | Blog plugin not available.
| `prompt_builder_missing` / `prompt_builder_unavailable` | 500 | Prompt builder file/class missing.
| `chubes_ai_request_failed` | 500 | Upstream AI provider returned an error.

## Usage Guidelines
- Always send JSON with proper camelCase keys to match the block's front end.
- Preserve prior `storyProgression` and `conversationHistory` arrays client-side; the endpoint expects clients to manage state between turns.
