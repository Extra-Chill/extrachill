# Extra Chill CLI

WP-CLI command surface for the Extra Chill platform. Network-activated plugin that wraps abilities from feature plugins into a unified `wp extrachill` namespace.

## Architecture

```
extrachill-cli   →  WP-CLI surface (agents + operators)
extrachill-api   →  REST surface (frontend + mobile)
feature plugins  →  abilities (core primitives)
```

The CLI plugin is the **sibling** to extrachill-api. Both consume the same underlying abilities registered by feature plugins via the WordPress Abilities API. The CLI never implements business logic — it delegates to abilities or queries functions exposed by the feature plugins.

## Namespace

All commands live under `wp extrachill <domain> <action>`:

```bash
wp extrachill analytics 404 list
wp extrachill analytics 404 top
wp extrachill analytics 404 patterns
wp extrachill analytics 404 drill <category>
wp extrachill analytics 404 summary
wp extrachill analytics 404 purge
```

## Directory Structure

```
extrachill-cli/
├── extrachill-cli.php          # Plugin bootstrap (WP-CLI only)
├── inc/
│   ├── bootstrap.php           # Command registration
│   └── Commands/
│       └── Analytics/
│           └── FourOhFourCommand.php
```

## Adding New Commands

1. Create a command class in `inc/Commands/<Domain>/<Command>.php`
2. Follow PSR-4: namespace `ExtraChill\CLI\Commands\<Domain>`
3. Register in `inc/bootstrap.php` via `WP_CLI::add_command()`
4. Command should delegate to abilities or query functions from the feature plugin
5. Always check that the required plugin is active via `ensure_*()` helper

## Conventions

- Commands use WP_CLI table formatting (`Utils\format_items`)
- Support `--format=table|json|csv` on all list/query commands
- Support `--days` for time-scoped queries
- Support `--dry-run` on destructive commands
- Feature-check the required plugin before running

## Dependencies

- WordPress 6.0+
- WP-CLI 2.0+
- extrachill-analytics (for 404 commands)
- Network: true (multisite required)
