# Extra Chill Community – AI agent quickstart

Scope: WordPress theme for community.extrachill.com focused on bbPress forums, user features, and cross‑domain auth. Artist features live in the extrachill-artist-platform plugin; integrate via filters/REST.

## Architecture
- Hybrid theme: `functions.php` orchestrates setup/enqueues; bbPress overrides in `bbpress/`; feature modules in `forum-features/`; auth/integration in `extrachill-integration/`; login flows in `login/`.
- Assets are conditional and versioned with `filemtime()`; bbPress default styles are dequeued and replaced by theme CSS.
- Cross‑domain auth: **Migrating to WordPress multisite** - native authentication replaces custom session tokens. Legacy session token system in `extrachill-integration/` maintained for compatibility.
- PSR‑4 autoload ready (`composer.json` → `Chubes\\Extrachill\\` → `src/`), but most logic is procedural today.

## Key files & patterns
- `functions.php`
  - Theme setup (menus/sidebars), login redirection (custom `/login`), admin-only wp‑admin.
  - Conditional enqueues with cache busting, e.g. `forums-loop.css`, `topics-loop.css`, `replies-loop.css`.
  - Dequeue `bbp-default` styles; localize scripts with nonces (`upvote_nonce`, `quote_nonce`).
- `forum-features/` grouped by domain: `admin/`, `content/`, `social/`, `users/`; loader `forum-features/forum-features.php` (frontend only).
- `extrachill-integration/`: `session-tokens.php`, `seamless-comments.php`, `rest-api-forums-feed.php`.
- `login/`: custom login/register UI and email change verification (`email-change-emails.php` + helpers in `functions.php`).

## Conventions
- Only enqueue assets in context (is_bbpress, page templates, specific pages); always version with `filemtime()`.
- Use WordPress escaping/sanitization and keep existing nonce/action names when extending handlers.
- Do not re-enable bbPress default CSS; extend theme CSS instead.
- Prefer filters (e.g., avatar menu injection) over template edits for integration points.

## Integration with extrachill-artist-platform
- Avatar menu: plugin injects via `ec_avatar_menu_items` filter; each item has `url`, `label`, `priority` (lower = higher in menu).
- Cross-domain access checks: **Legacy REST** under `extrachill/v1/*` with `.extrachill.com` cookie auth (maintained during multisite migration); changes here require coordinating plugin JS (edit icon, session modules).

## Workflows
- Install deps: `composer install` at theme root (no npm here).
- Dev loop: edit PHP/CSS/JS directly; cache busting via `filemtime()` is automatic.
- bbPress UI: add overrides in `bbpress/` and styles in `css/`; keep `bbp-default` dequeued.
- Auth flows: update `extrachill-integration/` + `login/`; test across subdomains.

## Examples to mirror
- Conditional style: enqueue `css/leaderboard.css` only on `page-templates/leaderboard-template.php`.
- Targeted JS: enqueue `forum-features/social/js/upvote.js` except forum ID 1494; localize `{ ajaxurl, nonce, is_user_logged_in, user_id }`.
- bbPress search: set `post_type` to `['post','page','forum','topic','reply']` in `pre_get_posts` during bbPress search.

## Changes: safe vs risky
- Safe: new contextual enqueues; additive filters/actions; new REST endpoints; new user_meta.
- Risky: login redirects/cookie domain; renaming REST routes/nonce names; removing bbPress dequeue.

## Adding PHP classes
- Place under `src/Chubes/Extrachill/*`; run Composer autoload if needed; keep public API minimal and document filters.

If you need deeper pointers (e.g., which hook/file controls a specific UI), ask for the exact flow and we’ll expand this doc.
