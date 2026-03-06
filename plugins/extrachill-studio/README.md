# Extra Chill Studio

Internal studio workspace for the Extra Chill team.

This plugin is the start of a team-only workspace for internal publishing tools, AI-assisted workflows, and lightweight content operations inside WordPress.

## What It Does Right Now

`extrachill-studio` currently ships a single Gutenberg block:

- **`extrachill/studio`** — team-gated Studio workspace block

The block handles three states:

1. **Logged out** → renders the existing `extrachill/login-register` block
2. **Logged in, not team** → renders a team-only access message
3. **Logged in, team member** → renders the Studio app shell

## First Real Feature

Studio 0.1.0 includes one real utility:

- **QR Code Generator** — paste a URL, generate a print-ready QR code, preview it, and download the PNG

This uses the existing Extra Chill backend rather than inventing a new feature surface:

- REST endpoint: `POST /wp-json/extrachill/v1/tools/qr-code`
- Ability: `extrachill/generate-qr-code`

## Current Shape

```text
Studio block
   ↓
login + team-member gate
   ↓
tabbed Studio UI
   ├─ Overview
   ├─ QR Codes
   └─ Publishing
```

The tabs use the existing **shared tabs** UI pattern from the Extra Chill theme.

## Architecture Notes

- **Plugin type:** WordPress plugin
- **Primary UI:** single headless React-powered Gutenberg block
- **Permissions:** uses `ec_is_team_member()` from `extrachill-users`
- **Logged-out state:** uses the existing `extrachill/login-register` block
- **Tabs UI:** uses theme-owned shared tabs assets from `extrachill`
- **Frontend data access:** currently uses `@wordpress/api-fetch` for the QR tool

## What This Plugin Is Not Yet

This is intentionally an early scaffold.

It does **not** yet provide:

- caption generation
- media upload workflows
- social publishing flows
- Data Machine-powered jobs
- team-specific assistants

Those are future directions, not current functionality.

## Requirements

- WordPress 6.9+
- PHP 7.4+
- `extrachill-users`
- Extra Chill theme (for shared tabs assets and design system alignment)

## Development

```bash
# Install dependencies
npm install

# Build block assets
npm run build
```

Build output is written to:

- `build/studio/`

Source files live in:

- `blocks/studio/`

## Repository Structure

```text
extrachill-studio/
├── blocks/studio/
│   ├── src/
│   ├── block.json
│   ├── render.php
│   └── style.css
├── build/studio/
├── docs/CHANGELOG.md
├── extrachill-studio.php
├── inc/assets.php
└── package.json
```

## Roadmap Direction

The long-term vision is a team workspace for tools like:

- QR generation
- caption drafting
- publishing workflows
- AI-assisted team operations
- eventually team-member-specific assistants

For now, the goal is simple:

> **Start with real, useful internal tools and iterate from there.**

## Related Repos

- `extrachill-users` — auth and team-member primitives
- `extrachill-api` — REST endpoints used by Studio
- `extrachill-admin-tools` — existing QR generation backend ability
- `extrachill-api-client` — future reusable typed client layer for Studio and other apps

## License

GPL v2 or later
