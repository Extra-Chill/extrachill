# Extra Chill Theme — Architecture & Conventions

WordPress theme serving the Extra Chill Platform multisite network. This
document is **load-bearing convention** only — hook names, primitive
classes, integration surface. Generic CSS / WordPress / security
tutorials are intentionally absent; assume the reader knows the
underlying tech and grep when needed.

## Multisite Map

| Blog ID | Domain | Role |
|---|---|---|
| 1 | `extrachill.com` | Main editorial site (drafts, articles) |
| 2 | `community.extrachill.com` | Community forums (bbPress) |
| 3 | `shop.extrachill.com` | E-commerce (WooCommerce) |
| 4 | `artist.extrachill.com` | Artist platform |
| 7 | `events.extrachill.com` | Events calendar hub |
| 8 | `stream.extrachill.com` | Live streaming |
| 9 | `newsletter.extrachill.com` | Newsletter subscription / send |
| 10 | `docs.extrachill.com` | Documentation |
| 11 | `wire.extrachill.com` | News wire |
| 12 | `studio.extrachill.com` | Studio (internal team workspace) |

Use `ec_get_blog_id('<key>')` from `extrachill-multisite` to resolve
domain → blog_id at runtime. Cross-site queries go through
`ec_cross_site_rest_request()` (see plugin docs); the theme should not
hard-code switch_to_blog calls outside its own template helpers.

## File Layout

```
extrachill/
├── functions.php             # Bootstrap, direct require_once includes only
├── header.php / footer.php / sidebar.php / index.php
├── style.css                 # Main stylesheet, always loaded
├── assets/
│   ├── css/
│   │   ├── root.css          # GENERATED from @extrachill/tokens (`npm run build`)
│   │   ├── archive.css
│   │   ├── single-post.css
│   │   ├── search.css
│   │   ├── editor-style.css
│   │   ├── block-editor.css
│   │   ├── filter-bar.css
│   │   ├── shared-tabs.css
│   │   ├── sidebar.css
│   │   ├── taxonomy-badges.css
│   │   ├── network-dropdown.css
│   │   └── embed.css
│   ├── js/
│   │   ├── nav-menu.js
│   │   ├── shared-tabs.js
│   │   ├── mini-dropdown.js
│   │   └── share.js
│   └── fonts/
└── inc/
    ├── components/           # Reusable UI components (filter-bar)
    ├── core/                 # WordPress feature wiring
    │   ├── actions.php
    │   ├── assets.php
    │   ├── template-router.php
    │   ├── icons.php
    │   ├── notices.php
    │   └── templates/        # breadcrumbs, pagination, share, etc.
    ├── footer/ / header/ / home/ / sidebar/ / single/
```

`root.css` is a build artifact. **Never edit `root.css` directly** — change
the source in `@extrachill/tokens` and run `npm run build` here.

## Template Routing

`inc/core/template-router.php` hooks `template_include` and exposes these
override filters so plugins can replace the theme's templates:

| Filter | When |
|---|---|
| `extrachill_template_single_post` | `is_singular('post')` |
| `extrachill_template_page` | `is_page()` (only when no theme template) |
| `extrachill_template_archive` | `is_archive()` |
| `extrachill_template_search` | `is_search()` — overridden by `extrachill-search` for multisite results |
| `extrachill_template_404` | `is_404()` |
| `extrachill_template_fallback` | Last resort |

The homepage is the exception: it uses **action hooks** instead of a
filterable template so multiple plugins can inject content blocks.

## Hooks Surface

### Action hooks (plugins inject content here)

| Hook | Location |
|---|---|
| `extrachill_homepage_content` | Primary homepage body — plugins hook here |
| `extrachill_after_homepage_content` | Footer/CTA slot on homepage |
| `extrachill_before_body_content` | Top of every page, after `<body>` opens |
| `extrachill_after_body_content` | Bottom of every page, before footer |
| `extrachill_notices` | All notices render through this |
| `extrachill_sidebar_top` / `_middle` / `_bottom` | Sidebar slots |
| `extrachill_before_footer` / `extrachill_footer_content` / `extrachill_after_footer` | Footer slots |
| `extrachill_search_header` | Above search results — `extrachill-search` fires `archive-header.php` here |
| `extrachill_archive_above_posts` | Where the filter bar renders |
| `extrachill_avatar_menu` | Header avatar / login menu |
| `extrachill_archive_title` | Archive page H1 slot |

### Filter hooks (plugins modify behavior)

| Filter | Default behavior | Use to |
|---|---|---|
| `extrachill_sidebar_content` | Render `sidebar.php` | Replace sidebar markup wholesale |
| `extrachill_enable_sticky_header` | true | `__return_false` to disable sticky header |
| `extrachill_community_activity_items` | bbPress recent activity | Customize community sidebar widget |
| `extrachill_navigation_main_menu` | WP nav menu | Customize header nav |
| `extrachill_show_page_title` | true | Hide H1 on specific pages |
| `extrachill_archive_styles` | base archive.css | Add per-site archive CSS |

## Reusable Primitives

These classes live in `style.css` and are network-wide conventions —
use them in new React surfaces and admin pages instead of reinventing.

### `.ec-checkbox-row`

Canonical "checkbox + label" wrapper. Survives flex/grid layout
because the global `input[type="checkbox"]` `margin-right` is reset
inside the row and spacing is owned by `gap: var(--spacing-sm)`.

