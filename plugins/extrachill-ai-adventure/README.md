# Extra Chill AI Adventure

AI-powered interactive text adventure block for WordPress. Create branching narratives with an AI game master, powered by [Data Machine](https://github.com/Extra-Chill/data-machine).

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

The **Game Master agent** (Data Machine) drives the conversation. It narrates scenes, embodies a companion character, and decides when the player's choices match a story trigger. When it does, it calls the `progress_story` tool to advance the narrative.

## Architecture

```
Player types action
    │
    ▼
view.js → POST /extrachill/v1/ai-adventure
    │
    ▼
Plugin builds client_context (game state, triggers, history)
    │
    ▼
Data Machine ChatOrchestrator
    ├── game-master agent (ID 10)
    │     ├── SOUL.md — persona, [SCENE]/[DIALOGUE] format rules
    │     └── tool_policy: allow [progress_story]
    │
    ├── DM session — full conversation memory
    │
    └── AI responds:
          ├── Normal turn → [SCENE] + [DIALOGUE] narrative
          └── Player decided → calls progress_story tool
                ├── tool → extrachill/progress-story ability
                ├── validates trigger_id against step triggers
                └── returns next_step_id
```

**One turn, one DM call.** The AI handles both narration and progression detection in a single conversation turn. No separate analysis pass.

## The Abilities API Pattern

Story progression is a WordPress Abilities API primitive:

```php
// The ability — pure game logic, no AI
wp_register_ability( 'extrachill/progress-story', [
    'execute_callback' => 'extrachill_ai_adventure_execute_progress_story',
    'category'         => 'extrachill-ai-adventure',
]);

// The tool — chat interface for the game-master agent
class ExtraChill_AI_Adventure_ProgressStory extends BaseTool {
    // AI calls this when it believes the player made a choice
    // Tool calls the ability, ability validates the trigger
}
```

The ability is the reusable primitive. CLI can call it. REST can call it. The tool is just the agent-facing wrapper. Same pattern as every other tool in the Extra Chill ecosystem.

## Tool Scoping

The `progress_story` tool is scoped to the game-master agent via Data Machine's per-agent tool policy:

| Agent | Policy | Effect |
|-------|--------|--------|
| game-master | `allow: [progress_story]` | Only sees game tools |
| roadie | `deny: [progress_story]` | Never sees game tools |
| others | no policy | Game tool doesn't appear |

The game master's entire existence is the game. It has no other tools, no other purpose.

## Design

Oregon Trail meets modern terminal aesthetic:

- **Background:** `#000` black
- **Terminal text:** `#00ff00` classic green phosphor
- **Player input:** `#00aaff` blue with `> ` prefix
- **[SCENE] narration:** `#FFC300` amber, italic
- **[DIALOGUE] speech:** `#00ff00` green with `> ` prefix
- **Opening screen:** `#39ff14` neon green glow, `#111` card, box-shadow glow
- **Font:** Courier New (gameplay), IBM Plex Mono (opening screen)
- **Input:** green border, green glow on focus, invert on hover

The editor uses CSS counters to auto-number paths and steps with the same green-on-black card structure.

## Requirements

- WordPress 6.4+
- [Data Machine](https://github.com/Extra-Chill/data-machine) plugin (provides ChatOrchestrator, agent system, Abilities API integration)
- A configured `game-master` agent in Data Machine with an AI provider

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
extrachill-ai-adventure.php     ← plugin entry, REST route, DM integration
inc/
  abilities/
    progress-story.php          ← Abilities API: trigger validation
  tools/
    class-progress-story.php    ← BaseTool: agent-facing wrapper
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
