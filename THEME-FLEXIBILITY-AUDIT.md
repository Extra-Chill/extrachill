# Theme Flexibility Audit

This document tracks hardcoded values in Extra Chill theme that limit adaptability for other users. We're working through these gradually, converting hardcoded opinions to filter/action hooks.

**Strategy**: Theme provides hooks, plugins (primarily `extrachill-multisite`) provide Extra Chill-specific content.

---

## Checklist

### 1. Visual Identity

- [ ] **assets/css/root.css:7-11** - Brand colors
  - Current: `--accent: #53940b`, `--accent-2: #36454F`, `--accent-3: #00c8e3`
  - Impact: Non-Extra Chill sites stuck with lime green/charcoal colors
  - Solution: Add inline style override via `extrachill_custom_css_variables` filter or integrate with WordPress Customizer

- [ ] **assets/css/root.css:29-32** - User badge colors
  - Current: Hardcoded artist/team/professional badge colors
  - Impact: Non-Extra Chill sites can't customize badge colors
  - Solution: Filter `extrachill_user_badge_colors` returning `['artist' => '#hex', 'team' => '#hex', 'professional' => '#hex']`

- [ ] **assets/css/root.css:34-37** - Font families
  - Current: Hardcoded font-family declarations
  - Impact: Non-Extra Chill sites can't change fonts via CSS variables
  - Solution: Add CSS custom properties for font families with filter override

---

### 2. Third-Party Scripts

- [ ] **header.php:17** - Mediavine DNS prefetch
  - Current: `<link rel="dns-prefetch" href="//scripts.mediavine.com">`
  - Impact: Extra Chill-specific analytics injected into theme
  - Solution: Move to `extrachill-multisite` plugin via `extrachill_head_scripts` action, or filter `extrachill_dns_prefetch_domains`

---

---

## Priority Order

1. **LOW** - Move Mediavine to plugin (header.php)
2. **LOW** - CSS variable overrides (root.css)

---

## Completed Items

The following items have been **completed** or **no longer exist**:
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

## Notes

- Theme should work standalone (single-site, no Extra Chill plugins)
- When plugins aren't active, theme degrades gracefully with sensible defaults
- Extra Chill-specific opinions live in `extrachill-multisite` plugin
- **Plugin Rename Plan**: `extrachill-multisite` will be renamed to `extrachill-network` post-migration to serve as main home for network-wide theming and UI hooks
- All hooks use `extrachill_` prefix for consistency
- Provide sensible defaults when filters aren't hooked
- Test in both multisite (Extra Chill Platform) and single-site contexts