```html
<label class="ec-checkbox-row">
    <input type="checkbox" />
    <span>Identify speakers</span>
    <span class="ec-checkbox-row__hint">adds ~15 min</span>
</label>
```

Hint span is optional. The row is a flex container; the input is
`flex-shrink: 0` and aligned to the first line of the label content.

### `.notice notice-*`

Three-tier notice system:

```php
echo '<div class="notice notice-success">Success!</div>';
echo '<div class="notice notice-info">Note</div>';
echo '<div class="notice notice-error">Error</div>';
```

Render via the `extrachill_notices` action hook.

### Pagination, breadcrumbs, share, no-results

Helpers in `inc/core/templates/`:

```php
extrachill_pagination(['total' => $wp_query->max_num_pages, 'current' => get_query_var('paged') ?: 1]);
extrachill_breadcrumbs(['home' => 'Home', 'separator' => '/']);
extrachill_share_button(['share_url' => get_permalink(), 'share_title' => get_the_title()]);
```

### Shared tabs

```php
extrachill_enqueue_shared_tabs();
?>
<div class="shared-tabs">
    <button class="tab-button" data-tab="tab-1">Tab 1</button>
    <div id="tab-1" class="tab-panel">Content 1</div>
</div>
```

Uses `assets/css/shared-tabs.css` and `assets/js/shared-tabs.js`.

### Filter bar

`extrachill_filter_bar()` renders the universal archive/forum filter UI.
Items register via the `extrachill_filter_bar_items_*` filter family
(see `inc/components/filter-bar-defaults.php`). Plugins like
`extrachill-community` extend it with forum-specific filters.

## Design Tokens

`root.css` is generated from `@extrachill/tokens` — these are the
canonical CSS variables every consumer should reach for:

**Colors:** `--background-color`, `--text-color`, `--link-color`,
`--border-color`, `--accent` (green), `--accent-2` (slate), `--accent-3`
(cyan), `--error-color`, `--success-color`, `--muted-text`,
`--card-background`.

**Typography:** `--font-family-heading` (Loft Sans), `--font-family-body`
(Helvetica), `--font-family-brand` (Lobster), `--font-family-mono`. Sizes
`--font-size-xs / sm / base / body / lg / xl / 2xl / 3xl / brand`.

**Layout:** `--container-width` (1200px), `--content-width` (800px),
`--container-wide` (1600px), `--sidebar-width` (380px), `--form-width`
(500px). Spacing `--spacing-xs / sm / md / lg / xl` (4 / 8 / 16 / 24 / 32 px).

**Borders + focus:** `--border-radius-sm / md / lg / xl / pill / circle`,
`--focus-border-color`, `--focus-box-shadow`.

Dark mode is provided via `@media (prefers-color-scheme: dark)` overrides
in `root.css` itself. Consumer CSS should reference the variables, not
hard-coded colors.

**Never use `!important`** — fight specificity properly or hoist into
`root.css` as a token if it's actually a system concern.

## Asset Loading

Conditional enqueue in `inc/core/assets.php`. Every stylesheet declares
`['extrachill-root']` as a dependency so the variables resolve. Cache
busting uses `filemtime()`:

```php
wp_enqueue_style('extrachill-archive', $url, ['extrachill-root'], filemtime($path));
```

Load only when needed: `is_archive()`, `is_singular('post')`,
`is_search()`, `is_active_sidebar('primary')`, etc. Don't enqueue
everything on every page.

### Blocks Everywhere editor iframe

`blocks_everywhere_enqueue_iframe_assets` hook (in `inc/core/assets.php`)
loads `root.css` + `style.css` + `block-editor.css` into the BE/IBE
iframe so the editor visually matches the rendered front-end.

## Integration with Network Plugins

Always check `function_exists()` before calling sibling-plugin helpers.

### `extrachill-multisite`

| Function | Purpose |
|---|---|
| `ec_get_blog_id($key)` | Resolve site key → blog_id |
| `ec_get_site_url($key)` | Resolve site key → URL |
| `ec_cross_site_rest_request($key, $method, $path, $args)` | Universal cross-site REST. Accepts `/wp/v2/*` paths since v1.12.3 |

### `extrachill-users`

| Function | Purpose |
|---|---|
| `ec_get_user_profile_url($user_id)` | Community-first profile URL |
| `ec_get_user_author_archive_url($user_id)` | Article-byline-context URL |
| `ec_is_team_member($user_id)` | Studio access gate |
| `ec_has_main_site_account($user_id)` | Can the user author on blog_id=1? |

### `extrachill-search`

Overrides `extrachill_template_search` to render multisite results.
Theme's `archive-header.php` handles the `is_search()` case for the H1.

### `extrachill-newsletter`

Subscribe form available via the homepage action hook or direct
`extrachill_newsletter_subscribe_form()`.

## Build + Deploy

```bash
# Edit source files in inc/, assets/css/, assets/js/
npm run build                # Regenerates assets/css/root.css from @extrachill/tokens
homeboy release extrachill   # Conventional commits → auto-bump
homeboy deploy extrachill    # Build + ship to production
```

**Never edit `root.css` directly** — it's a build artifact.

**Never bump version strings manually** — homeboy owns version_targets in
`homeboy.json` and bumps from conventional commits.

**Plugin overrides land via filters**, not by editing theme templates.
If you need to change a template, file an issue or add a hook to the
theme so plugins can attach.

---

**Cross-reference**: For platform-wide patterns see the root `/AGENTS.md`
(auto-generated — don't edit). For plugin-specific integration patterns
see each plugin's own `AGENTS.md`.
