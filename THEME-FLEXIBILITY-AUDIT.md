# Theme Flexibility Audit

This document tracks hardcoded values in Extra Chill theme that limit adaptability for other users. We're working through these gradually, converting hardcoded opinions to filter/action hooks.

**Strategy**: Theme provides hooks, plugins (primarily `extrachill-multisite`) provide Extra Chill-specific content.

---

## Checklist

### 0. CRITICAL BUGS

- [x] **extrachill-plugins/network/extrachill-multisite/inc/core/blog-ids.php:80** - Function redefinition
  - Current: `ec_get_blog_id()` declared without `function_exists()` guard
  - Impact: Fatal error when test bootstrap loads first
  - Solution: Wrap function declaration with `function_exists()` check ✅ **FIXED**

---

### 1. Hardcoded IDs

- [ ] **functions.php:91** - Blog ID 1 admin menu check
  - Current: `if (get_current_blog_id() !== 1)`
  - Impact: Only main site gets Posts menu in admin
  - Solution: Filter `extrachill_admin_show_posts_menu` returning boolean, or use `is_main_site()`

---

### 2. Visual Identity

- [ ] **header.php:14-15** - Hardcoded font preloads
  - Current: Preloads WilcoLoftSans-Treble.woff2 and Lobster2.woff2 directly
  - Impact: Non-Extra Chill sites can't use different fonts
  - Solution: Filter `extrachill_preload_fonts` returning array of `['url' => string, 'as' => string, 'type' => string]`

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

### 3. Third-Party Scripts

- [ ] **header.php:17** - Mediavine DNS prefetch
  - Current: `<link rel="dns-prefetch" href="//scripts.mediavine.com">`
  - Impact: Extra Chill-specific analytics injected into theme
  - Solution: Move to `extrachill-multisite` plugin via `extrachill_head_scripts` action, or filter `extrachill_dns_prefetch_domains`

---

### 4. Hardcoded URLs

- [ ] **inc/core/templates/social-links.php:16-46** - Social media URLs
  - Current: Array of hardcoded URLs (facebook.com/extrachill, twitter.com/extra_chill, etc.)
  - Impact: Non-Extra Chill sites show wrong social links
  - Solution: Filter `extrachill_social_links` returning array of `['url' => string, 'icon' => string, 'label' => string]`

- [ ] **inc/core/templates/404.php:25** - Contact page URL
  - Current: `$main_site_url . '/contact/'`
  - Impact: Non-Extra Chill sites link to wrong contact page
  - Solution: Filter `extrachill_404_contact_url`

- [ ] **inc/core/templates/404.php:28,29** - Documentation and forum URLs
  - Current: Hardcoded docs and tech support forum references
  - Impact: Non-Extra Chill sites link to Extra Chill resources
  - Solution: Filters `extrachill_404_docs_url`, `extrachill_404_forum_url`

- [ ] **index.php:26** - Contact Us button URL
  - Current: `home_url('/contact-us/')`
  - Impact: Non-Extra Chill sites link to wrong contact page
  - Solution: Filter `extrachill_fallback_contact_url`

---

### 5. UI Text / Labels

- [ ] **inc/core/templates/404.php:21** - 404 error heading
  - Current: `"Well, that's not very chill of us."`
  - Impact: Extra Chill-specific messaging for non-Extra Chill sites
  - Solution: Filter `extrachill_404_heading` (already uses `_e()` for i18n)

- [ ] **inc/core/templates/404.php:23** - 404 subtext
  - Current: `"We can't find what you're looking for..."`
  - Impact: Generic message but Extra Chill branding
  - Solution: Filter `extrachill_404_message`

- [ ] **index.php:21** - Fallback error heading
  - Current: `"Yeah, something is royally f*cked."`
  - Impact: Extra Chill-specific messaging with profanity
  - Solution: Filter `extrachill_fallback_error_heading`

- [ ] **inc/footer/online-users-stats.php:44** - "Online Now" label
  - Current: Hardcoded string in widget
  - Impact: Non-Extra Chill sites can't customize label
  - Solution: Filter `extrachill_online_stats_online_label`

- [ ] **inc/footer/online-users-stats.php:51** - "Total Members" label
  - Current: Hardcoded string in widget
  - Impact: Non-Extra Chill sites can't customize label
  - Solution: Filter `extrachill_online_stats_members_label`

---

## Priority Order

1. **CRITICAL** - Fix function redefinition bug (blog-ids.php)
2. **HIGH** - Remove hardcoded blog ID checks (functions.php)
3. **HIGH** - Filterable font preloads (header.php)
4. **HIGH** - Filterable social links (social-links.php)
5. **MEDIUM** - Filterable URLs (404, contact pages)
6. **MEDIUM** - Filterable UI labels (notices, stats, 404)
7. **LOW** - Move Mediavine to plugin (header.php)
8. **LOW** - CSS variable overrides (root.css)

---

## Completed Items

The following items have been **completed** or **no longer exist**:
- ✅ Function redefinition bug (blog-ids.php) - FIXED
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
- All hooks use `extrachill_` prefix for consistency
- Provide sensible defaults when filters aren't hooked
- Test in both multisite (Extra Chill Platform) and single-site contexts
