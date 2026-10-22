# Block ability clients

Authenticated edit-profile and user-settings views are script modules requiring
WordPress 7.1's `@wordpress/abilities` and `@wordpress/core-abilities`. The shared
adapter awaits `ready` before executing; core owns input validation and schema
defaults (including omitted input for get-user-settings). React/UI dependencies
remain classic core scripts, explicitly enqueued in render.php. Build and start
use wp-scripts' module pipeline; generated module assets list only module IDs.

## Public blocks

Leaderboard now executes the public ability in PHP and embeds the initial page
as escaped JSON in a data attribute. Contribution heatmap retains its existing
server-inlined initial calendar. Neither needs anonymous catalog discovery.
Pagination, leaderboard fallback on missing initial data, and historical heatmap
years call the existing read-only `/wp-abilities/v1/abilities/{name}/run` GET
routes directly with JSON input. These routes enforce each ability's own
permissions and validation; unlike descriptor/list routes, they do not require
catalog `read` access. No new REST routes or permission relaxations are needed.
Public views do not import core-abilities, so anonymous visitors never download
the catalog. This deliberately uses existing ability run routes rather than
adding an extrachill/v1 facade.

## Production bundle comparison

Same installed dependencies, `npm run build`, uncompressed view.js bytes:

| View | Before | After |
| --- | ---: | ---: |
| edit-profile | 51,333 | 48,145 |
| user-settings | 64,772 | 61,507 |
| leaderboard | 42,241 | 39,123 |
| contribution-heatmap | 10,761 | 7,523 |
| Total | 169,107 | 156,298 |

Savings: 12,809 bytes (7.6%) across these files. Core modules are external and
not included in these numbers: on authenticated pages core-abilities fetches
categories and the **entire ability catalog** (`per_page=-1`, edit context)
once per module initialization. This adds core module transfer, catalog bytes,
and initialization latency before execution; exact cost depends on the site's
registered abilities and cannot be measured from this repository alone.

## Validation

- `npm run build` succeeds (existing Sass deprecation warnings remain).
- `npm run test:js -- --watch=false`: 7 suites, 25 tests pass (including anonymous pagination without discovery).
- `php -d zend.assertions=1 -d assert.exception=1 tests/public-leaderboard-render-smoke.php`
  checks anonymous initial rendering and safe JSON encoding.
- `npx tsc --noEmit` remains blocked by existing missing WordPress declarations
  and block editor type errors.

A deployed WordPress 7.1 integration check should verify anonymous pagination
and heatmap year tabs, authenticated saves, and real catalog transfer size.
Versions and CHANGELOG are unchanged.
