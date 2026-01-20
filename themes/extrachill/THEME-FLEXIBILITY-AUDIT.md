# Theme Flexibility Audit

This document tracks hardcoded values in Extra Chill theme that limit adaptability for other users. We're working through these gradually, converting hardcoded opinions to filter/action hooks.

**Strategy**: Theme provides hooks, plugins (primarily `extrachill-multisite`) provide Extra Chill-specific content.

---

## Checklist

### 1. Custom Taxonomies (DEFERRED)

- [x] **inc/core/custom-taxonomies.php** - Music-specific taxonomies (KEEPING IN THEME)
  - Current: Theme registers `artist`, `venue`, `festival`, `location` taxonomies
  - Decision: Keep in theme. The theme is opinionated about being for music sites.
  - Rationale: Anyone making "their own Extra Chill" would likely want these taxonomies
  - Status: DEFERRED - can revisit later if needed

---

## Completed Items

The following items have been **completed** or **no longer exist**:

### Phase 2 (2.0.0)

- ✅ **Taxonomy Badge Colors** (assets/css/taxonomy-badges.css)
  - Music-specific colors (festivals, locations, venues, artists) moved to `extrachill-multisite/assets/css/taxonomy-badges.css`
  - Theme retains only generic `.taxonomy-badge` base styling

- ✅ **User Badge CSS** (style.css, assets/css/root.css)
  - Badge styling (`.user-is-artist`, `.extrachill-team-member`, `.user-is-professional`) moved to `extrachill-users/assets/css/user-badges.css`
  - Badge color variables moved to plugin CSS

- ✅ **Filter Bar Music Logic** (inc/components/filter-bar-defaults.php)
  - Hardcoded category checks removed (`song-meanings`, `music-history`)
  - Added `extrachill_filter_bar_category_items` filter
  - EC-specific artist dropdown provided by `extrachill-multisite/inc/theme/filter-bar.php`

- ✅ **Related Posts Defaults** (inc/single/related-posts.php)
  - Default changed from `array( 'artist', 'venue' )` to `array( 'category', 'post_tag' )`
  - Filter `extrachill_related_posts_allowed_taxonomies` exists for plugins to override

- ✅ **Footer Link Defaults** (inc/footer/footer-bottom-menu.php)
  - Default changed to empty array
  - EC-specific links provided by `extrachill-multisite/inc/theme/footer-links.php`

- ✅ **Community Activity Widget** (inc/sidebar/community-activity.php)
  - Improved graceful degradation - returns early if EC functions don't exist
  - Widget renders nothing without EC plugins (no broken markup)

- ✅ **festival_wire Post Type Handling**
  - Removed from theme taxonomy registration (plugin handles via `register_taxonomy_for_object_type()`)
  - Asset loading made filterable via `extrachill_single_post_style_post_types` and `extrachill_sidebar_style_post_types`
  - Sidebar recent posts made filterable via `extrachill_sidebar_recent_posts_content`
  - All EC-specific logic moved to `extrachill-news-wire/includes/theme-integration.php`

### Phase 1 (2.0.0)

- ✅ **Brand colors** (root.css:7-11) - Now overridable via `extrachill_css_variables` filter
- ✅ **User badge colors** (root.css:29-32) - Now overridable via `extrachill_css_variables` filter
- ✅ **Font families** (root.css:34-37) - Now overridable via `extrachill_css_variables` filter
- ✅ **Mediavine DNS prefetch** (header.php) - Moved to `extrachill_dns_prefetch_domains` filter, Mediavine added via extrachill-multisite plugin

### Prior Completions

- ✅ Blog ID 1 admin menu check (moved to extrachill-multisite plugin)
- ✅ 404 error messaging (moved to extrachill-multisite plugin via filters)
- ✅ Fallback error heading (moved to extrachill-multisite plugin via filter)
- ✅ 404 content links (moved to extrachill-multisite plugin via action)
- ✅ 404 error page CSS (moved to extrachill-multisite plugin via assets.php + 404.css, search form CSS restored to theme)
- ✅ Online users stats widget (moved to extrachill-users plugin)
- ✅ Font preloads (moved to extrachill-multisite plugin via filter)
- ✅ Social links (made filterable via filter)
- ✅ Fallback contact button (moved to extrachill-multisite plugin via action)
- ✅ Forum ID 1494 exclusion (removed from codebase)
- ✅ Category ID 714 filter (removed from codebase)
- ✅ Site title filter (already implemented in inc/core/site-title.php)
- ✅ Breadcrumbs filters (already implemented)
- ✅ Footer bottom menu filter (already implemented)
- ✅ Community activity null check (properly implemented)

---

## Keeping in Theme (Confirmed OK)

The following were reviewed and confirmed appropriate for a generic theme:

- ✅ **Custom Taxonomies** (inc/core/custom-taxonomies.php) - Theme is music-oriented, taxonomies are appropriate
- ✅ **Instagram embed handler** (inc/core/editor/instagram-embeds.php) - Generic social media, widely used by bloggers
- ✅ **Bandcamp embed handler** (inc/core/editor/bandcamp-embeds.php) - Music-related but provides value for music bloggers using any theme

---

## Notes

- Theme should work standalone (single-site, no Extra Chill plugins)
- When plugins aren't active, theme degrades gracefully with sensible defaults
- Extra Chill-specific opinions live in `extrachill-multisite` plugin
- **Plugin Rename Plan**: `extrachill-multisite` will be renamed to `extrachill-network` post-migration to serve as main home for network-wide theming and UI hooks
- All hooks use `extrachill_` prefix for consistency
- Provide sensible defaults when filters aren't hooked
- Test in both multisite (Extra Chill Platform) and single-site contexts
