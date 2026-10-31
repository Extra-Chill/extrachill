# Extra Chill Artist Platform

## Link Pages runtime dependency

Artist Platform requires the `extrachill-link-pages` plugin (`Requires Plugins:
extrachill-link-pages`). That plugin is the only provider of the generic Link
Pages runtime: the Link Page post type, owner references, operations, public
routing, head/SEO output, and link expiration. Artist Platform carries no copy
of it. At `plugins_loaded` priority 20 Artist Platform validates the runtime
contract (`inc/link-pages/runtime-handoff.php`) and registers its artist
adapters into it. If the runtime is missing or incompatible, the adapters are
skipped and an admin notice explains why.

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
```

## Documentation

- [docs/](docs/) — Feature documentation
