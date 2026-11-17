# Extra Chill AI Adventure abilities

Extra Chill AI Adventure registers one ability, in the `extrachill-ai-adventure` category (also registered by this plugin): the deterministic story-progression step used by the game-master agent. No AI runs inside the ability. The agent decides when to call it, and the ability only checks the chosen trigger.

### `extrachill/progress-story`

- **Purpose:** Checks a trigger ID against the current step's available triggers and returns the destination step when it matches.
- **Input:**
  - `trigger_id` (string, required): the trigger the game master believes the player activated.
  - `triggers` (array of `{ id: string, destination: string }`, required): the triggers available at the current step.
- **Output:**
  - On a match: `{ progressed: true, next_step_id: <destination>, trigger_id }`.
  - On no match: `{ progressed: false, next_step_id: "", trigger_id }`.
  - Errors: `missing_trigger_id`, `no_triggers`, or `no_destination` (the matched trigger has an empty destination).
- **Permission:** `__return_true`, so it is public. The ability is a pure function over its input and reads no stored data.
- **Exposure:** `show_in_rest` not set (defaults to false). Annotations: not readonly, idempotent, not destructive. Called by the Agents API runtime tool `extrachill/progress-story` (`inc/tools/progress-story-tool.php`), whose executor runs the ability through `wp_get_ability()` during block turns (`inc/runtime/conversation-runner.php`). No `extrachill-api` route or `wp extrachill` command found. See the [plugin README](../README.md) for the agent and tool architecture.
