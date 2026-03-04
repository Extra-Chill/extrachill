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
