# Extra Chill CLI

WP-CLI command surface for the [Extra Chill](https://extrachill.com) platform. Network-activated WordPress plugin that wraps abilities from feature plugins into a unified `wp extrachill` namespace.

## Architecture

```
extrachill-cli   →  WP-CLI surface (agents + operators)
extrachill-api   →  REST surface (frontend + mobile)
feature plugins  →  abilities (core primitives)
```

extrachill-cli is the **sibling** to [extrachill-api](https://github.com/Extra-Chill/extrachill-api). Both consume the same underlying abilities registered by feature plugins via the WordPress Abilities API. The CLI never implements business logic — it delegates to abilities and query functions exposed by the feature plugins.

## Requirements

- WordPress 6.0+
- WP-CLI 2.0+
- WordPress Multisite (network activated)
- [extrachill-analytics](https://github.com/Extra-Chill/extrachill-analytics) (for analytics commands)

## Installation

```bash
# Clone into your plugins directory
git clone https://github.com/Extra-Chill/extrachill-cli.git wp-content/plugins/extrachill-cli

# Network activate
wp plugin activate extrachill-cli --network --allow-root
```

## Commands

### Platform Health

The platform health command keeps host-wide and site-owned signals in distinct
machine-readable scopes. JSON returns a `network` object and a `sites` array.
CSV returns one row with `scope=network`, followed by `scope=site` rows; the
`network_errors_per_day` cell is populated only on the network row.

```bash
wp extrachill platform health --format=json
wp extrachill platform health --format=csv
```

This scoped contract intentionally replaces the older JSON/CSV shape that
repeated the host-wide error rate on every site record.

### Multisite Owner Context

Commands backed by site-only abilities must use WP-CLI's native `--url` bootstrap
for the site that owns those abilities. The CLI does not load another plugin's
PHP after startup.

```bash
wp --url=https://events.extrachill.com extrachill events market-report
wp --url=https://artist.extrachill.com extrachill artists count
```

Running either command group against another network site exits with the exact
owner URL. If the owner plugin is inactive or incomplete on that site, the
command exits with an owner-plugin capability error.

### Platform Experiments

Inspect registered definitions and orphaned lifecycle records, transition
registered experiments, and request Analytics-owned experiment summaries:

```bash
wp --allow-root --path=/var/www/extrachill.com extrachill experiments list
wp --allow-root --path=/var/www/extrachill.com extrachill experiments get geo-bridge-holdout
wp --allow-root --path=/var/www/extrachill.com extrachill experiments state geo-bridge-holdout active
wp --allow-root --path=/var/www/extrachill.com extrachill experiments report geo-bridge-holdout --outcome-event-types=bridge_click
```

Network authorizes these private administrative Abilities for trusted local
WP-CLI execution. Use the standard local invocation above without `--user`.
Web and REST contexts still require Network's administrative capability.
Orphaned rows are inspectable through `list` and `get`, but cannot be
transitioned or reported because they have no registered code definition.

### Tools — QR Code

Generate print-ready QR code PNG files through the existing admin-tools ability primitive.

```bash
# Generate a QR code in current directory
wp extrachill tools qr generate https://example.com

# Write to a specific output path
wp extrachill tools qr generate https://example.com --output=/tmp/example-qr.png
```

### Analytics — 404 Errors

Analyze, categorize, and manage 404 errors tracked by extrachill-analytics.

```bash
# Summary with daily chart
wp extrachill analytics 404 summary

# Categorized breakdown
wp extrachill analytics 404 patterns

# Top URLs by hit count
wp extrachill analytics 404 top --days=30 --min-hits=5

# Drill into a category (cross-references posts for redirect opportunities)
wp extrachill analytics 404 drill legacy-html
wp extrachill analytics 404 drill content --limit=50

# Recent events
wp extrachill analytics 404 list --days=1

# Purge old data
wp extrachill analytics 404 purge --days=30 --dry-run
```

### Pattern Categories

The 404 analyzer categorizes URLs into 15 buckets:

| Category | Description | Actionable |
|---|---|---|
| `legacy-html` | Old Blogger-era `.html` permalinks | Yes — redirect to current slug |
| `content` | Slugs with no matching published post | Yes — redirect or investigate |
| `date-prefix` | `/YYYY/MM/slug` without `.html` | Yes — redirect to current slug |
| `missing-upload` | Missing images/media files | Yes — regenerate or clean up |
| `community-thread` | `/t/` URLs (bbPress threads) | Yes — cross-site routing |
| `events` | `/events/` URLs | Yes — cross-site routing |
| `festival` | `/festival` URLs | Yes — redirect or create page |
| `ad-txt` | `/ads.txt`, `/app-ads.txt`, etc. | Yes — create static files |
| `old-sitemap` | Legacy sitemap URLs | Yes — redirect to current sitemap |
| `join-page` | `/join` page | Yes — create or redirect |
| `bot-probe` | `/login`, `/admin`, `/cgi-bin` | No — attack noise |
| `php-probe` | `.php` file probes | No — attack noise |
| `plugin-probe` | `/wp-content/plugins/` probes | No — attack noise |
| `wp-includes-probe` | `/wp-includes/` probes | No — attack noise |
| `author-enum` | `/?author=N` enumeration | No — attack noise |

### Analytics — Reads (no acting user)

The aggregate analytics reads are network/site queries that take **no acting-user
context**:

```bash
wp extrachill analytics summary
wp extrachill analytics search-gaps
wp extrachill analytics retention
wp extrachill analytics errors
```

Do **not** pass the global `--user` flag to these commands — it is unused. On
installs where a `user_login` collides with a numeric user ID (e.g. a login of
`"1"` alongside user ID `1`), passing `--user=1` makes WP-CLI print a harmless
but noisy `Ambiguous user match detected` warning before the output, which
clutters scripted captures. Omit `--user` entirely.

### Analytics — Revenue

Revenue commands are thin adapters over the Data Machine Business and Extra
Chill Analytics abilities. Fetches replace the deterministic snapshot for the
source site and period, so repeating a monthly fetch is safe.

Use `--mode=additive --snapshot=<label>` only for an intentional parallel
snapshot; the ingestion ability validates that mode and snapshot. The default
`replace` mode owns deterministic snapshot identity in analytics.

The legacy `revenue import` and `revenue batches` commands are intentionally
retired. They bypassed the ability-owned ingestion identity and persistence
contract; use `revenue fetch` for supported Mediavine ingestion.

```bash
# Fetch a month directly from Mediavine and replace that period's snapshot.
wp extrachill analytics revenue fetch --start=2026-05-01 --end=2026-05-31 --period=2026-05

# Inspect resolved pages without direct database access.
wp extrachill analytics revenue pages --period=2026-05 --min-views=100 --sort-by=derived_rpm

# Inspect unresolved report routes separately.
wp extrachill analytics revenue pages --period=2026-05 --cohort=unresolved --format=csv

# Run store-integrity checks. Warnings remain successful; failures exit nonzero.
wp extrachill analytics revenue diagnose --format=json
```

### Output Formats

All list/query commands support `--format=table|json|csv`:

```bash
# Export top 404s as CSV
wp extrachill analytics 404 top --format=csv > /tmp/top-404s.csv

# JSON for piping to other tools
wp extrachill analytics 404 patterns --format=json
```

## Adding Commands

1. Create a command class in `inc/Commands/<Domain>/<Command>.php`
2. Namespace: `ExtraChill\CLI\Commands\<Domain>`
3. Register in `inc/bootstrap.php`
4. Delegate to abilities or query functions from the feature plugin
5. Always check that the required plugin is active

## Planned Command Domains

- `wp extrachill seo` — redirects, meta, audits
- `wp extrachill newsletter` — subscribers, campaigns
- `wp extrachill community` — forums, users
- `wp extrachill blog` — posts, categories

## License

GPL-2.0-or-later
