# AI Assistant Instructions for ExtraChill Theme

Purpose: Enable an AI agent to make high‑quality, production‑safe contributions to the ExtraChill WordPress theme immediately.

## 1. Big Picture
- This repo is a custom WordPress theme powering blog + community (bbPress) + Festival Wire homepage integration. Newsletter, contact forms, community, and e-commerce functionality moved to dedicated plugins (ExtraChill Newsletter, ExtraChill Contact, ExtraChill Community, ExtraChill Shop, ExtraChill Multisite). 100% PHP/CSS/JS with NO build toolchain (just a packaging script).
- Architecture is modular: feature logic lives under `inc/` in subfolders (core, archives, footer, header, home, sidebar, single). `functions.php` wires everything and controls conditional asset loading.
- Performance + reduced bloat + conditional loading are guiding principles (minimal image sizes, selective style/script enqueueing, dynamic versioning via `filemtime()`).

## 2. Core Conventions
- Add new feature modules in an appropriate `inc/<area>/` file; include them from `functions.php` (or an existing aggregator file) only if needed globally. Consider future conditional requires.
- Always enqueue assets with dynamic versioning: `filemtime( $path )` and narrow page conditions (e.g. `is_front_page()`, `is_singular('post')`).
- Root CSS variables must load first (`assets/css/root.css`) → other styles depend on handle `extrachill-root`. If adding a stylesheet, declare dependency on that handle.
- Page/context-specific CSS: archive → `assets/css/archive.css`, single post → `assets/css/single-post.css`, home → `assets/css/home.css`, navigation → `assets/css/nav.css`.
- Do NOT resurrect removed CSS concatenation/inline “optimization” (comment notes memory issues). Keep individual files.
- Use local fonts in `/fonts/`; don't introduce external font CDNs.
- Image sizes: custom policy removes many defaults; avoid relying on `thumbnail` or WooCommerce sizes—check existing sizes before using.

## 3. Custom Data Structures & Content Types
- All custom taxonomies (festival, artist, venue, location) registered in `inc/core/custom-taxonomies.php`.
- Custom Post Types: Newsletter functionality completely handled by ExtraChill Newsletter Plugin (no theme templates). Festival Wire custom post type handled by ExtraChill News Wire plugin. Follow existing arg patterns (REST enabled, non-hierarchical unless needed, slug = lowercase snake/hyphen form).
- When adding CPT/taxonomies: after deploy, requires `wp rewrite flush` (document in PR description—don’t add automatic flush in code).

## 4. Asset & Script Patterns
- JS enqueue examples: archive pages (`chill-custom.js`), single post (`community-comments.js`). Copy these patterns for new scoped scripts with localized AJAX objects.
- Lightbox loading: Conditional DOM inspection pattern in `enqueue_custom_lightbox_script()`—mirror that approach for other content-driven enhancements.
- Admin/editor styling: Editor styles added via `add_editor_style()` and admin enqueue (`extrachill_enqueue_admin_styles`). New editor styling should extend `editor-style.css` not replace it.

## 5. E-commerce Integration (Plugin-Based)
- WooCommerce functionality moved to ExtraChill Shop plugin. Theme no longer includes WooCommerce-specific templates or functionality. Any e-commerce features should be implemented in the dedicated plugin.

## 6. Community Integration
- Community functionality moved to ExtraChill Community plugin and ExtraChill Multisite plugin. Theme includes function existence checks for multisite plugin functions with graceful fallback when unavailable.

## 7. Security & Output
- Maintain consistent escaping: `esc_html()`, `esc_attr()`, `esc_url()`, and nonce/capability checks for AJAX. Follow existing external link modification filter when adjusting link behaviors.
- External links auto-get `target="_blank" rel="noopener noreferrer"`; do not duplicate that logic elsewhere.

## 8. Performance Practices
- Preserve conditional body classes & style dequeues (emoji removal, admin style suppression). If adding new heavy assets, gate them by context early in `wp_enqueue_scripts` with low priority if foundational, higher if dependent.
- Memory debugging markers exist (string expressions) but aren’t logged; if expanding diagnostics, implement a lightweight guarded logger instead of unconditional heavy computations.

## 9. Build & Tooling
- Packaging: run build task (`./build.sh` or VS Code task “Build Theme”) → creates `dist/extrachill.zip` excluding patterns in `.buildignore`; validates required files; reinstalls dev Composer deps afterward.
- Composer: Prod requires only `composer/installers`; dev adds PHPCS + WPCS. Run `composer test` / `composer run lint:php` before committing PHP changes.
- No JS bundler—submit plain ES5/ESNext compatible with target browsers (WordPress standards). Avoid adding a bundler unless explicitly approved.

## 10. Adding Features (Example Flow)
1. Create `inc/<domain>/<feature>.php` (e.g. `inc/home/templates/new-homepage-section.php`).
2. Wire require in `functions.php` (no auto-include functionality exists - manual requires needed).
3. Enqueue any CSS/JS conditionally with `filemtime()` versioning.
4. Add filters/actions exposing extension points (prefix with `extrachill_`).
5. Update README or this instructions file only if pattern divergent.

## 11. Patterns to Copy
- Conditional CSS override: see existing CSS enqueuing patterns with page context conditions.
- REST field extension: co-authors registration block (guards existence, registers via `register_rest_field`).
- Navigation enhancement: custom walker `Custom_Walker_Nav_Menu` adding SVG indicators.

## 12. What NOT To Do
- Don’t introduce global asset bundles or inline concatenation for CSS/JS.
- Don’t flush rewrite rules automatically in code.
- Don’t assume WooCommerce/bbPress active—always guard.
- Don’t add external font/CDN dependencies.
- Don’t bypass existing escaping or link handling filters.

## 13. PR/Change Notes Suggestion
Include: feature summary, conditional loading review, taxonomy/CPT impacts (note rewrite flush), performance considerations, and any new hooks introduced.

---
Clarify needs? Ask which modules or workflows require deeper expansion (e.g., community integration, plugin integrations).
