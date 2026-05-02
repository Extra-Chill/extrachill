# Extra Chill AI Adventure

AI-powered interactive text adventure block for WordPress. Create branching narratives with a game master agent built on the [Agents API](https://github.com/Automattic/agents-api) substrate and WordPress core's AI Client.

```
┌────────────────────────────────────────────────┐
│                                                │
│           ░▒▓ AI  ADVENTURE ▓▒░               │
│                                                │
│  You find yourself in a dimly lit bar on       │
│  South Congress. The jukebox crackles to       │
│  life with a Townes Van Zandt tune.            │
│                                                │
│  > talk to the bartender                       │
│                                                │
│  [SCENE] The bartender slides a cold Lone      │
│  Star across the counter without being asked.  │
│                                                │
│  [DIALOGUE] You look like you've got a story.  │
│  Pull up a stool — night's young.              │
│                                                │
│  > _                                           │
│                                                │
└────────────────────────────────────────────────┘
```

Oregon Trail-inspired terminal aesthetic. Green phosphor on black. Courier New. Dashed borders. The AI narrates in `[SCENE]` tags (amber, italic) and speaks in `[DIALOGUE]` tags (green). The player types actions. The story branches.

## How It Works

Three Gutenberg blocks compose a branching adventure:

```
extrachill/ai-adventure           ← container with title, prompt, persona
  └─ extrachill/ai-adventure-path ← a narrative branch (label + prompt)
       └─ extrachill/ai-adventure-step ← a scene with triggers
            triggers: [
              { phrase: "take the back door", destination: step-2 },
              { phrase: "talk your way in",   destination: step-3 }
            ]
```

The **game-master agent** (declared by this plugin) drives the conversation. It narrates scenes, embodies a companion character, and decides when the player's choices match a story trigger. When it does, it calls the `progress_story` tool to advance the narrative.

## Architecture

```
Player types action
    │
    ▼
view.js → POST /extrachill/v1/ai-adventure
    │
    ▼
Plugin builds game context (state, triggers, history) per request
    │
    ▼
AgentsAPI\AI\AgentConversationLoop::run( $messages, $turn_runner, [ max_turns => 1 ] )
    │
    ▼
Turn runner closure
    ├── Reads agent/SOUL.md from plugin source
    ├── Prepends SOUL.md to a "Current Game Context" block as the system prompt
    ├── Builds wp-ai-client message history from rolling conversationHistory
    ├── Declares progress_story as a wp-ai-client function declaration
    └── Dispatches one turn through wp_ai_client_prompt() (WP 7.0 core)
          │
          ▼
    Provider responds
          ├── Normal turn → [SCENE] + [DIALOGUE] narrative text
          └── Player decided → function_call progress_story
                │
                ▼
          ToolExecutorInterface adapter → extrachill/progress-story ability
                ├── validates trigger_id against step triggers
                └── returns next_step_id
```

**One turn, one provider call.** The AI handles both narration and progression detection in a single conversation turn. No separate analysis pass.

## The Agents API Pattern

The game-master is a declarative agent registered on `wp_agents_api_init`:

```php
wp_register_agent( 'game-master', [
    'label'          => 'Game Master',
    'memory_seeds'   => [ 'SOUL.md' => __DIR__ . '/agent/SOUL.md' ],
    'default_config' => [
        'default_provider' => 'openai',
        'default_model'    => 'gpt-4o-mini',
        'tool_policy'      => [ 'mode' => 'allow', 'tools' => [ 'progress_story' ] ],
    ],
] );
```

Identity (SOUL.md, defaults) ships in the plugin source. There is no DB row for the agent and nothing to configure in an admin UI.

The `progress_story` tool is declared as an Agents API runtime tool:

```php
RuntimeToolDeclaration::normalize( [
    'name'        => 'extrachill/progress-story',
    'description' => '...',
    'parameters'  => [ /* JSON Schema */ ],
    'executor'    => RuntimeToolDeclaration::EXECUTOR_CLIENT,
    'scope'       => RuntimeToolDeclaration::SCOPE_RUN,
] );
```

The tool executor is a `ToolExecutorInterface` adapter that delegates to the `extrachill/progress-story` ability. The ability owns the actual game logic and is callable from CLI, REST, or any other Abilities API consumer.

## State Management

The plugin is **stateless on the server**. There is no transcript table and no chat session.

- `agent/SOUL.md` is read directly from the plugin source on every turn.
- The frontend roundtrips full `conversationHistory`, `progression_history`, and `transition_context` on every request, and the plugin renders all of it into the per-turn system prompt.
- `AgentConversationLoop` runs with `max_turns = 1` and a `NullAgentConversationTranscriptPersister` is the implicit default.
- The `sessionId` parameter is accepted from the client and echoed back unchanged for backward-compatible response shape, but it is not persisted or read.

## Requirements

- WordPress 7.0+ (provides core's AI Client: `wp_ai_client_prompt()`, `\WordPress\AiClient\*`).
- [Agents API](https://github.com/Automattic/agents-api) plugin, network-active (provides `wp_register_agent()`, `AgentConversationLoop`, `RuntimeToolDeclaration`, `ToolExecutorInterface`).
- A registered AI provider (e.g. OpenAI) compatible with `wp_ai_client_prompt()`.
- The `extrachill/progress-story` ability is registered by this plugin via the Abilities API.

The `data-machine` plugin is **not** required.

## Development

```bash
# Install dependencies
npm install

# Development build with watch
npm run start

# Production build
npm run build
```

Blocks are built with `@wordpress/scripts`. Source in `src/blocks/`, output to `build/blocks/`.

## File Structure

```
extrachill-ai-adventure.php     ← plugin entry, REST route, request → runner
agent/
  SOUL.md                       ← Game Master identity, prepended to system prompt
inc/
  abilities/
    progress-story.php          ← Abilities API: trigger validation
  agent/
    register-agent.php          ← wp_register_agent('game-master', ...)
  tools/
    progress-story-tool.php     ← RuntimeToolDeclaration + ToolExecutorInterface adapter
  runtime/
    conversation-runner.php     ← AgentConversationLoop + wp_ai_client_prompt() turn runner
src/blocks/
  ai-adventure/
    index.js                    ← editor block (InnerBlocks container)
    view.js                     ← frontend game engine (React)
    render.php                  ← server-side render (data attributes)
    style.scss                  ← Oregon Trail terminal styles
    editor.scss                 ← editor card structure with CSS counters
    OpeningScreen.js            ← title screen component
    OpeningScreen.scss          ← neon green glow card
  ai-adventure-path/
    index.js                    ← branch block (RichText + InnerBlocks)
  ai-adventure-step/
    index.js                    ← scene block (triggers UI)
```

## License

GPL v2 or later.

---

Built by [Extra Chill](https://extrachill.com) — the Online Music Scene.
