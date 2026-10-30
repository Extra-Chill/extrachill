# Extra Chill monorepo

The whole Extra Chill WordPress platform in one repository: the network theme, every live `extrachill-*` plugin, and the shared npm packages. Tracking: [#385](https://github.com/Extra-Chill/extrachill-network/issues/385).

## Status: staging

The code has been imported with full history (`git filter-repo --to-subdirectory-filter`; old release tags are kept as `<repo>-vX.Y.Z`). **The original repositories are still the source of truth** and still release and deploy exactly as before. Do not make changes under `plugins/`, `themes/` or `packages/` here until the cutover. A re-import before cutover brings this tree up to date.

Every component, including `extrachill-network`, lives in its own directory with its own `homeboy.json`. The repo root holds only site-level infrastructure:

| Path | What it is |
|---|---|
| `deploy/homeboy/projects/extrachill-monorepo` | Deploy targets for every monorepo member (`deploy-monorepo.yml`) |
| `deploy/homeboy/projects/extrachill-site` + `components/` | Release-polled components that are *not* in this repo (Data Machine family, `wp-codebox`, …; `deploy.yml`) |
| `deploy/tests/` | Deploy, nginx and sunrise contract tests |
| `deploy/nginx`, `deploy/sunrise.php`, `docs/nginx` | Server artifacts |
| `rigs/extrachill-network` | The 11-site network rig and user journeys; boots members from this checkout |

## CI

`homeboy.yml` runs each changed component's own lint and tests (homeboy-action `components: changed`), reported as one `homeboy / Components` check, plus a `Site infrastructure` job for `deploy/` and the rig. `journeys.yml` boots the network from this checkout on any change under `plugins/`, `themes/` or `packages/`.

## Deploy model (after cutover)

This works like wpcom: merging to `main` deploys that commit. Only components whose paths changed are deployed (`homeboy deploy --ref <sha>`). A merge does not cut a release, and rolling back means deploying an older commit. `release.yml` is a manual, per-component dispatch. Changed-component detection: [homeboy#16640](https://github.com/Extra-Chill/homeboy/issues/16640).

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
| `extrachill-network` | `plugins/extrachill-network` | all sites (network-activated) |
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
