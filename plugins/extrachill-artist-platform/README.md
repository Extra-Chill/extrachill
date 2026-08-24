# Extra Chill Artist Platform

## Link Pages runtime handoff

This compatibility build supports a rolling extraction to the standalone
`extrachill-link-pages` plugin without declaring a hard `Requires Plugins`
dependency. Deploy this Artist Platform compatibility build first, then install
and activate the standalone plugin. During that activation request the
standalone plugin must tolerate the already-loaded fallback symbols; on the next
request Artist Platform sees the active configuration and fully defers. Artist
Platform reads WordPress's site and network active plugin configuration for
`extrachill-link-pages/extrachill-link-pages.php`; when configured, it defers the
`artist_link_page` CPT and bundled generic owner/operation APIs to that plugin.
The standalone plugin must expose those APIs by `plugins_loaded` priority 20,
after which Artist Platform registers its artist adapters. Without the
standalone plugin, the bundled runtime and current behavior remain active.

Artist profiles, link pages, and shop management for the Extra Chill network.

## What It Does

Artist Platform gives musicians everything they need to build their presence:

- **Link pages** — Branded link-in-bio pages on extrachill.link
- **Artist profiles** — Rich profiles with socials, roster, and analytics
- **Shop integration** — Sell merch with Stripe Connect payouts
- **Subscriber management** — Collect and manage fan email lists

## How It Works

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│    JOIN     │ ──▶ │   MANAGE    │ ──▶ │   PUBLISH   │
│ extrachill  │     │   artist.   │     │ extrachill  │
│ .link/join  │     │ extrachill  │     │   .link/    │
└─────────────┘     └─────────────┘     └─────────────┘
```

Artists join via **extrachill.link/join**, manage everything on **artist.extrachill.com**, and their link pages go live on **extrachill.link**.

## Features

| Feature | Description |
|---------|-------------|
| **Link Page Editor** | Drag-and-drop links, custom colors, live preview |
| **Analytics Dashboard** | Views, clicks, date filtering, artist switcher |
| **Roster Management** | Invite band members, manage permissions |
| **Shop Manager** | Products, orders, shipping labels, Stripe payouts |
| **Subscriber Export** | Download fan email lists |

## Blocks

All management happens through Gutenberg blocks:

- **Link Page Editor** — Full link page customization with live preview
- **Artist Manager** — Profile, socials, roster, subscribers
- **Artist Analytics** — Chart.js dashboard with date filtering
- **Artist Creator** — Guided onboarding for new artists
- **Shop Manager** — Products, inventory, orders, fulfillment

## Requirements

- WordPress 5.0+
- PHP 7.4+
- Network-activated `extrachill-users` plugin
- Extrachill theme on artist.extrachill.com

## Development

```bash
# Build blocks
npm run build

# Development watch
npm run start

# Package for distribution
./build.sh  # Creates /build/extrachill-artist-platform.zip
```

## Documentation

- [AGENTS.md](AGENTS.md) — Technical reference for contributors
- [docs/](docs/) — Feature documentation
