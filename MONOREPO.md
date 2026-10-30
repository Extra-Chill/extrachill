# Extra Chill monorepo

The whole Extra Chill WordPress platform in one repository: the network theme, every live `extrachill-*` plugin, and the shared npm packages. Tracking: [#385](https://github.com/Extra-Chill/extrachill-network/issues/385).

## Status: staging

The code has been imported with full history (`git filter-repo --to-subdirectory-filter`; old release tags are kept as `<repo>-vX.Y.Z`). **The original repositories are still the source of truth** and still release and deploy exactly as before. Do not make changes under `plugins/`, `themes/` or `packages/` here until the cutover. A re-import before cutover brings this tree up to date.

During staging, those directories are excluded from the `extrachill-network` plugin's build (`.buildignore`), release scope (`homeboy.json`) and lint (`composer lint:php`). The `extrachill-network` plugin itself stays at the repo root for now.

## Deploy model (after cutover)

This works like wpcom: merging to `main` deploys that commit. Only components whose paths changed are deployed (`homeboy deploy --ref <sha>`). There are no per-component versions and no release step, and rolling back means deploying an older commit. Changed-component detection: [homeboy#16640](https://github.com/Extra-Chill/homeboy/issues/16640).

## Layout and where each component runs

| Component | Path | Active on |
|---|---|---|
| `extrachill-ai-adventure` | `plugins/extrachill-ai-adventure` | extrachill.com |
| `extrachill-analytics` | `plugins/extrachill-analytics` | all sites (network-activated) |
| `extrachill-api` | `plugins/extrachill-api` | all sites (network-activated) |
| `extrachill-artist-platform` | `plugins/extrachill-artist-platform` | artist.extrachill.com |
| `extrachill-blog` | `plugins/extrachill-blog` | extrachill.com |
| `extrachill-cache` | `plugins/extrachill-cache` | all sites (network-activated) |
| `extrachill-cli` | `plugins/extrachill-cli` | all sites (network-activated) |
| `extrachill-community` | `plugins/extrachill-community` | community.extrachill.com |
| `extrachill-contact` | `plugins/extrachill-contact` | extrachill.com |
| `extrachill-content-blocks` | `plugins/extrachill-content-blocks` | community.extrachill.com, extrachill.com |
| `extrachill-docs` | `plugins/extrachill-docs` | docs.extrachill.com |
| `extrachill-events` | `plugins/extrachill-events` | events.extrachill.com |
| `extrachill-link-pages` | `plugins/extrachill-link-pages` | all sites (network-activated) |
| `extrachill-mcp` | `plugins/extrachill-mcp` | auth.extrachill.com |
| `extrachill-network` | repo root (moves to `plugins/extrachill-network` later) | all sites (network-activated) |
| `extrachill-newsletter` | `plugins/extrachill-newsletter` | all sites (network-activated) |
| `extrachill-news-wire` | `plugins/extrachill-news-wire` | wire.extrachill.com |
| `extrachill-search` | `plugins/extrachill-search` | all sites (network-activated) |
| `extrachill-seo` | `plugins/extrachill-seo` | all sites (network-activated) |
| `extrachill-shop` | `plugins/extrachill-shop` | shop.extrachill.com |
| `extrachill-studio` | `plugins/extrachill-studio` | studio.extrachill.com |
| `extrachill-users` | `plugins/extrachill-users` | all sites (network-activated) |
| `extrachill` | `themes/extrachill` | all sites (network theme) |
| `extrachill-tokens` | `packages/tokens` | npm package (build-time) |
| `extrachill-api-client` | `packages/api-client` | npm package (build-time) |
| `extrachill-components` | `packages/components` | npm package (build-time) |

Not here: `extrachill-app` (native app), `extrachill-event-bundles` (private), `blocks-everywhere` (third-party fork), the Data Machine family (its own monorepo later), and `homeboy`, `wp-codebox` and `wp-native-client`.
