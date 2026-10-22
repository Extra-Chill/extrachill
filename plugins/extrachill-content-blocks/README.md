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
| **Photo Contest** | User-submitted photo contests: email-gated entries, public voting, per-entry share links, event ticket CTA |
| **Rapper Name Generator** | Generates rapper stage names based on user input |
| **Band Name Generator** | Generates band names by genre with customizable options |

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

Build output goes to `/build/blocks/`. The npm `prepare` lifecycle builds these
assets during `npm install` / `npm ci`, including dependency hydration in clean
Homeboy test workspaces. Generated assets remain untracked.

### Tests

```bash
npm ci
npm run test:php
npm run test:js
npm run typecheck
```

The PHP suite checks generated block metadata and dependency manifests, so assets
must be built before invoking `homeboy review test extrachill-content-blocks`
directly. After changing block sources, run `npm run build` again.

### Photo contest scheduling

The Photo Contest inspector supports optional entry and voting open times beside
the close times. Dates use the site's timezone. Empty open times preserve immediate
entry submission and voting for existing contests. Before entries open, visitors
see “Contest opens” with the scheduled date, event details, and ticket link; voting
controls stay hidden until voting opens. The server enforces both windows even
when a cached page or direct request is used.

## Activation model

This plugin is intended to be activated on any site that should expose these content blocks in the editor.

Initial rollout target:

- **extrachill.com**

Future consumers:

- **studio.extrachill.com**
- **community.extrachill.com**
