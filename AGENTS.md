# Extra Chill Theme

WordPress theme serving the Extra Chill Platform multisite network across 9 active sites.

This theme is part of the Extra Chill Platform, a WordPress multisite network serving music communities across 9 active sites.

## Development Guidelines
1. Build/package with `./build.sh`; it installs prod deps, zips to `build/extrachill.zip`, then restores dev deps.
2. Install deps via `composer install` (dev) or `composer install --no-dev` for prod parity.
3. Primary lint/test command: `composer lint:php`; fix via `composer lint:fix`.
4. Run a single-file sniff with `vendor/bin/phpcs --standard=WordPress path/to/file.php`.
5. Repo is PHP/CSS/JS only—no bundlers, transpilers, or external build tooling.
6. Follow `.github/copilot-instructions.md`: modular `inc/` includes, wire via `functions.php`, and conditionally enqueue assets.
7. Load `assets/css/root.css` first; every new stylesheet depends on the `extrachill-root` handle.
8. Define CSS variables only in `assets/css/root.css`; downstream files must consume those tokens.
9. Ban inline CSS/JS, `!important`, and external font/CDN usage; rely on local assets under `assets/`.
10. Prefer vanilla JS over jQuery and WordPress REST API over admin-ajax; backend remains single source of truth.
11. Keep each file single-responsibility and expose extensibility via `extrachill_*` hooks/filters.
12. Enforce strict escaping (`esc_html`, `esc_attr`, `esc_url`) and sanitize inputs with `wp_unslash()` plus type-specific sanitizers.
13. Guard WooCommerce, bbPress, or plugin-specific helpers with `function_exists()`/`class_exists()` checks before use.
14. Asset enqueues must be context-aware (`is_front_page`, `is_singular`, etc.) and versioned with `filemtime()`.
15. Maintain hook-based navigation/footer architecture; never revive WordPress admin menus or legacy menu APIs.
16. Follow naming conventions: prefix globals/functions with `extrachill_`, use snake_case for PHP and kebab-case for CSS classes.
17. Handle errors by bailing early with guarded conditionals; avoid silent failures or placeholder fallbacks.
18. Composer scripts are the single testing authority; document any new commands before adding them.
19. Update `docs/CHANGELOG.md` for behavior changes but never bump the theme version without explicit instruction.
20. Honor repo-wide bans: no AI model tweaks, no dual data contracts, no inline comments about removed code.
