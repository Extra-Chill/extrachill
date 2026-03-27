# Extra Chill Content Blocks

Reusable Gutenberg blocks for content creation across the Extra Chill platform.

## Purpose

This plugin owns the shared `extrachill/*` content blocks so they can be used on any site that activates the plugin, starting with the main blog and extending to Studio and Community.

It is the content-block layer for:

- `extrachill-blog`
- `extrachill-studio`
- `extrachill-community`

## Blocks

| Block | Description |
|-------|-------------|
| **Trivia** | Interactive multiple-choice trivia questions with scoring |
| **Image Voting** | Image-based polls with email capture and newsletter integration |
| **Rapper Name Generator** | Generates rapper stage names based on user input |
| **Band Name Generator** | Generates band names by genre with customizable options |
| **AI Adventure** | AI-powered text adventure games with branching narratives |
| **AI Adventure Path** | Story path/branch container (child of AI Adventure) |
| **AI Adventure Step** | Individual story step with triggers (child of AI Adventure Path) |

## Namespace

All blocks keep the portable `extrachill/*` namespace for saved-content compatibility and cross-site reuse.

## Dependencies

- **extrachill-api**: REST API endpoints for block operations
- **extrachill-newsletter**: Newsletter subscription for Image Voting block

Some block behaviors may also depend on other Extra Chill platform plugins being active in the target environment.

## Build System

Uses `@wordpress/scripts` for block compilation:

```bash
npm install
npm run build
npm run start
```

Build output goes to `/build/blocks/`.

## Activation model

This plugin is intended to be activated on any site that should expose these content blocks in the editor.

Initial rollout target:

- **extrachill.com**

Future consumers:

- **studio.extrachill.com**
- **community.extrachill.com**
