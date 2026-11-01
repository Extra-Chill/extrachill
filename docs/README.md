# Documentation map

Start with the [architecture overview](architecture.md) for cross-component relationships, or the component references below. The [monorepo guide](../MONOREPO.md) owns the activation map, CI, deploy and release model.

## Where documentation belongs

- `plugins/<p>/docs/user/` holds published end-user help. Follow the canonical [writing rules](../plugins/extrachill-docs/runner-configs/writing-rules.md): plain language, user goals, no implementation details.
- `plugins/<p>/docs/` outside `user/` holds developer reference that stays in the repository: contracts, hooks, integrations and operational notes. Keep domain-specific knowledge with its owning plugin.
- `themes/extrachill/docs/` holds theme developer reference.
- Each component's `docs/CHANGELOG.md` is release-owned, configured by its Homeboy `changelog_target`. Leave changelogs and version strings to the release process.
- Root `docs/` holds cross-component architecture and site-level infrastructure documentation, including [nginx and Cloudflare configuration](nginx/README.md).

Do not move developer docs between components just to make the layout uniform. When adding a component docs directory, add it to the index below. Check coverage with `node tools/check-docs-index.mjs` (also run by the Site infrastructure CI job).

## How help reaches docs.extrachill.com

The [platform map](../plugins/extrachill-docs/runner-configs/platform-map.yml) defines each published platform's source plugin, `docs_subpath`, parent slug, audience and optional Docs Agent configuration. `docs/user/` is the default; the existing Connect Your AI help uses `plugins/extrachill-docs/ec_docs/mcp/`. Studio help is configured as private. A docs directory alone does not opt a component into publishing.

The manually dispatched [Docs Agent workflow](../.github/workflows/docs-agent.yml) uses that map to propose changes under the configured component's `docs/user/` path, following the writing rules and voice checks. Only entries with runner configuration participate; this workflow writes help, not developer reference.

After reviewed changes merge, the normal component deploy ships the Markdown files. On the docs site, [`extrachill-docs/publish-plugin-docs`](../plugins/extrachill-docs/inc/abilities/publish-plugin-docs.php) reads the installed mapped files (direct `*.md` children of `docs_subpath`, not recursively), then calls `extrachill-docs/upsert-doc-page` to create or update pages. The hourly scheduled check fingerprints installed content and publishes changed sources; it does not fetch from GitHub. Publishing is additive: missing source files are reported as orphans, not automatically deleted. Keep filenames stable to update existing pages in place. Developer references, changelogs and root architecture docs are not published by this pipeline.

## Component index

Links use an existing docs index or overview where available; otherwise they open the docs directory listing. Some components currently contain only a release changelog; their README is the developer entry point until reference docs are added.

### Plugins

| Component documentation | Purpose |
|---|---|
| [extrachill-ai-adventure](../plugins/extrachill-ai-adventure/docs/) | AI adventure experience on the main site; currently changelog only. |
| [extrachill-analytics](../plugins/extrachill-analytics/docs/) | Network analytics, experiments, privacy and measurement contracts. |
| [extrachill-api](../plugins/extrachill-api/docs/) | Central `extrachill/v1` REST route and API infrastructure reference. |
| [extrachill-artist-platform](../plugins/extrachill-artist-platform/docs/overview.md) | Artist profiles, rosters, artist access and link-page integrations. |
| [extrachill-blog](../plugins/extrachill-blog/docs/) | Editorial site behavior, homepage and artist dispatch rollout. |
| [extrachill-booking](../plugins/extrachill-booking/docs/) | Booking component on the events site; currently changelog only. |
| [extrachill-cache](../plugins/extrachill-cache/docs/) | Network caching infrastructure; currently changelog only. |
| [extrachill-cli](../plugins/extrachill-cli/docs/) | Unified `wp extrachill` command surface; currently changelog only. |
| [extrachill-community](../plugins/extrachill-community/docs/index.md) | Community forums, profiles, social features and user help. |
| [extrachill-contact](../plugins/extrachill-contact/docs/) | Main-site contact functionality; currently changelog only. |
| [extrachill-content-blocks](../plugins/extrachill-content-blocks/docs/) | Shared content blocks and photo-contest operations. |
| [extrachill-docs](../plugins/extrachill-docs/docs/) | Documentation hub and publishing component; currently changelog only here, with publishing sources linked above. |
| [extrachill-events](../plugins/extrachill-events/docs/overview.md) | Calendar, event submissions, venues, booking and settlement contracts. |
| [extrachill-link-pages](../plugins/extrachill-link-pages/docs/) | Link-page functionality and verification notes. |
| [extrachill-mcp](../plugins/extrachill-mcp/docs/) | Authenticated MCP access to platform abilities; currently changelog only. |
| [extrachill-network](../plugins/extrachill-network/docs/) | Network configuration, site identity, cross-site routing and service assertions. |
| [extrachill-news-wire](../plugins/extrachill-news-wire/docs/overview.md) | News wire ingestion and source provenance. |
| [extrachill-newsletter](../plugins/extrachill-newsletter/docs/) | Newsletter API and integrations. |
| [extrachill-search](../plugins/extrachill-search/docs/) | Cross-site search; currently changelog only. |
| [extrachill-seo](../plugins/extrachill-seo/docs/) | Network SEO functionality; currently changelog only. |
| [extrachill-shop](../plugins/extrachill-shop/docs/) | Shop and ecommerce integration. |
| [extrachill-studio](../plugins/extrachill-studio/docs/) | Internal editorial workspace and private user help. |
| [extrachill-users](../plugins/extrachill-users/docs/README.md) | Shared identity, authentication, profiles, onboarding and notifications. |

### Theme

| Component documentation | Purpose |
|---|---|
| [extrachill](../themes/extrachill/docs/README.md) | Shared theme routing, hooks, assets, design system and plugin integration contracts. |
